import base64
from contextlib import contextmanager
import copy
import json
import multiprocessing
import os
from pathlib import Path
import shutil
import signal
import sqlite3
import stat
import time
import unittest
import uuid
from unittest.mock import patch
import zipfile
from fixture import Installation
from backup_contract import canonical, database, file_sha, inspect_bundle, load, sha, tree_hash, InvalidBackup
from coordination import phase, atomic, barrier, Paused
from recovery import Recovery, safe_error
from service_host import allowed


def rewrite(f, backup, change=None, extra=None, omit=None):
    """Corruption/hostile-envelope fixture, not an authentication scheme."""
    target = f.directory / ('bundle-' + uuid.uuid4().hex); target.mkdir(mode=0o700)
    with zipfile.ZipFile(backup / 'backup.zip') as archive:
        files = {i.filename: archive.read(i) for i in archive.infolist() if i.filename != 'BACKUP.json'}
        manifest = json.loads(archive.read('BACKUP.json'))
    if change: change(manifest, files)
    manifest['files'] = {name: {'size': len(data), 'sha256': sha(data)} for name, data in files.items()}
    with zipfile.ZipFile(target / 'backup.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
        for name, data in files.items():
            if name != omit: archive.writestr(name, data)
        archive.writestr('BACKUP.json', canonical(manifest))
        if extra:
            name, data, kind = extra
            info = zipfile.ZipInfo(name); info.external_attr = kind << 16
            archive.writestr(info, data)
    atomic(target / 'receipt.json', {'schema': 'philcst.backup-receipt.v1', 'backup': manifest['backup'], 'archive_sha256': file_sha(target / 'backup.zip'),
        'archive_size': (target / 'backup.zip').stat().st_size, 'manifest_sha256': sha(canonical(manifest)), 'complete': True})
    return target


def terminate_restore(f, backup, operation, confirmation, point):
    def checkpoint(name):
        if name == point: os.kill(os.getpid(), signal.SIGKILL)
    f.recovery.checkpoint = checkpoint
    f.recovery.restore(operation, backup, confirmation, True, True)


class RecoveryFailures(unittest.TestCase):
    def setUp(self):
        self.f = Installation(); self.addCleanup(self.f.close)

    def stopped(self):
        self.assertFalse(allowed(self.f.p['base'], 'detector'))
        self.assertFalse(allowed(self.f.p['base'], 'web'))
        self.assertNotEqual('running', phase(self.f.p['base'])['phase'])

    def test_inspection_is_read_only_and_confirmation_is_concrete(self):
        f = self.f; backup = f.backup(); before = {role: tree_hash(p) for role, p in f.p['targets'].items()}
        plan = f.recovery.plan(backup)
        self.assertEqual(before, {role: tree_hash(p) for role, p in f.p['targets'].items()})
        operation = f.enter()
        with self.assertRaises(ValueError): f.recovery.restore(operation, backup, plan['confirmation'], False)
        with database(f.p['targets']['database'], write=True) as db: db.execute("UPDATE vehicles SET vehicle_owner_name='Changed After Approval'")
        with self.assertRaises(ValueError): f.recovery.restore(operation, backup, plan['confirmation'], True)
        self.stopped()

    def test_missing_truncated_and_wrong_hash_payloads(self):
        f = self.f; backup = f.backup()
        missing = rewrite(f, backup, omit='payload/environment/data')
        with self.assertRaises(ValueError): f.recovery.plan(missing)
        for end in (0, 10, -50):
            bad = rewrite(f, backup); archive = bad / 'backup.zip'; data = archive.read_bytes(); archive.write_bytes(data[:end])
            with self.assertRaises(ValueError): f.recovery.plan(bad)
        bad = rewrite(f, backup); receipt = load(bad / 'receipt.json'); receipt['archive_sha256'] = '0' * 64; atomic(bad / 'receipt.json', receipt)
        with self.assertRaises(ValueError): f.recovery.plan(bad)
        bad = rewrite(f, backup, lambda m, data: data.__setitem__('payload/database/data', b'not sqlite'))
        with self.assertRaises((ValueError, sqlite3.DatabaseError)): f.recovery.plan(bad)

    def test_safe_paths_links_duplicates_collisions_and_expansion(self):
        f = self.f; backup = f.backup()
        cases = [('../escape', stat.S_IFREG), ('/absolute', stat.S_IFREG), ('payload/link', stat.S_IFLNK),
            ('BACKUP.json', stat.S_IFREG), ('backup.JSON', stat.S_IFREG), ('C:/drive', stat.S_IFREG), ('payload/CON.txt', stat.S_IFREG)]
        for name, kind in cases:
            with self.subTest(name=name):
                bad = rewrite(f, backup, extra=(name, b'x', kind))
                with self.assertRaises(ValueError): inspect_bundle(bad)
        def collide(m, data):
            data['payload/storage/app/public/Case.txt'] = b'a'; data['payload/storage/app/public/case.TXT'] = b'b'
        def unicode(m, data):
            data['payload/storage/app/public/Café.txt'] = b'a'; data['payload/storage/app/public/Cafe\u0301.txt'] = b'b'
        def shadow(m, data):
            data['payload/storage/app/shadow'] = b'a'; data['payload/storage/app/shadow/file'] = b'b'
        for change in (collide, unicode, shadow):
            with self.assertRaises(ValueError): inspect_bundle(rewrite(f, backup, change))
        with patch('backup_contract.MAX_BYTES', 1):
            with self.assertRaises(ValueError): inspect_bundle(backup)
        self.assertFalse((f.directory / 'escape').exists())

    def test_interrupted_initialization_reuses_only_recorded_exact_intent(self):
        f = Installation(auto_initialize=False)
        self.addCleanup(f.close); operation = str(uuid.uuid4())
        def interrupt(point):
            if point == 'journal:preparing': raise OSError('Interrupted initial registration')
        f.recovery.checkpoint = interrupt
        with self.assertRaises(OSError): f.recovery.initialize(operation)
        self.assertFalse(allowed(f.p['base'], 'web'))
        f.recovery.checkpoint = lambda _: None
        f.recovery.recover(operation, 'current'); f.recovery.resume(operation)
        self.assertTrue(allowed(f.p['base'], 'web'))

    def test_locked_database_rename_retains_maintenance_and_verified_rollback(self):
        f = self.f; backup = f.backup(); operation = f.enter(); plan = f.recovery.plan(backup)
        original = os.rename
        def locked(source, target):
            if Path(source) == f.p['targets']['database']: raise PermissionError('Synthetic Windows sharing violation')
            return original(source, target)
        with patch('recovery.os.rename', side_effect=locked):
            with self.assertRaises(PermissionError): f.recovery.restore(operation, backup, plan['confirmation'], True)
        self.stopped(); f.recovery.recover(operation, 'rollback'); f.recovery.accept(operation); f.recovery.resume(operation)
        self.assertEqual('running', phase(f.p['base'])['phase'])

    def test_legacy_visit_images_and_rfid_json_are_required_and_retained(self):
        f = self.f
        photo = f.p['targets']['storage'] / 'app/public/guest_snapshots/legacy.jpg'
        photo.parent.mkdir(parents=True, exist_ok=True); photo.write_bytes(b'legacy linked image')
        export = f.p['targets']['storage'] / 'app/private/rfid-scan-exports/entrance/fixture.json'
        export.parent.mkdir(parents=True, exist_ok=True); export.write_text('{"fixture":true}')
        with database(f.p['targets']['database'], write=True) as db:
            db.execute("INSERT INTO guest_visits(rfid_tag_id,entry_at,entry_snapshot,status) VALUES(10,CURRENT_TIMESTAMP,'guest_snapshots/legacy.jpg','completed')")
            db.execute("INSERT INTO rfid_scan_logs(tag_uid,scan_location,scan_time,payload_file_path) VALUES('0000FF','gate-1',CURRENT_TIMESTAMP,'rfid-scan-exports/entrance/fixture.json')")
        backup = f.backup(); manifest = inspect_bundle(backup)
        self.assertIn('payload/storage/app/public/guest_snapshots/legacy.jpg', manifest['files'])
        self.assertIn('payload/storage/app/private/rfid-scan-exports/entrance/fixture.json', manifest['files'])
        export.unlink(); operation = str(uuid.uuid4())
        with self.assertRaises(ValueError): f.recovery.backup(operation)
        self.stopped()

    def test_wrong_installation_source_schema_runtime_and_machine_are_rejected(self):
        f = self.f; backup = f.backup()
        for category, key, value in [('compatibility', 'source_sha256', 'a' * 64), ('compatibility', 'runtime_id', 'a' * 64),
            ('compatibility', 'schema_id', 'a' * 64), ('compatibility', 'platform', 'windows-x64'), ('installation', 'host_sha256', 'a' * 64)]:
            with self.subTest(key=key):
                bad = rewrite(f, backup, lambda m, d: m[category].__setitem__(key, value))
                with self.assertRaises(ValueError): f.recovery.plan(bad)
        host = load(f.recovery.root / 'host.json'); host['machine'] = 'wrong-machine'; atomic(f.recovery.root / 'host.json', host)
        with self.assertRaises(ValueError): f.recovery.plan(backup)

    def test_wrong_encryption_key_missing_ca_key_and_referenced_image_fail(self):
        f = self.f; backup = f.backup()
        def key(m, data):
            env = data['payload/environment/data'].decode(); original = f.env['APP_KEY']
            data['payload/environment/data'] = env.replace(original, 'base64:' + base64.b64encode(b'a' * 32).decode()).encode()
        def ca(m, data): data.pop('payload/tls/tls/pki/authorities/local/root.key')
        def image(m, data):
            for name in list(data):
                if name.startswith('payload/storage/app/private/occurrence-media/'): data.pop(name)
        for change in (key, ca, image):
            with self.subTest(change=change.__name__):
                with self.assertRaises(ValueError): f.recovery.plan(rewrite(f, backup, change))
        self.assertEqual('running', phase(f.p['base'])['phase'])

    def test_backup_failure_retains_valid_set_and_requires_explicit_resume(self):
        f = self.f; valid = f.backup(); digest = file_sha(valid / 'backup.zip')
        operation = str(uuid.uuid4())
        def checkpoint(point):
            if point == 'backup:before_publish': raise OSError('Synthetic failed archive publication')
        f.recovery.checkpoint = checkpoint
        with self.assertRaises(OSError): f.recovery.backup(operation)
        self.stopped(); self.assertEqual(digest, file_sha(valid / 'backup.zip')); inspect_bundle(valid)
        self.assertTrue(list((f.recovery.root / 'backups').glob('.incomplete-*')))
        f.recovery.checkpoint = lambda _: None
        with self.assertRaises(ValueError): f.recovery.resume(operation)
        f.recovery.recover(operation, 'current'); f.recovery.resume(operation)
        self.assertEqual('running', phase(f.p['base'])['phase'])

    def test_disk_and_permissions_fail_closed(self):
        f = self.f; valid = f.backup()
        f.adapter.failure = 'disk'
        with self.assertRaises(OSError): f.recovery.plan(valid)
        self.assertEqual('running', phase(f.p['base'])['phase'])
        operation = str(uuid.uuid4())
        with self.assertRaises(OSError): f.recovery.backup(operation)
        self.stopped(); inspect_bundle(valid)
        f.adapter.failure = None; f.recovery.recover(operation, 'current'); f.recovery.resume(operation)
        operation = f.enter(); plan = f.recovery.plan(valid); f.adapter.failure = 'permissions'
        with self.assertRaises(PermissionError): f.recovery.restore(operation, valid, plan['confirmation'], True)
        self.stopped()

    def test_each_database_media_environment_configuration_failure_can_rollback(self):
        f = self.f; backup = f.backup()
        # Every controlled component, including preserved configuration/key bytes,
        # crosses a journal intent boundary even when its bytes happen to agree.
        for role in ('database', 'storage', 'environment', 'configuration'):
            with self.subTest(role=role):
                with database(f.p['targets']['database'], write=True) as db: db.execute("UPDATE vehicles SET vehicle_owner_name='Current Owner'")
                operation = f.enter(); plan = f.recovery.plan(backup)
                def checkpoint(point):
                    if point == ('promote:component:' + role): raise OSError('Synthetic locked-file failure')
                f.recovery.checkpoint = checkpoint
                with self.assertRaises(OSError): f.recovery.restore(operation, backup, plan['confirmation'], True)
                self.stopped()
                with self.assertRaises(ValueError): f.recovery.ctl.resume(operation)
                f.recovery.checkpoint = lambda _: None
                f.recovery.recover(operation, 'rollback'); f.recovery.accept(operation); f.recovery.resume(operation)
                with database(f.p['targets']['database']) as db:
                    self.assertEqual('Current Owner', db.execute('SELECT vehicle_owner_name FROM vehicles').fetchone()[0])

    @unittest.skipUnless(os.name == 'posix', 'SIGKILL/fork fixture requires POSIX')
    def test_real_process_kill_between_renames_recovery_uses_journal_and_hashes(self):
        f = self.f; backup = f.backup()
        for point in ('journal:prepared', 'promote:old_moved:database', 'promote:new_installed:storage'):
            with self.subTest(point=point):
                with database(f.p['targets']['database'], write=True) as db: db.execute("UPDATE vehicles SET vehicle_owner_name='Before Kill'")
                operation = f.enter(); plan = f.recovery.plan(backup)
                process = multiprocessing.get_context('fork').Process(target=terminate_restore, args=(f, backup, operation, plan['confirmation'], point))
                process.start(); process.join(30)
                if process.is_alive(): process.kill(); process.join(); self.fail('Recovery subprocess stalled')
                self.assertEqual(-signal.SIGKILL, process.exitcode); self.stopped()
                self.assertTrue((f.p['base'] / 'operation.lock').exists())
                with self.assertRaises(ValueError): f.recovery.recover(operation, 'forward')
                f.recovery.ctl.recover(operation)
                f.recovery.recover(operation, 'forward'); f.recovery.accept(operation); f.recovery.resume(operation)
                self.assertEqual('running', phase(f.p['base'])['phase'])
                with database(f.p['targets']['database']) as db:
                    self.assertEqual('Fixture Owner', db.execute('SELECT vehicle_owner_name FROM vehicles').fetchone()[0])

    def test_corrupt_staging_and_failed_rollback_keep_all_writers_closed(self):
        f = self.f; backup = f.backup(); operation = f.enter(); plan = f.recovery.plan(backup)
        def interrupt(point):
            if point == 'promote:old_moved:database': raise OSError('Locked destination')
        f.recovery.checkpoint = interrupt
        with self.assertRaises(OSError): f.recovery.restore(operation, backup, plan['confirmation'], True)
        f.recovery.checkpoint = lambda _: None
        (f.recovery.operation_dir(operation) / 'incoming/payload/database/data').write_bytes(b'bad')
        (f.recovery.operation_dir(operation) / 'current/payload/database/data').write_bytes(b'bad rollback')
        for decision in ('forward', 'rollback'):
            with self.assertRaises(ValueError): f.recovery.recover(operation, decision)
            self.stopped()

    def test_two_backup_owners_and_scheduler_delivery_admission_cannot_race(self):
        f = self.f; backup = f.backup(); operation = f.enter()
        with f.recovery.ctl.owned(operation):
            with self.assertRaises(ValueError): f.recovery.backup(operation)
            with self.assertRaises(ValueError): f.recovery.restore(operation, backup, f.recovery.plan(backup)['confirmation'], True)
        for kind in ('capture', 'delivery', 'web', 'scheduler'):
            with self.subTest(kind=kind):
                with self.assertRaises(Paused):
                    with barrier(f.p['base'], kind): pass
        self.stopped()

    def test_generated_configuration_failure_never_reopens_capture(self):
        f = self.f; backup = f.backup(); operation = f.restore(backup); f.recovery.accept(operation)
        f.adapter.failure = 'prepare'
        with self.assertRaises(ValueError): f.recovery.resume(operation)
        self.stopped(); f.adapter.failure = None; f.recovery.resume(operation)
        self.assertEqual('running', phase(f.p['base'])['phase'])
        # Ordinary later D5A maintenance remains available after D5B acceptance.
        later = f.enter(); f.recovery.ctl.resume(later)
        self.assertEqual('running', phase(f.p['base'])['phase'])

    def test_known_relationship_defects_are_reported_and_need_acknowledgement(self):
        f = self.f
        with database(f.p['targets']['database'], write=True) as db:
            db.execute('UPDATE visitor_records SET plate_profile_id=99999')
        backup = f.backup(); operation = f.enter(); plan = f.recovery.plan(backup)
        self.assertTrue(plan['known_relationship_defects'])
        with self.assertRaises(ValueError): f.recovery.restore(operation, backup, plan['confirmation'], True, False)
        f.recovery.restore(operation, backup, plan['confirmation'], True, True)
        f.recovery.accept(operation); f.recovery.resume(operation)
        with database(f.p['targets']['database']) as db:
            self.assertEqual(99999, db.execute('SELECT plate_profile_id FROM visitor_records').fetchone()[0])

    def test_legacy_color_and_relationship_columns_survive_without_reclassification(self):
        f = self.f
        with database(f.p['targets']['database'], write=True) as db:
            db.execute("UPDATE vehicle_events SET vehicle_color='Legacy event color'")
            db.execute("UPDATE visitor_records SET vehicle_color='Legacy visitor color'")
            before = [tuple(r) for r in db.execute('SELECT id,visitor_record_id,vehicle_color FROM vehicle_events')]
        backup = f.backup()
        with database(f.p['targets']['database'], write=True) as db: db.execute('UPDATE vehicle_events SET vehicle_color=NULL')
        operation = f.restore(backup); f.recovery.accept(operation); f.recovery.resume(operation)
        with database(f.p['targets']['database']) as db:
            self.assertEqual(before, [tuple(r) for r in db.execute('SELECT id,visitor_record_id,vehicle_color FROM vehicle_events')])


class ConcurrentRecovery(unittest.TestCase):
    def test_safe_failure_feedback_never_echoes_untrusted_exception_text(self):
        self.assertNotIn('synthetic-secret', safe_error(RuntimeError('synthetic-secret')))
        self.assertNotIn('synthetic-secret', safe_error(OSError('synthetic-secret')))
        self.assertIn('Required media is missing', safe_error(InvalidBackup('Required media is missing')))

    def test_installed_environment_cannot_inherit_shell_secret_database_or_cache_overrides(self):
        from recovery_platform import NativeRecovery
        import types
        for windows in (True, False):
            with self.subTest(windows=windows):
                f = Installation(windows=windows); self.addCleanup(f.close)
                module = types.SimpleNamespace(runtime_env=lambda *args: {'APP_ENV': 'production'})
                with patch.dict(os.environ, {'APP_KEY': 'synthetic-shell-key', 'DB_DATABASE': '/outside/db', 'APP_CONFIG_CACHE': '/outside/cache', 'APP_ROUTES_CACHE': '/outside/routes', 'MONITORING_SERVICES': 'app'}), patch.object(f.adapter, 'mac', return_value=module):
                    result = NativeRecovery.env(f.adapter)
                for key in ('APP_KEY', 'DB_DATABASE', 'APP_ROUTES_CACHE', 'MONITORING_SERVICES'): self.assertNotIn(key, result)
                self.assertEqual(str(f.p['app']), result['APP_BASE_PATH'])
                self.assertTrue(Path(result['APP_CONFIG_CACHE']).is_relative_to(f.p['root']))

    def test_inflight_writer_finishes_before_backup_and_second_controller_cannot_overlap(self):
        import threading
        import concurrent.futures
        f = Installation(); self.addCleanup(f.close)
        admitted = threading.Event(); release = threading.Event(); capturing = threading.Event(); finish = threading.Event()
        image = f.p['targets']['storage'] / 'app/private/occurrence-media/11111111-1111-4111-8111-111111111111.png'
        def writer():
            with barrier(f.p['base'], 'operational'):
                admitted.set(); self.assertTrue(release.wait(15))
                with database(f.p['targets']['database'], write=True) as db: db.execute("UPDATE vehicles SET vehicle_owner_name='In Flight Owner'")
                image.write_bytes(image.read_bytes() + b'fixture-finished')
        def checkpoint(point):
            if point == 'capture:storage': capturing.set(); self.assertTrue(finish.wait(15))
        f.recovery.checkpoint = checkpoint
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            write = pool.submit(writer); self.assertTrue(admitted.wait(5))
            backup = pool.submit(f.backup)
            try:
                deadline = time.monotonic() + 5
                while phase(f.p['base'])['phase'] == 'running' and time.monotonic() < deadline: time.sleep(.01)
                self.assertEqual('quiescing', phase(f.p['base'])['phase']); self.assertFalse(backup.done())
                release.set(); write.result(10); self.assertTrue(capturing.wait(10))
                with self.assertRaises(ValueError): f.recovery.backup(str(uuid.uuid4()))
            finally: release.set(); finish.set()
            result = backup.result(15)
        with f.recovery.scratch() as tmp:
            inspect_bundle(result, tmp / 'bundle')
            with database(tmp / 'bundle/payload/database/data') as db:
                self.assertEqual('In Flight Owner', db.execute('SELECT vehicle_owner_name FROM vehicles').fetchone()[0])
            self.assertEqual(image.read_bytes(), (tmp / 'bundle/payload/storage/app/private/occurrence-media' / image.name).read_bytes())


if __name__ == '__main__': unittest.main()
