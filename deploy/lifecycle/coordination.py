"""D5A local writer barrier. SQLite locking is shared with PHP PDO on both OSes.
Protected identity/phase files are administrator-owned; writable coordination is separate.
No installation is inferred or initialized merely by importing this module.
"""
from contextlib import contextmanager
import json
import os
from pathlib import Path
import sqlite3
import time
import uuid

RUNNING='running'
PHASES={'running','quiescing','fenced','quiescent','quiescent_pending','resuming','failed'}


class Paused(RuntimeError):pass


def root():
    value=os.environ.get('PHILCST_LIFECYCLE_ROOT')
    if not value:
        app=Path(__file__).resolve().parents[2]
        # Standalone installed tools must not bypass maintenance by omitting service env.
        # A development checkout has no parent BUILD-INFO and keeps its existing behavior.
        if (app.parent/'BUILD-INFO.json').is_file():
            if not (app/'.env').is_file():raise Paused('Installed lifecycle environment is missing')
            values=[line.split('=',1)[1].strip().strip('"\'') for line in (app/'.env').read_text().splitlines() if line.startswith('PHILCST_LIFECYCLE_ROOT=')]
            if len(values)!=1 or not values[0]:raise Paused('Installed D5A identity is not initialized; capture refused')
            value=values[0]
    return Path(value) if value else None


def read(base,name):
    value=json.loads((Path(base)/name).read_text())
    if type(value) is not dict or type(value.get('schema')) is not int or value['schema']!=1:raise Paused('Invalid lifecycle metadata; administrator recovery required')
    return value


def identity(base):
    value=read(base,'identity.json')
    for name in ('installation','generation'):uuid.UUID(value[name])
    return value


def phase(base):
    value=read(base,'maintenance.json')
    if value.get('phase') not in PHASES:raise Paused('Unknown maintenance phase')
    return value


def atomic(path,value):
    path=Path(path);temporary=path.with_name('.'+path.name+'.'+uuid.uuid4().hex)
    try:
        with temporary.open('x',encoding='utf-8') as f:
            json.dump(value,f,sort_keys=True);f.flush();os.fsync(f.fileno())
        temporary.chmod(0o640)
        if os.name=='posix':os.chown(temporary,-1,path.stat().st_gid if path.exists() else path.parent.stat().st_gid)
        os.replace(temporary,path)
        if os.name=='posix':
            fd=os.open(path.parent,os.O_RDONLY)
            try:os.fsync(fd)
            finally:os.close(fd)
    finally:temporary.unlink(missing_ok=True)


def initialize(base):
    """Explicit privileged fresh-only setup, never a missing-state repair."""
    base=Path(base)
    if base.exists():raise ValueError('Lifecycle state already exists; do not regenerate identity or erase a fence')
    base.mkdir(parents=True,mode=0o750)
    (base/'coordination').mkdir(mode=0o770);(base/'delivery').mkdir(mode=0o770)
    info={'schema':1,'installation':str(uuid.uuid4()),'generation':str(uuid.uuid4())}
    atomic(base/'identity.json',info)
    db=sqlite3.connect(base/'coordination/barrier.sqlite');db.execute('CREATE TABLE barrier (id INTEGER PRIMARY KEY CHECK(id=1))');db.execute('INSERT INTO barrier VALUES (1)')
    db.execute('CREATE TABLE producer_jobs(token TEXT PRIMARY KEY,pid INTEGER NOT NULL,purpose TEXT NOT NULL,started REAL NOT NULL)');db.commit();db.close()
    (base/'coordination/barrier.sqlite').chmod(0o660)
    atomic(base/'maintenance.json',{'schema':1,'phase':RUNNING,'operation':None,'entered_at':None})
    return info


@contextmanager
def barrier(base=None,kind='operational',timeout=30):
    base=Path(base) if base else root()
    if base is None:yield;return
    # mode=rw prevents a missing barrier from silently creating an unlocked replacement.
    connection=sqlite3.connect((base/'coordination/barrier.sqlite').resolve().as_uri()+'?mode=rw',uri=True,timeout=timeout)
    try:
        connection.execute('BEGIN IMMEDIATE')
        current=phase(base);identity(base)
        allowed=current['phase']==RUNNING or kind=='delivery' and current['phase']=='quiescing' or kind=='control'
        if not allowed:raise Paused('Maintenance active: '+current['phase'])
        yield connection
        connection.commit()
    finally:connection.close()


def admission(base=None):
    base=base or root()
    return base is None or phase(base)['phase']==RUNNING


def wait_for_resume():
    while not admission():time.sleep(1)


def jobs(base):
    db=sqlite3.connect((Path(base)/'coordination/barrier.sqlite').resolve().as_uri()+'?mode=rw',uri=True)
    try:
        db.row_factory=sqlite3.Row
        return [dict(row) for row in db.execute('SELECT * FROM producer_jobs ORDER BY started')]
    finally:db.close()


@contextmanager
def producer_work(purpose):
    base=root()
    if base is None:yield;return
    token=uuid.uuid4().hex
    # Admission and registration are atomic against enter. Newly starting work is refused.
    with barrier(base) as db:db.execute('INSERT INTO producer_jobs VALUES(?,?,?,?)',(token,os.getpid(),purpose,time.time()))
    try:yield
    finally:
        with barrier(base,'delivery') as db:db.execute('DELETE FROM producer_jobs WHERE token=?',(token,))
