import base64
import hashlib
import json
from pathlib import Path
import subprocess
import unittest
from fixture import Installation
from backup_contract import database, load, tree_hash
from coordination import atomic, phase


def packet(box, operation):
    with box.db() as db: row = dict(db.execute('SELECT * FROM operations WHERE id=?', (operation,)).fetchone())
    return {'envelope': row['envelope'], 'sha256': row['sha'], 'media': json.loads(row['media'])}


def trust(f):
    # A separate temporary RSA identity; never the TLS key or a distributed private key.
    private = f.directory / 'fixture-update-private.pem'
    subprocess.run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', str(private)], check=True, capture_output=True)
    public = subprocess.check_output(['openssl', 'pkey', '-in', str(private), '-pubout']).decode(); private.unlink()
    der = base64.b64decode(''.join(line for line in public.splitlines() if not line.startswith('---')))
    key = hashlib.sha256(der).hexdigest(); binding = f.adapter.source_binding()
    value = {'schema': 'philcst.update-trust.v1', 'product': 'philcst-vms', 'environment': 'fixture', 'generation': 1,
        'keys': {key: {'public_key': public, 'roles': ['update', 'rotate'], 'channels': ['stable', 'prerelease', 'development'],
            'platforms': [binding['platform']], 'not_before': '2026-01-01T00:00:00Z', 'not_after': '2028-01-01T00:00:00Z', 'revoked': False}}}
    directory = f.p['targets']['update_trust']; directory.mkdir(mode=0o700)
    atomic(directory / 'trust.json', value)
    atomic(directory / 'state.json', {'schema': 'philcst.installation-update-state.v1', 'product': 'philcst-vms', 'environment': 'fixture',
        'platform': binding['platform'], 'os_version': '14.0.0', 'version': binding['version'], 'channel': 'development',
        'source': {'commit': binding['commit'], 'sha256': binding['source_sha256'], 'build_identity': binding['build_identity']},
        'schema_id': binding['schema_id'], 'runtime_id': binding['runtime_id'], 'max_expanded_bytes': 1000000})
    return key


class RecoverySecurity(unittest.TestCase):
    def setUp(self): self.f = Installation(); self.addCleanup(self.f.close)

    def test_lost_uid_current_security_setup_and_revoked_trust_survive_older_backup(self):
        f = self.f; key = trust(f)
        with database(f.p['targets']['database'], write=True) as db:
            vehicle = db.execute('SELECT id FROM vehicles').fetchone()[0]
            db.execute("INSERT INTO vehicle_rfid_tags(uid,tag_uid,tag_number,status,vehicle_id) VALUES('0000AABB','0000AABB',7,'assigned',?)", (vehicle,))
            tag = db.execute("SELECT id FROM vehicle_rfid_tags WHERE uid='0000AABB'").fetchone()[0]
            db.execute("UPDATE vehicles SET rfid_tag_id=?,rfid_tag_uid='0000AABB'", (tag,))
        backup = f.backup()
        with database(f.p['targets']['database'], write=True) as db:
            db.execute("UPDATE users SET account_status='disabled',session_version=12,activation_hash='expired-activation',remember_token='old-token'")
            db.execute("INSERT INTO unavailable_rfid_uids(uid,tag_id,marked_at,previous_status) VALUES('0000AABB',?,CURRENT_TIMESTAMP,'assigned')", (tag,))
            # A later lost UID at a different numeric ID must not lose its stable deny.
            db.execute("INSERT INTO unavailable_rfid_uids(uid,tag_id,marked_at,previous_status) VALUES('0000CCDD',NULL,CURRENT_TIMESTAMP,'available')")
            db.execute("UPDATE account_security_state SET version=10,setup_hash='never-reuse',setup_attempts=9")
            db.execute("INSERT INTO sessions(id,user_id,payload,last_activity) VALUES('old-session',1,'synthetic',1)")
        current = load(f.p['targets']['update_trust'] / 'trust.json'); current['generation'] = 2; current['keys'][key]['revoked'] = True
        atomic(f.p['targets']['update_trust'] / 'trust.json', current)
        tls_before = tree_hash(f.p['targets']['tls']); env_before = tree_hash(f.p['targets']['environment']); network = tree_hash(f.p['targets']['network'])
        operation = f.restore(backup); f.recovery.accept(operation); f.recovery.resume(operation)
        with database(f.p['targets']['database']) as db:
            user = dict(db.execute('SELECT * FROM users').fetchone()); setup = dict(db.execute('SELECT * FROM account_security_state').fetchone())
            self.assertEqual('disabled', user['account_status']); self.assertGreater(user['session_version'], 12)
            self.assertIsNone(user['remember_token']); self.assertIsNone(user['activation_hash'])
            self.assertIsNotNone(setup['setup_completed_at']); self.assertIsNone(setup['setup_hash']); self.assertGreater(setup['version'], 10)
            self.assertEqual(0, db.execute('SELECT COUNT(*) FROM sessions').fetchone()[0])
            self.assertEqual({'0000AABB', '0000CCDD'}, {r[0] for r in db.execute('SELECT uid FROM unavailable_rfid_uids')})
            self.assertEqual('lost', db.execute('SELECT status FROM vehicle_rfid_tags WHERE id=?', (tag,)).fetchone()[0])
            self.assertIsNone(db.execute('SELECT rfid_tag_id FROM vehicles').fetchone()[0])
        retained = load(f.p['targets']['update_trust'] / 'trust.json'); self.assertEqual(2, retained['generation']); self.assertTrue(retained['keys'][key]['revoked'])
        self.assertEqual((tls_before, env_before, network), tuple(tree_hash(f.p['targets'][k]) for k in ('tls', 'environment', 'network')))

    def test_acknowledgement_lost_before_backup_retries_after_restore_without_effect(self):
        f = self.f; operation_id = f.box.enqueue('crossing', f.php()['payload']); original = packet(f.box, operation_id)
        result = f.php('request', original); self.assertEqual(200, result['status'])
        # Server committed, collector never saw this ACK; backup pairs both durable states.
        before = f.php(); self.assertEqual('pending', f.box.inspect(operation_id)['state'])
        backup = f.backup(); operation = f.restore(backup); f.recovery.accept(operation); f.recovery.resume(operation)
        again = f.php('request', original)
        self.assertEqual(200, again['status']); self.assertTrue(again['body']['duplicate']); self.assertEqual(result['body']['delivery_ack'], again['body']['delivery_ack'])
        after = f.php(); self.assertEqual((before['events'], before['entries'], before['receipts']), (after['events'], after['entries'], after['receipts']))

    def test_newer_discarded_work_cannot_replay_but_retained_work_still_can(self):
        f = self.f; retained = f.box.enqueue('crossing', f.php()['payload']); retained_packet = packet(f.box, retained); backup = f.backup()
        payload = dict(f.php()['payload'], external_event_key='d5b-after-recovery-point')
        newer = f.box.enqueue('crossing', payload); newer_packet = packet(f.box, newer)
        self.assertEqual(200, f.php('request', newer_packet)['status'])
        operation = f.restore(backup); f.recovery.accept(operation); f.recovery.resume(operation)
        before = f.php(); denied = f.php('request', newer_packet)
        self.assertEqual(409, denied['status']); self.assertEqual(before['events'], f.php()['events'])
        self.assertEqual(200, f.php('request', retained_packet)['status'])
        self.assertTrue(f.php('request', retained_packet)['body']['duplicate'])
        self.assertEqual(before['events'], f.php()['events'])

    def test_missing_monotonic_state_and_epoch_mismatch_refuse_resume(self):
        f = self.f; backup = f.backup(); operation = f.enter()
        path = f.p['base'] / 'recovery-security.json'; original = load(path); path.unlink()
        with self.assertRaises(ValueError): f.recovery.ctl.resume(operation)
        atomic(path, dict(original, epoch=original['epoch'] + 1))
        with self.assertRaises(ValueError): f.recovery.plan(backup)
        self.assertNotEqual('running', phase(f.p['base'])['phase'])

    def test_malformed_update_trust_and_recycled_account_identity_reject(self):
        f = self.f; key = trust(f); backup = f.backup()
        store = f.p['targets']['update_trust'] / 'trust.json'; current = load(store); current['keys'][key]['roles'] = ['invented']
        atomic(store, current)
        with self.assertRaises(ValueError): f.recovery.plan(backup)
        current['keys'][key]['roles'] = ['update']; atomic(store, current)
        with database(f.p['targets']['database'], write=True) as db: db.execute("UPDATE users SET email='recycled@school.test'")
        operation = f.enter(); plan = f.recovery.plan(backup)
        with self.assertRaises(ValueError): f.recovery.restore(operation, backup, plan['confirmation'], True)
        self.assertNotEqual('running', phase(f.p['base'])['phase'])


if __name__ == '__main__': unittest.main()
