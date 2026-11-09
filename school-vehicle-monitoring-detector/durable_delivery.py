"""Durable immutable detector/RFID submissions using bundled sqlite3 + requests.
Payload and losslessly encoded evidence commit together before transport. No API key in spool.
This protects persisted work; it cannot reconstruct uncaptured physical movements.
"""
import base64
from contextlib import contextmanager
from datetime import datetime,timezone
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import sys
import threading
import time
import uuid

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'deploy/lifecycle'))
from coordination import barrier,identity,phase,root,Paused,admission,wait_for_resume,producer_work


class CapacityError(RuntimeError):pass


def canonical(value):return json.dumps(value,ensure_ascii=True,sort_keys=True,separators=(',',':'),allow_nan=False)
def digest(value):return hashlib.sha256(value).hexdigest()
def event_time(payload):return payload.get('event_time') or payload.get('scan_time')


class Outbox:
    def __init__(self,base,max_bytes=512*1024*1024,max_operations=5000,min_free=256*1024*1024,initialize=False):
        self.base=Path(base);self.path=self.base/'delivery/outbox.sqlite';self.ident=identity(base)
        self.max_bytes=max_bytes;self.max_operations=max_operations;self.min_free=min_free
        self.refused_bytes=0
        self.initializing=initialize
        if not self.path.is_file() and not initialize:raise Paused('Delivery spool is missing; explicit recovery required, never recreate it silently')
        with self.db() as db:
            if initialize:db.executescript('''CREATE TABLE IF NOT EXISTS metadata (identity TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS operations (
 id TEXT PRIMARY KEY, occurrence TEXT NOT NULL, lane TEXT NOT NULL, kind TEXT NOT NULL, envelope TEXT NOT NULL,
 sha TEXT NOT NULL, media TEXT, dependency TEXT, state TEXT NOT NULL DEFAULT 'pending',
 created REAL NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, total_attempts INTEGER NOT NULL DEFAULT 0,
 reviews INTEGER NOT NULL DEFAULT 0, reviewed_at REAL, last_attempt REAL, next REAL NOT NULL DEFAULT 0,
 lease REAL NOT NULL DEFAULT 0, token TEXT, error TEXT, result TEXT, bytes INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS delivery_ready ON operations(state,next,created);''')
            stored=db.execute('SELECT identity FROM metadata').fetchone()
            marker=canonical(self.ident)
            if stored and stored[0]!=marker:raise Paused('Outbox belongs to a different installation/generation; replay refused')
            if not stored:
                if not initialize:raise Paused('Missing delivery identity; no empty spool replacement permitted')
                db.execute('INSERT INTO metadata VALUES (?)',(marker,))
        if initialize:self.path.chmod(0o660)
        self.initializing=False

    @contextmanager
    def db(self):
        db=sqlite3.connect(self.path if self.initializing else self.path.resolve().as_uri()+'?mode=rw',uri=not self.initializing,timeout=15);db.row_factory=sqlite3.Row
        db.execute('PRAGMA synchronous=FULL')
        if self.initializing:db.execute('PRAGMA journal_mode=DELETE')
        # Bound allocated pages too; active work is never evicted to fit the limit.
        db.execute('PRAGMA max_page_count='+str((self.max_bytes+32*1024*1024)//4096))
        try:
            if not self.initializing:
                stored=db.execute('SELECT identity FROM metadata').fetchone()
                if not stored or stored[0]!=canonical(self.ident):raise Paused('Spool database generation changed; no replay or replacement permitted')
            yield db;db.commit()
        except sqlite3.Error as error:
            db.rollback()
            if getattr(error,'sqlite_errorcode',None)==sqlite3.SQLITE_FULL:raise CapacityError('Delivery database capacity exhausted; retain pending work and pause capture') from None
            raise Paused('Delivery storage unavailable or inconsistent; retain pending work and inspect protected status') from None
        except BaseException:db.rollback();raise
        finally:db.close()

    def enqueue(self,kind,payload,media=None,operation=None):
        if identity(self.base)!=self.ident:raise Paused('Installation generation changed; capture paused')
        payload=json.loads(canonical(payload));media=media or {}
        if not event_time(payload):raise ValueError('Original occurrence/scan time is required')
        # A retried capture with the same original scan timestamp is the same work.
        # Separate reader observations retain their distinct capture timestamps.
        occurrence=str(payload.get('external_event_key') or operation or (kind+'-'+digest(canonical(payload).encode())))
        lane=str(payload.get('camera_role') or payload.get('scan_location') or 'device-discovery')
        packed={name:{'filename':name+'.jpg','sha256':digest(data),'bytes':len(data)} for name,data in media.items()}
        # Content identity distinguishes revisions and kinds; a retry never regenerates it.
        content={'schema':1,**self.ident,'kind':kind,'occurrence':occurrence,'payload':payload,'media':packed}
        operation=operation or digest(canonical(content).encode())
        content['operation']=operation;encoded=canonical(content);sha=digest(encoded.encode())
        encoded_media=canonical({name:base64.b64encode(data).decode() for name,data in media.items()})
        size=len(encoded.encode())+len(encoded_media)
        with barrier(self.base,'delivery'):
            state=phase(self.base)
            if state['phase']=='quiescing' and datetime.fromisoformat(event_time(payload).replace('Z','+00:00')).timestamp()>state['entered_at']:
                raise Paused('Capture occurred after maintenance admission closed')
            with self.db() as db:
                db.execute('BEGIN IMMEDIATE')
                db.execute("""DELETE FROM operations WHERE state='acked' AND id NOT IN
 (SELECT id FROM operations WHERE state='acked' ORDER BY rowid DESC LIMIT 1000)
 AND id NOT IN (SELECT dependency FROM operations WHERE dependency IS NOT NULL AND state!='acked')""")
                old=db.execute('SELECT sha FROM operations WHERE id=?',(operation,)).fetchone()
                if old:
                    if old['sha']!=sha:raise ValueError('Operation identity reused with different immutable content')
                    return operation
                count,total=db.execute("SELECT COUNT(*),COALESCE(SUM(bytes),0) FROM operations WHERE state!='acked'").fetchone()
                if count>=self.max_operations or total+size>self.max_bytes or shutil.disk_usage(self.base).free-size<self.min_free:
                    # No pending eviction; producer receives an explicit capture refusal.
                    self.refused_bytes=size
                    raise CapacityError('Delivery capacity exhausted; capture must pause, pending evidence retained')
                prior=db.execute('SELECT id FROM operations WHERE occurrence=? OR lane=? ORDER BY rowid DESC LIMIT 1',(occurrence,lane)).fetchone()
                db.execute('INSERT INTO operations(id,occurrence,lane,kind,envelope,sha,media,dependency,created,bytes) VALUES(?,?,?,?,?,?,?,?,?,?)',
                    (operation,occurrence,lane,kind,encoded,sha,encoded_media,prior['id'] if prior else None,time.time(),size))
                self.refused_bytes=0
        return operation

    def claim(self,now=None):
        now=time.time() if now is None else now
        if identity(self.base)!=self.ident:raise Paused('Installation generation changed; delivery stopped')
        if phase(self.base)['phase'] not in ('running','quiescing'):return None
        with barrier(self.base,'delivery'), self.db() as db:
            db.execute('BEGIN IMMEDIATE')
            db.execute("UPDATE operations SET state='pending',error='Previous worker lease expired; original operation retained' WHERE state='inflight' AND lease<?",(now,))
            row=db.execute("""SELECT * FROM operations o WHERE state='pending' AND next<=?
 AND (dependency IS NULL OR EXISTS(SELECT 1 FROM operations p WHERE p.id=o.dependency AND p.state='acked')) ORDER BY rowid LIMIT 1""",(now,)).fetchone()
            if not row:return None
            token=uuid.uuid4().hex
            db.execute("UPDATE operations SET state='inflight',attempts=attempts+1,total_attempts=total_attempts+1,last_attempt=?,lease=?,token=? WHERE id=?",(now,now+30,token,row['id']))
            return dict(row)|{'attempts':row['attempts']+1,'token':token}

    def deliver_one(self,url,key,transport=None,now=None):
        if transport is None:
            import requests
            transport=requests.post
        row=self.claim(now)
        if not row:return None
        now=time.time() if now is None else now;status=0;ack=None;retry_after=0
        try:
            envelope=json.loads(row['envelope']);media=json.loads(row['media'] or '{}')
            if digest(row['envelope'].encode())!=row['sha']:raise ValueError('Corrupt immutable envelope')
            for name,info in envelope['media'].items():
                raw=base64.b64decode(media[name],validate=True)
                if len(raw)!=info['bytes'] or digest(raw)!=info['sha256']:raise ValueError('Corrupt retained evidence')
            response=transport(url,json={'envelope':row['envelope'],'sha256':row['sha'],'media':media},
                headers={'Accept':'application/json','X-Api-Key':key},timeout=(3,12))
            status=response.status_code
            try:body=response.json()
            except ValueError:body={}
            receipt=body.get('delivery_ack',{})
            if status in (200,201) and receipt.get('committed') is True and type(receipt.get('schema')) is int and receipt=={'schema':1,'operation':row['id'],'sha256':row['sha'],
                    'installation':self.ident['installation'],'generation':self.ident['generation'],'committed':True}:
                ack=body
            else:
                if status==429:
                    try:retry_after=min(300,max(1,int(response.headers.get('Retry-After','0'))))
                    except ValueError:
                        from email.utils import parsedate_to_datetime
                        try:retry_after=min(300,max(1,parsedate_to_datetime(response.headers['Retry-After']).timestamp()-now))
                        except Exception:retry_after=30
                error=('HTTP '+str(status)+' without a matching committed receipt')
        except (ValueError,KeyError,TypeError):
            status=422;error='Retained payload/evidence is corrupt; administrator review required'
        except Exception:
            # Never include request URLs, API credentials or raw payloads in diagnostics.
            error='Transport unavailable or acknowledgement lost'
        with barrier(self.base,'delivery'), self.db() as db:
            if ack:
                # Retained acknowledgements are diagnostics, not a second event archive.
                # Full canonical details and receipts remain in the application database.
                if len(canonical(ack).encode())>32768:
                    ack={k:ack[k] for k in ('delivery_ack','created','duplicate','ack_effect') if k in ack}
                    ack['result']={'message':'Committed; full details remain in the application'}
                db.execute("UPDATE operations SET state='acked',media=NULL,envelope='',bytes=0,error=NULL,result=? WHERE id=? AND token=?",(canonical(ack),row['id'],row['token']))
            else:
                permanent=400<=status<500 and status not in (408,429) or row['attempts']>=20
                delay=max(retry_after,min(300,2**min(row['attempts'],8)))
                db.execute('UPDATE operations SET state=?,error=?,next=?,lease=0 WHERE id=? AND token=?',('dead' if permanent else 'pending',error,now+delay,row['id'],row['token']))
        return self.result(row['id'])

    def result(self,operation):
        with self.db() as db:row=db.execute('SELECT * FROM operations WHERE id=?',(operation,)).fetchone()
        body=json.loads(row['result']) if row['result'] else {}
        return {'accepted':row['state']=='acked','queued':row['state'] in ('pending','inflight'),
            'durably_queued':True,'operation':operation,'created':body.get('created',False),
            'duplicate':body.get('duplicate',False),
            'body':body.get('result',{}),'message':row['error'] or ('Delivery committed' if row['state']=='acked' else 'Persisted for delivery')}

    def status(self):
        if identity(self.base)!=self.ident:raise Paused('Installation generation changed; capture and delivery paused')
        with self.db() as db:
            counts=dict(db.execute('SELECT state,COUNT(*) FROM operations GROUP BY state').fetchall())
            oldest=db.execute("SELECT MIN(created),COALESCE(SUM(bytes),0) FROM operations WHERE state!='acked'").fetchone()
            error=db.execute('SELECT error FROM operations WHERE error IS NOT NULL ORDER BY rowid DESC LIMIT 1').fetchone()
            blocked=db.execute("SELECT COUNT(*) FROM operations o JOIN operations p ON o.dependency=p.id WHERE o.state='pending' AND p.state='dead'").fetchone()[0]
            retries=db.execute('SELECT COALESCE(SUM(MAX(total_attempts-1,0)),0) FROM operations').fetchone()[0]
            pages=db.execute('PRAGMA page_count').fetchone()[0];free_pages=db.execute('PRAGMA freelist_count').fetchone()[0];page_size=db.execute('PRAGMA page_size').fetchone()[0]
            failures=[dict(row) for row in db.execute("SELECT id,kind,occurrence,attempts,total_attempts,reviews,last_attempt,error,dependency FROM operations WHERE state='dead' ORDER BY rowid DESC LIMIT 20")]
        return {'counts':counts,'oldest_age_seconds':max(0,time.time()-oldest[0]) if oldest[0] else 0,'retained_bytes':oldest[1],
            'blocked_dependencies':blocked,'retries':retries,'last_error':error[0] if error else None,'free_bytes':shutil.disk_usage(self.base).free,
            'allocated_bytes':pages*page_size,'used_bytes':(pages-free_pages)*page_size,
            'failures':failures,
            'delivery_ready':phase(self.base)['phase'] in ('running','quiescing') and error is None,
            'capacity_operations':self.max_operations,'capacity_bytes':self.max_bytes,
            'ready':(pages-free_pages)*page_size<self.max_bytes+32*1024*1024 and sum(v for k,v in counts.items() if k!='acked')<self.max_operations and oldest[1]+max(1,self.refused_bytes)<=self.max_bytes and shutil.disk_usage(self.base).free-self.refused_bytes>self.min_free}

    def requeue(self,operation):
        with self.db() as db:
            row=db.execute('SELECT state FROM operations WHERE id=?',(operation,)).fetchone()
            if not row or row['state']!='dead':raise ValueError('Review an existing dead operation; identity/content cannot be changed')
            db.execute("UPDATE operations SET state='pending',attempts=0,reviews=reviews+1,reviewed_at=?,next=0,error='Explicit reviewed retry of original operation' WHERE id=?",(time.time(),operation))

    def inspect(self,operation):
        with self.db() as db:row=db.execute('SELECT * FROM operations WHERE id=?',(operation,)).fetchone()
        if not row:raise ValueError('Unknown retained operation')
        envelope=json.loads(row['envelope']) if row['envelope'] else {}
        payload=envelope.get('payload',{})
        return {'operation':row['id'],'state':row['state'],'kind':row['kind'],'occurrence':row['occurrence'],
            'sha256':row['sha'],'dependency':row['dependency'],'attempts':row['attempts'],'total_attempts':row['total_attempts'],'reviews':row['reviews'],
            'context':{k:payload[k] for k in ('event_time','scan_time','camera_role','camera_id','direction','scan_location') if k in payload},
            'media':envelope.get('media',{}),'error':row['error']}

    def export_media(self,operation,destination,installation):
        destination=Path(destination)
        if destination.exists() or destination.resolve().is_relative_to(Path(installation).resolve()):
            raise ValueError('Select a new private review folder outside the installation')
        with self.db() as db:row=db.execute('SELECT envelope,media,sha FROM operations WHERE id=?',(operation,)).fetchone()
        if not row or not row['media']:raise ValueError('No unacknowledged evidence retained for this operation')
        envelope=json.loads(row['envelope']);media=json.loads(row['media']);verified={}
        if digest(row['envelope'].encode())!=row['sha']:raise ValueError('Envelope is corrupt; export refused')
        for name,info in envelope['media'].items():
            raw=base64.b64decode(media[name],validate=True)
            if name not in ('snapshot','plate_image') or len(raw)!=info['bytes'] or digest(raw)!=info['sha256']:raise ValueError('Evidence checksum mismatch')
            extension='png' if raw.startswith(b'\x89PNG\r\n\x1a\n') else 'jpg' if raw.startswith(b'\xff\xd8') else None
            if extension is None:raise ValueError('Unknown image encoding; protected retained bytes require review')
            verified[name+'.'+extension]=raw
        destination.mkdir(mode=0o700)
        for name,raw in verified.items():
            with (destination/name).open('xb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
        with (destination/'REVIEW.json').open('x') as f:json.dump(self.inspect(operation),f,indent=2);f.flush();os.fsync(f.fileno())
        return {'exported_images':list(verified),'operation':operation,'queue_changed':False}


_instances={};_lock=threading.Lock()
_targets={}
def configured():
    base=root()
    if base is None:return None
    with _lock:
        if str(base) not in _instances:_instances[str(base)]=Outbox(base)
        return _instances[str(base)]


def submit(kind,payload,url,key,media=None,operation=None):
    box=configured()
    if box is None:return None
    op=box.enqueue(kind,payload,media,operation)
    start_worker(url,key)
    return box.result(op)


def start_worker(url,key):
    box=configured()
    if box is None:return
    address=str(box.base)
    with _lock:
        prior=_targets.get(address)
        _targets[address]=(url,key)
        if prior is not None:return
    def work():
        while True:
            try:
                target,secret=_targets[address]
                result=box.deliver_one(target,secret) if target else None
                time.sleep(.05 if result else .5)
            except Exception:time.sleep(2)
    threading.Thread(target=work,name='durable-delivery',daemon=True).start()


def delivery_url(endpoint):return endpoint.rsplit('/',1)[0]+'/deliveries' if endpoint else ''


def can_capture():
    try:
        return admission() and (configured() is None or configured().status()['ready'])
    except Exception:return False


def capture_work(function=None,*,tuple_result=False):
    if function is None:return lambda fn:capture_work(fn,tuple_result=tuple_result)
    from functools import wraps
    @wraps(function)
    def wrapped(*args,**kwargs):
        try:
            with producer_work(function.__name__):return function(*args,**kwargs)
        except Paused:
            if tuple_result:return False,'Capture admission paused for maintenance'
            return {'accepted':False,'created':False,'durably_queued':False,'message':'Capture admission paused for maintenance'}
    return wrapped
