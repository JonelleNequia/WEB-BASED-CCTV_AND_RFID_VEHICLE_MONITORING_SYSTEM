"""Monotonic D5B reconciliation, applied to staged copies only.
No arbitrary later vehicle/activity rows are merged into an older recovery point.
"""
import datetime as dt
import re
import uuid
from backup_contract import canonical, database, load, require, sha

SECURITY_SCHEMA = 'philcst.recovery-security.v1'


def uid(value):
    return re.sub(r'\s+', '', str(value or '')).upper()


def rows(db, table):
    return [dict(row) for row in db.execute('SELECT * FROM "' + table + '"')]


def security_state(path):
    with database(path) as db:
        lost = {uid(row[0]) for row in db.execute('SELECT uid FROM unavailable_rfid_uids')}
        for row in db.execute("SELECT uid,tag_uid FROM vehicle_rfid_tags WHERE status='lost'"):
            lost.update(uid(value) for value in row)
        marker = db.execute('SELECT epoch,active_set FROM recovery_state WHERE id=1').fetchone()
        require(marker is not None, 'D5B recovery schema is not installed; upgrade origins remain unapproved')
        return {'lost_uids': sorted(lost - {''}), 'users': rows(db, 'users'),
                'setup': dict(db.execute('SELECT * FROM account_security_state WHERE id=1').fetchone()),
                'events': rows(db, 'security_events'), 'epoch': marker['epoch'], 'active_set': marker['active_set']}


def validate_ledger(value, ident):
    require(set(value) == {'schema', 'installation', 'generation', 'epoch', 'active_set', 'lost_uids',
                           'revoked_update_keys', 'update_generation', 'blocked_by_set'}, 'Invalid protected recovery security ledger')
    require(value['schema'] == SECURITY_SCHEMA and all(value[k] == ident[k] for k in ('installation', 'generation')), 'Protected security identity mismatch')
    require(type(value['epoch']) is int and value['epoch'] >= 0 and type(value['update_generation']) is int and value['update_generation'] >= 0, 'Invalid monotonic security generation')
    uuid.UUID(value['active_set'])
    require(type(value['lost_uids']) is list and all(type(v) is str and uid(v) == v and 0 < len(v) <= 100 for v in value['lost_uids']), 'Invalid retained permanent UID')
    require(type(value['revoked_update_keys']) is list and all(type(v) is str and re.fullmatch('[a-f0-9]{64}', v) for v in value['revoked_update_keys']), 'Invalid retained key revocation')
    require(type(value['blocked_by_set']) is dict and value['active_set'] in value['blocked_by_set'], 'Missing recovery-set replay policy')
    for key, operations in value['blocked_by_set'].items():
        uuid.UUID(key); require(type(operations) is dict, 'Invalid retained replay policy')
        require(all(re.fullmatch('[a-f0-9]{64}', k) and type(v) is str and re.fullmatch('[a-f0-9]{64}', v) for k, v in operations.items()), 'Invalid retained operation block')
    return value


def initial_ledger(ident, db_path):
    state = security_state(db_path)
    return {'schema': SECURITY_SCHEMA, 'installation': ident['installation'], 'generation': ident['generation'],
            'epoch': state['epoch'], 'active_set': state['active_set'], 'lost_uids': state['lost_uids'],
            'revoked_update_keys': [], 'update_generation': 0, 'blocked_by_set': {state['active_set']: {}}}


def operations(db_path, spool_path):
    result = {}
    with database(db_path) as db:
        result.update({row[0]: row[1] for row in db.execute('SELECT operation,sha256 FROM delivery_receipts')})
    with database(spool_path) as db:
        for row in db.execute('SELECT id,sha FROM operations'):
            require(row[0] not in result or result[row[0]] == row[1], 'Receipt/operation checksum conflict')
            result[row[0]] = row[1]
    return result


def union_ledger(current, saved, current_db, target_db, current_spool, target_spool, update_store=None):
    require(current['installation'] == saved['installation'] and current['generation'] == saved['generation'], 'Unsupported installation/generation recovery')
    live, older = security_state(current_db), security_state(target_db)
    require(current['epoch'] >= saved['epoch'] and current['epoch'] == live['epoch'] and current['active_set'] == live['active_set'], 'Protected recovery epoch is missing, stale or rolled back')
    new = dict(current, epoch=current['epoch'] + 1)
    new['lost_uids'] = sorted(set(current['lost_uids']) | set(saved['lost_uids']) | set(live['lost_uids']) | set(older['lost_uids']))
    revoked = set(current['revoked_update_keys']) | set(saved['revoked_update_keys'])
    generation = max(current['update_generation'], saved['update_generation'])
    if update_store:
        store = load(update_store)
        generation = max(generation, store['generation'])
        revoked.update(k for k, v in store['keys'].items() if v['revoked'])
    new.update(revoked_update_keys=sorted(revoked), update_generation=generation)
    restore_set, rollback_set = str(uuid.uuid4()), str(uuid.uuid4())
    after = operations(current_db, current_spool); retained = operations(target_db, target_spool)
    for operation in after.keys() & retained.keys():
        require(after[operation] == retained[operation], 'Original delivery identity has conflicting immutable content')
    prior_blocks = dict(current['blocked_by_set'][current['active_set']])
    new['blocked_by_set'] = dict(current['blocked_by_set'])
    new['blocked_by_set'][restore_set] = prior_blocks | {k: v for k, v in after.items() if k not in retained}
    new['blocked_by_set'][rollback_set] = prior_blocks
    return new, restore_set, rollback_set


def put_row(db, table, row, replace=False):
    names = list(row); columns = ','.join('"' + name + '"' for name in names)
    db.execute(('INSERT OR REPLACE' if replace else 'INSERT') + ' INTO "' + table + '" (' + columns + ') VALUES (' + ','.join('?' for _ in names) + ')', [row[k] for k in names])


def reconcile_database(target, current, ledger, set_id):
    """Current account security wins; removed historical accounts remain disabled."""
    newer = security_state(current)
    now = dt.datetime.now(dt.timezone(dt.timedelta(hours=8))).strftime('%Y-%m-%d %H:%M:%S')
    with database(target, write=True) as db:
        db.execute('BEGIN IMMEDIATE')
        prior_users = {row['id']: row for row in rows(db, 'users')}
        current_users = {row['id']: row for row in newer['users']}
        for user_id, old in prior_users.items():
            current_user = current_users.get(user_id)
            if current_user:
                require(all(old.get(k) == current_user.get(k) for k in ('email', 'created_at')), 'Recycled account identity requires separate reviewed recovery')
                merged = dict(current_user)
            else:
                merged = dict(old, account_status='disabled')
            merged.update(session_version=max(old['session_version'], (current_user or {}).get('session_version', 0)) + 1,
                          remember_token=None, activation_hash=None, activation_expires_at=None)
            assignments = ','.join('"' + k + '"=?' for k in merged if k != 'id')
            db.execute('UPDATE users SET ' + assignments + ' WHERE id=?', [v for k, v in merged.items() if k != 'id'] + [user_id])
        for user_id, user in current_users.items():
            if user_id not in prior_users:
                require(not db.execute('SELECT 1 FROM users WHERE lower(email)=lower(?)', (user['email'],)).fetchone(), 'Account identity collision requires separate review')
                put_row(db, 'users', dict(user, session_version=user['session_version'] + 1, remember_token=None, activation_hash=None, activation_expires_at=None))
        saved_setup = dict(db.execute('SELECT * FROM account_security_state WHERE id=1').fetchone())
        setup = dict(newer['setup'])
        setup.update(version=max(setup['version'], saved_setup['version']) + 1,
                     setup_completed_at=setup['setup_completed_at'] or saved_setup['setup_completed_at'],
                     setup_hash=None, setup_expires_at=None,
                     setup_attempts=max(setup['setup_attempts'], saved_setup['setup_attempts']))
        # UPDATE preserves existing relationships and does not invoke DELETE/REPLACE FK effects.
        db.execute('UPDATE account_security_state SET ' + ','.join('"' + k + '"=?' for k in setup if k != 'id') + ' WHERE id=1', [v for k, v in setup.items() if k != 'id'])
        old_events = {row['id']: row for row in rows(db, 'security_events')}
        for event in newer['events']:
            if event['id'] in old_events:
                require(event == old_events[event['id']], 'Security audit identity collision; no guessed merge permitted')
            else:
                put_row(db, 'security_events', event)
        for table in ('sessions', 'password_reset_tokens', 'cache', 'cache_locks'):
            if db.execute('SELECT 1 FROM sqlite_master WHERE type=\'table\' AND name=?', (table,)).fetchone():
                db.execute('DELETE FROM "' + table + '"')
        lost = set(ledger['lost_uids']); lost_ids = []
        for row in db.execute('SELECT id,uid,tag_uid FROM vehicle_rfid_tags'):
            if uid(row['uid']) in lost or uid(row['tag_uid']) in lost:
                lost_ids.append(row['id'])
        for value in lost:
            db.execute('INSERT OR IGNORE INTO unavailable_rfid_uids(uid,tag_id,marked_by,marked_at,previous_status) VALUES(?,NULL,NULL,?,?)', (value, now, 'recovery_deny'))
        for tag_id in lost_ids:
            db.execute("UPDATE vehicle_rfid_tags SET status='lost',vehicle_id=NULL,assigned_at=NULL WHERE id=?", (tag_id,))
            db.execute('UPDATE vehicles SET rfid_tag_id=NULL,rfid_tag_uid=NULL WHERE rfid_tag_id=?', (tag_id,))
        for row in db.execute('SELECT id,rfid_tag_uid FROM vehicles').fetchall():
            if uid(row['rfid_tag_uid']) in lost:
                db.execute('UPDATE vehicles SET rfid_tag_id=NULL,rfid_tag_uid=NULL WHERE id=?', (row['id'],))
        db.execute('UPDATE recovery_state SET epoch=?,active_set=? WHERE id=1', (ledger['epoch'], set_id))
    return {'sessions_invalidated': True, 'setup_codes_invalidated': True, 'permanent_uids': len(lost)}


def reset_leases(spool):
    with database(spool, write=True) as db:
        db.execute("UPDATE operations SET state='pending',token=NULL,lease=0 WHERE state='inflight'")
