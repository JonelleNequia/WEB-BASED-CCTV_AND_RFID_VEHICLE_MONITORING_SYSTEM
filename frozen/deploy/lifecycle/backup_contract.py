"""D5B sensitive-data contract. Read-only validation; no incoming code execution.

ZIP path rules mirror D3's portable archive policy. Recovery sets are intentionally
not software updates, publisher signatures, or cross-machine transfer packages.
"""
from contextlib import contextmanager
import datetime as dt
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import sqlite3
import stat
import unicodedata
import uuid
import zipfile

SCHEMA = 'philcst.backup.v1'
PRODUCT = 'philcst-vms'
MAX_FILES = 250000
MAX_BYTES = 32 * 1024**3
MAX_FILE = 4 * 1024**3
MAX_JSON = 16 * 1024**2
ROLES = {'database', 'environment', 'storage', 'configuration', 'tls', 'network',
         'tls_identity', 'public_root', 'server_trust', 'update_trust', 'setup',
         'installation', 'delivery_identity', 'delivery', 'coordination', 'security'}
REQUIRED = ROLES - {'server_trust', 'update_trust', 'setup'}
MEDIA = {'vehicle_events': ['vehicle_image_path', 'plate_image_path'],
         'vehicle_crossings': ['snapshot_path'], 'visitor_records': ['snapshot_path', 'plate_image_path'],
         'guest_vehicle_observations': ['snapshot_path'], 'guest_visits': ['entry_snapshot', 'exit_snapshot']}


class InvalidBackup(ValueError):
    pass


def require(condition, message):
    if not condition:
        raise InvalidBackup(message)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode()


def sha(value):
    return hashlib.sha256(value).hexdigest()


def file_sha(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1024**2), b''):
            h.update(block)
    return h.hexdigest()


def parse(raw):
    require(len(raw) <= MAX_JSON, 'Recovery metadata exceeds the local limit')
    def pairs(items):
        out = {}
        for key, value in items:
            require(key not in out, 'Duplicate recovery JSON key')
            out[key] = value
        return out
    try:
        value = json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda x: (_ for _ in ()).throw(InvalidBackup('Nonfinite JSON')))
    except (ValueError, UnicodeError) as error:
        raise InvalidBackup('Malformed recovery JSON') from error
    require(type(value) is dict, 'Recovery metadata must be an object')
    return value


def load(path):
    with Path(path).open('rb') as stream:
        return parse(stream.read(MAX_JSON + 1))


def safe_path(name):
    require(type(name) is str and name and len(name) < 1024, 'Invalid archive path')
    require('\\' not in name and not re.search(r'[\x00-\x1f<>:"|?*]', name), 'Unsafe archive path')
    require(not name.startswith('/') and all(x not in ('', '.', '..') for x in name.split('/')), 'Unsafe archive path')
    for part in PurePosixPath(name).parts:
        require(not part.endswith((' ', '.')) and not re.fullmatch(r'(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?', part, re.I), 'Unsafe portable archive path')
    return name


def path_key(name):
    return unicodedata.normalize('NFC', name).casefold()


def no_links(path):
    path = Path(path).absolute()
    for part in [path, *path.parents]:
        require(not part.is_symlink() and not (hasattr(part, 'is_junction') and part.is_junction()), 'Unexpected recovery/state redirect')
        if part.exists():
            require(stat.S_ISDIR(part.stat().st_mode) or stat.S_ISREG(part.stat().st_mode), 'Special state file refused')


def inventory(path):
    path = Path(path); no_links(path)
    if not path.exists():
        return None
    if path.is_file():
        return {'': {'size': path.stat().st_size, 'sha256': file_sha(path)}}
    result = {}
    for directory, dirs, files in os.walk(path, followlinks=False):
        for name in dirs + files:
            child = Path(directory) / name; no_links(child)
        for name in files:
            child = Path(directory) / name
            rel = child.relative_to(path).as_posix(); safe_path(rel)
            result[rel] = {'size': child.stat().st_size, 'sha256': file_sha(child)}
    return result


def tree_hash(path):
    value = inventory(path)
    return None if value is None else sha(canonical(value))


@contextmanager
def database(path, write=False):
    path = Path(path); no_links(path)
    require(path.is_file(), 'Required SQLite file is missing')
    db = sqlite3.connect(path.resolve().as_uri() + ('?mode=rw' if write else '?mode=ro'), uri=True, timeout=5)
    db.row_factory = sqlite3.Row
    try:
        if not write:
            db.execute('PRAGMA query_only=ON')
        yield db
        if write:
            db.commit()
    finally:
        db.close()


def snapshot_sqlite(source, target):
    target = Path(target)
    require(not target.exists(), 'SQLite snapshot target already exists')
    target.parent.mkdir(parents=True, exist_ok=True)
    with database(source) as db:
        out = sqlite3.connect(target)
        try:
            db.backup(out)
            out.execute('PRAGMA journal_mode=DELETE')
        finally:
            out.close()
    target.chmod(0o600)


def sqlite_info(path):
    with database(path) as db:
        require([row[0] for row in db.execute('PRAGMA integrity_check')] == ['ok'], 'SQLite integrity check failed')
        schema = [list(row) for row in db.execute("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")]
        migrations = [dict(row) for row in db.execute('SELECT migration,batch FROM migrations ORDER BY migration')]
        defects = sorted([list(row) for row in db.execute('PRAGMA foreign_key_check')], key=canonical)
        return {'schema_sha256': sha(canonical(schema)), 'migrations': migrations, 'foreign_key_defects': defects}


def env_values(path):
    values = {}
    for line in Path(path).read_text().splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        require('=' in line, 'Unsupported environment syntax')
        key, value = line.split('=', 1)
        require(key not in values and re.fullmatch(r'[A-Z][A-Z0-9_]*', key), 'Duplicate/invalid environment key')
        value = value.strip()
        if value.startswith(('"', "'")):
            require(len(value) >= 2 and value[-1] == value[0], 'Unsupported quoted environment value')
            value = value[1:-1]
        else:
            require(' #' not in value, 'Inline environment comments need explicit review')
        values[key] = value
    require(values.get('DB_CONNECTION') == 'sqlite' and values.get('DB_DATABASE') not in (None, '', ':memory:'), 'Only installed local SQLite recovery is supported')
    require(values.get('APP_KEY') and values.get('DETECTOR_API_KEY'), 'Required encryption/integration key is missing')
    return values


def media_references(path):
    result = set()
    with database(path) as db:
        for table, columns in MEDIA.items():
            for column in columns:
                for row in db.execute(f'SELECT DISTINCT "{column}" FROM "{table}" WHERE "{column}" IS NOT NULL AND "{column}"!=\'\''):
                    value = row[0]
                    require(isinstance(value, str), 'Invalid historical media reference')
                    safe_path(value)
                    if value.startswith('occurrence-media/'):
                        require(re.fullmatch(r'occurrence-media/[a-f0-9-]{36}\.(jpg|png)', value), 'Unsupported private media reference')
                        result.add('app/private/' + value)
                    else:
                        require(re.fullmatch(r'[A-Za-z0-9_/-]+(?:[ .-][A-Za-z0-9_-]+)*\.(?:jpe?g|png|webp)', value, re.I), 'Unsupported historical media reference; separate review required')
                        result.add('app/public/' + value)
    with database(path) as db:
        for row in db.execute("SELECT DISTINCT payload_file_path FROM rfid_scan_logs WHERE payload_file_path IS NOT NULL AND payload_file_path!=''"):
            safe_path(row[0]); require(row[0].endswith('.json'), 'Unsupported RFID evidence path')
            result.add('app/private/' + row[0])
    return sorted(result)


def validate_delivery(db_path, spool_path, ident):
    with database(db_path) as app, database(spool_path) as spool:
        marker = app.execute('SELECT installation,generation FROM delivery_installations WHERE id=1').fetchone()
        require(marker and dict(marker) == {k: ident[k] for k in ('installation', 'generation')}, 'Database delivery identity mismatch')
        stored = spool.execute('SELECT identity FROM metadata').fetchall()
        require(len(stored) == 1 and parse(stored[0][0]) == ident, 'Outbox identity mismatch')
        require([row[0] for row in spool.execute('PRAGMA integrity_check')] == ['ok'], 'Outbox integrity failed')
        rows = [dict(row) for row in spool.execute('SELECT * FROM operations')]
        ids = {row['id'] for row in rows}
        for row in rows:
            require(re.fullmatch('[a-f0-9]{64}', row['id']) and re.fullmatch('[a-f0-9]{64}', row['sha']), 'Malformed retained delivery identity')
            receipt = app.execute('SELECT * FROM delivery_receipts WHERE operation=?', (row['id'],)).fetchone()
            if receipt:
                require(receipt['sha256'] == row['sha'] and all(receipt[k] == ident[k] for k in ('installation', 'generation')), 'Receipt/retained envelope mismatch')
            require(row['dependency'] is None or row['dependency'] in ids, 'Missing retained delivery dependency')
            require(row['state'] in ('pending', 'inflight', 'acked', 'dead'), 'Unsupported delivery state')
            if row['state'] == 'acked':
                require(receipt is not None, 'Acknowledged delivery lacks its canonical receipt')
                ack = parse(row['result'])['delivery_ack']
                require(ack == {'schema': 1, 'operation': row['id'], 'sha256': row['sha'], 'installation': ident['installation'], 'generation': ident['generation'], 'committed': True}, 'Retained acknowledgement mismatch')
                continue
            require(sha(row['envelope'].encode()) == row['sha'], 'Corrupt retained envelope')
            envelope = parse(row['envelope']); media = json.loads(row['media'])
            require(envelope['operation'] == row['id'] and all(envelope[k] == ident[k] for k in ('installation', 'generation')), 'Retained operation context mismatch')
            require(type(media) is dict and set(media) == set(envelope['media']), 'Retained evidence inventory mismatch')
            for key, item in envelope['media'].items():
                content = base64.b64decode(media[key], validate=True)
                require(len(content) == item['bytes'] and sha(content) == item['sha256'], 'Retained evidence checksum mismatch')
        return {'operations': len(rows), 'pending': sum(row['state'] != 'acked' for row in rows), 'receipts': app.execute('SELECT COUNT(*) FROM delivery_receipts').fetchone()[0]}


def validate_manifest(value):
    require(set(value) == {'schema', 'product', 'backup', 'created_at', 'timezone', 'compatibility', 'installation', 'maintenance', 'components', 'files', 'validation'}, 'Unexpected/missing backup manifest fields')
    require(value['schema'] == SCHEMA and value['product'] == PRODUCT and value['timezone'] == 'Asia/Manila', 'Wrong backup contract/product/timezone')
    uuid.UUID(value['backup'])
    require(dt.datetime.fromisoformat(value['created_at']).tzinfo is not None, 'Recovery point must include its timezone')
    require(value['maintenance'].get('phase') in ('quiescent', 'quiescent_pending', 'failed', 'fenced'), 'Backup was not captured with writers fenced')
    require(value['installation']['identity'].get('schema') == 1, 'Missing installation/generation contract')
    require(type(value['components']) is dict and set(value['components']) == ROLES, 'Incomplete recovery role inventory')
    require(value['validation'].get('complete') is True, 'Backup was not completed')
    for role, component in value['components'].items():
        require(set(component) == {'present', 'kind'} and type(component['present']) is bool and component['kind'] in ('file', 'directory'), 'Malformed backup component')
        require(component['present'] or role not in REQUIRED, 'Missing required recovery component: ' + role)
    files = value['files']; require(type(files) is dict and 0 < len(files) <= MAX_FILES, 'Invalid recovery inventory size')
    names = set(); total = 0
    for name, item in files.items():
        safe_path(name); parts = name.split('/')
        require(len(parts) >= 3 and parts[0] == 'payload' and parts[1] in ROLES, 'Unexpected backup file role')
        component = value['components'][parts[1]]
        require(component['present'] and (component['kind'] != 'file' or parts[2:] == ['data']), 'File outside its controlled component')
        require(set(item) == {'size', 'sha256'} and type(item['size']) is int and 0 <= item['size'] <= MAX_FILE
                and type(item['sha256']) is str and re.fullmatch('[a-f0-9]{64}', item['sha256']), 'Malformed backup file inventory')
        key = path_key(name); require(key not in names, 'Case/Unicode collision in backup'); names.add(key); total += item['size']
    require(total <= MAX_BYTES, 'Backup expansion exceeds the installed limit')
    for role, component in value['components'].items():
        if component == {'present': True, 'kind': 'file'}:
            require(f'payload/{role}/data' in files, 'Required file component has no bytes')
    for name in names:
        parts = name.split('/')
        require(not any('/'.join(parts[:i]) in names for i in range(1, len(parts))), 'Archive file/directory shadowing')
    return value


def inspect_bundle(path, extract=None):
    path = Path(path); path = path / 'backup.zip' if path.is_dir() else path
    no_links(path); receipt = load(path.with_name('receipt.json'))
    require(set(receipt) == {'schema', 'backup', 'archive_sha256', 'archive_size', 'manifest_sha256', 'complete'}
            and receipt['schema'] == 'philcst.backup-receipt.v1' and receipt['complete'] is True, 'Missing completed backup receipt')
    require(path.stat().st_size == receipt['archive_size'] and file_sha(path) == receipt['archive_sha256'], 'Truncated or corrupted backup archive')
    try:
        with zipfile.ZipFile(path) as archive:
            require(0 < len(archive.infolist()) <= MAX_FILES + 1, 'Excessive archive entries')
            seen = set(); items = {}
            for item in archive.infolist():
                name = safe_path(item.filename)
                require(not item.is_dir() and not item.flag_bits & 1 and (item.external_attr >> 16) & 0o170000 in (0, stat.S_IFREG), 'Archive directory/link/device/encryption refused')
                require(path_key(name) not in seen, 'Archive duplicate/case/Unicode collision'); seen.add(path_key(name)); items[name] = item
                require(item.file_size <= MAX_FILE, 'Oversized archive entry')
            require('BACKUP.json' in items and items['BACKUP.json'].file_size <= MAX_JSON, 'Missing/oversized backup manifest')
            raw = archive.read('BACKUP.json'); require(sha(raw) == receipt['manifest_sha256'], 'Backup manifest checksum mismatch')
            manifest = validate_manifest(parse(raw)); require(manifest['backup'] == receipt['backup'], 'Backup receipt identity mismatch')
            require(set(items) == set(manifest['files']) | {'BACKUP.json'}, 'Unexpected or missing archive files')
            if extract:
                extract = Path(extract); require(not extract.exists(), 'Recovery staging directory already exists')
                extract.mkdir(mode=0o700, parents=True)
            for name, expected in manifest['files'].items():
                require(items[name].file_size == expected['size'], 'Truncated inventory member')
                h = hashlib.sha256(); count = 0; out = None
                try:
                    if extract:
                        target = Path(extract) / name; target.parent.mkdir(parents=True, exist_ok=True); out = target.open('xb'); target.chmod(0o600)
                    with archive.open(items[name]) as stream:
                        for block in iter(lambda: stream.read(1024**2), b''):
                            count += len(block); require(count <= expected['size'], 'Archive expands beyond its declared size'); h.update(block)
                            if out: out.write(block)
                    require(count == expected['size'] and h.hexdigest() == expected['sha256'], 'Backup member checksum mismatch')
                    if out: out.flush(); os.fsync(out.fileno())
                finally:
                    if out: out.close()
            if extract:
                for role, item in manifest['components'].items():
                    if item['present'] and item['kind'] == 'directory':
                        (Path(extract) / 'payload' / role).mkdir(parents=True, exist_ok=True)
            return manifest
    except (zipfile.BadZipFile, EOFError, KeyError) as error:
        raise InvalidBackup('Malformed/incomplete backup archive') from error
