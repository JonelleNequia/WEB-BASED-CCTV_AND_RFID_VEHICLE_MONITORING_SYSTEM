import copy
import json
import multiprocessing
import os
from pathlib import Path
import signal
import unittest
from unittest.mock import patch
from fixture import Fixture
from backup_contract import database,load,tree_hash
from coordination import phase,atomic
from install import UpdateRecovery
from manager import Manager
from recovery import Recovery
from service_host import allowed


def kill_update(f,candidate,confirmation,point):
    def checkpoint(name):
        if name==point:os.kill(os.getpid(),signal.SIGKILL)
    f.update.checkpoint=checkpoint
    f.update.install(candidate,confirmation,True)

class Install(unittest.TestCase):
    def fixture(self,windows=False):
        f=Fixture(windows);self.addCleanup(f.close);return f
    def candidate(self,f,failing=False):
        manifest,archive=f.package(failing);candidate=f.manager.import_local(manifest,archive)['candidate'];return candidate,f.update.plan(candidate)
    def stopped(self,f):
        self.assertNotEqual('running',phase(f.p['base'])['phase']);self.assertFalse(allowed(f.p['base'],'detector'))
        if phase(f.p['base'])['phase']!='resuming':self.assertFalse(allowed(f.p['base'],'web'))
    def operation(self,f):return phase(f.p['base'])['operation']
    def rollback(self,f,operation):
        f.adapter.failure=None;f.update.checkpoint=lambda _:None
        f.update.recover(operation,'rollback');f.update.accept(operation);f.update.resume(operation)
        self.assertEqual('1.0.0',f.adapter.source_binding()['version']);self.assertEqual('running',phase(f.p['base'])['phase'])
        with database(f.p['targets']['database']) as db:self.assertIsNone(db.execute("SELECT 1 FROM sqlite_master WHERE name='d5c_fixture_feature'").fetchone())

    def test_real_application_schema_runtime_update_and_lost_ack_replay(self):
        f=self.fixture();operation_id=f.f.box.enqueue('crossing',f.f.php()['payload'])
        with f.f.box.db() as db:row=dict(db.execute('SELECT * FROM operations WHERE id=?',(operation_id,)).fetchone())
        packet={'envelope':row['envelope'],'sha256':row['sha'],'media':json.loads(row['media'])}
        ack=f.f.php('request',packet);self.assertEqual(200,ack['status']);before=f.f.php()
        protected={r:tree_hash(f.p['targets'][r]) for r in ('environment','tls','network','tls_identity','public_root')}
        candidate,plan=self.candidate(f);result=f.update.install(candidate,plan['confirmation'],True)
        self.assertTrue(result['installed']);self.assertEqual('1.1.0',f.adapter.source_binding()['version'])
        self.assertEqual({'feature':0,'php':'8.4.24'},f.target_response())
        retry=f.target_response('/api/v1/integration/deliveries',packet);self.assertTrue(retry['body']['duplicate']);self.assertEqual(ack['body']['delivery_ack'],retry['body']['delivery_ack'])
        after=f.f.php();self.assertEqual((before['events'],before['entries'],before['receipts'],before['context']),(after['events'],after['entries'],after['receipts'],after['context']))
        self.assertEqual('pending',f.f.box.inspect(operation_id)['state']);self.assertEqual(protected,{r:tree_hash(f.p['targets'][r]) for r in protected})
        self.assertEqual('running',phase(f.p['base'])['phase'])
        with self.assertRaises(ValueError):f.update.recover(result['operation'],'rollback')

    def test_readiness_failure_rolls_back_real_program_runtime_and_schema(self):
        f=self.fixture();before=f.f.php();candidate,plan=self.candidate(f);f.adapter.failure='ready'
        with self.assertRaises(ValueError):f.update.install(candidate,plan['confirmation'],True)
        operation=self.operation(f);self.stopped(f);self.assertEqual('1.1.0',f.adapter.source_binding()['version'])
        # New application's boot path is unusable; the retained Python/PHP controller
        # still restores the original compatible program, runtime and staged data.
        with patch.object(f.adapter,'ready',side_effect=ValueError('New app cannot boot')):
            f.update.recover(operation,'rollback');f.update.accept(operation)
        f.adapter.failure=None;f.update.resume(operation)
        self.assertEqual('1.0.0',f.adapter.source_binding()['version']);self.assertEqual(before['events'],f.f.php()['events'])
        self.assertEqual('1.0',(f.p['app']/'program-version.txt').read_text())
        with database(f.p['targets']['database']) as db:self.assertIsNone(db.execute("SELECT 1 FROM sqlite_master WHERE name='d5c_fixture_feature'").fetchone())

    def test_real_target_migration_failure_retains_original_and_current_recovery(self):
        f=self.fixture();candidate,plan=self.candidate(f,True);before=f.f.php()
        with self.assertRaises(ValueError):f.update.install(candidate,plan['confirmation'],True)
        operation=self.operation(f);self.stopped(f);self.assertEqual('1.0.0',f.adapter.source_binding()['version']);self.assertEqual(before,f.f.php())
        f.update.recover(operation,'current');f.update.resume(operation);self.assertEqual('running',phase(f.p['base'])['phase'])

    def test_disk_full_stale_approval_and_staging_replacement_do_not_enter_maintenance(self):
        f=self.fixture();candidate,plan=self.candidate(f)
        with self.assertRaises(ValueError):f.update.install(candidate,'0'*64,True)
        f.adapter.failure='disk'
        with self.assertRaises(OSError):f.update.install(candidate,plan['confirmation'],True)
        f.adapter.failure=None;value=f.store.read();archive=f.store.base.parent/'update-cache'/candidate/value['candidate']['manifest']['signed']['package']['filename'];archive.write_bytes(archive.read_bytes()[:123])
        with self.assertRaises(ValueError):f.update.install(candidate,plan['confirmation'],True)
        self.assertEqual('running',phase(f.p['base'])['phase'])

    def test_permanent_uid_security_accounts_devices_and_private_media_survive_update(self):
        f=self.fixture()
        with database(f.p['targets']['database'],write=True) as db:
            db.execute("INSERT INTO unavailable_rfid_uids(uid,marked_at,previous_status) VALUES('0000CCDD',CURRENT_TIMESTAMP,'available')")
            db.execute("UPDATE users SET account_status='disabled',session_version=12,remember_token='stale'")
            db.execute("UPDATE account_security_state SET version=10,setup_attempts=9")
            db.execute("UPDATE cameras SET calibration_mask_json='[{\"x\":0.1,\"y\":0.2},{\"x\":0.9,\"y\":0.2},{\"x\":0.9,\"y\":0.8}]'")
            saved={t:[dict(r) for r in db.execute('SELECT * FROM '+t)] for t in ('gates','cameras','vehicle_crossings','visitor_records','vehicle_events')}
        media=tree_hash(f.p['targets']['storage']/'app/private');candidate,plan=self.candidate(f)
        f.update.install(candidate,plan['confirmation'],True)
        with database(f.p['targets']['database']) as db:
            user=db.execute('SELECT * FROM users').fetchone();self.assertEqual('disabled',user['account_status']);self.assertGreater(user['session_version'],12);self.assertIsNone(user['remember_token'])
            self.assertEqual('0000CCDD',db.execute('SELECT uid FROM unavailable_rfid_uids').fetchone()[0]);self.assertIsNotNone(db.execute('SELECT setup_completed_at FROM account_security_state').fetchone()[0])
            for t,rows in saved.items():self.assertEqual(rows,[dict(r) for r in db.execute('SELECT * FROM '+t)])
        self.assertEqual(media,tree_hash(f.p['targets']['storage']/'app/private'));self.assertEqual(1,f.store.read()['trust']['generation'])

    def test_interrupted_early_data_preparation_restores_prior_program_and_epoch(self):
        f=self.fixture();candidate,plan=self.candidate(f)
        def checkpoint(point):
            if point=='journal:prepared':raise OSError('Prepared data intent persisted before program plan')
        f.update.checkpoint=checkpoint
        with self.assertRaises(OSError):f.update.install(candidate,plan['confirmation'],True)
        operation=self.operation(f);self.stopped(f);self.rollback(f,operation)

    def test_security_change_requires_renewed_approval(self):
        f=self.fixture();candidate,plan=self.candidate(f)
        with database(f.p['targets']['database'],write=True) as db:db.execute('UPDATE users SET session_version=session_version+1')
        with self.assertRaises(ValueError):f.update.install(candidate,plan['confirmation'],True)
        self.assertEqual('running',phase(f.p['base'])['phase'])

    def test_windows_program_locked_during_promotion_then_complete_rollback(self):
        f=self.fixture(True);candidate,plan=self.candidate(f);rename=os.rename
        def locked(source,target):
            if Path(source)==f.p['root']/'app':raise PermissionError('Synthetic Windows locked executable')
            return rename(source,target)
        with patch('install.os.rename',side_effect=locked):
            with self.assertRaises(PermissionError):f.update.install(candidate,plan['confirmation'],True)
        operation=self.operation(f);self.stopped(f);self.rollback(f,operation)

    def test_windows_process_kill_after_app_move_recovers_missing_active_app(self):
        f=self.fixture(True);candidate,plan=self.candidate(f)
        process=multiprocessing.get_context('fork').Process(target=kill_update,args=(f,candidate,plan['confirmation'],'program:old:app'));process.start();process.join(150)
        if process.is_alive():process.kill();process.join();self.fail('Synthetic Windows app-move boundary deadline')
        self.assertEqual(-signal.SIGKILL,process.exitcode);operation=self.operation(f);self.assertFalse(f.p['app'].exists());self.stopped(f)
        oldapp=Path(f.update.journal(operation)['program_moves'][next(i for i,m in enumerate(f.update.journal(operation)['program_moves']) if m['role']=='app')]['old'])
        (oldapp/'database/database.sqlite-wal').write_bytes(b'synthetic stale sidecar in retained prior app')
        f.update.ctl.recover(operation);self.rollback(f,operation);self.assertEqual('1.0',(f.p['app']/'program-version.txt').read_text())
        self.assertFalse((f.p['app']/'database/database.sqlite-wal').exists())

    def test_failed_rollback_and_untrusted_release_bytes_keep_fence(self):
        f=self.fixture();candidate,plan=self.candidate(f);f.adapter.failure='ready'
        with self.assertRaises(ValueError):f.update.install(candidate,plan['confirmation'],True)
        operation=self.operation(f);journal=f.update.journal(operation)
        old=Path(journal['program_moves'][0]['old_hash'])/'app/program-version.txt';saved=old.read_text();old.write_text('tampered')
        with self.assertRaises(ValueError):f.update.recover(operation,'rollback')
        self.stopped(f);old.write_text(saved);self.rollback(f,operation)

    def test_killed_between_migration_program_data_and_startup_recovers_from_retained_journal(self):
        for point,decision in [('update:migrated','current'),('program:new:current','rollback'),('promote:new_installed:database','forward'),('update:before-writer-reopen','rollback')]:
            with self.subTest(point=point):
                f=self.fixture();candidate,plan=self.candidate(f)
                process=multiprocessing.get_context('fork').Process(target=kill_update,args=(f,candidate,plan['confirmation'],point));process.start();process.join(120)
                if process.is_alive():process.kill();process.join();self.fail('Synthetic update exceeded deadline')
                self.assertEqual(-signal.SIGKILL,process.exitcode);operation=self.operation(f);self.stopped(f)
                f.update.ctl.recover(operation)
                # Reconstruct the controller object as a newly invoked recovery process.
                f.update=UpdateRecovery(f.adapter,Manager(f.adapter));f.update.recover(operation,decision)
                if decision!='current':f.update.accept(operation)
                f.update.resume(operation);self.assertEqual('running',phase(f.p['base'])['phase'])
                self.assertEqual('1.1.0' if decision=='forward' else '1.0.0',f.adapter.source_binding()['version'])

    def test_additive_d5b_schema_migration_on_copy_preserves_existing_tables(self):
        f=self.fixture();payload=f.directory/'additive/payload'
        with database(f.p['targets']['database']) as db:original_marker=tuple(db.execute('SELECT epoch,active_set FROM recovery_state').fetchone())
        (payload/'database').mkdir(parents=True);(payload/'environment').mkdir();(payload/'storage').mkdir()
        import shutil
        shutil.copy2(f.p['targets']['database'],payload/'database/data');shutil.copy2(f.p['targets']['environment'],payload/'environment/data')
        with database(payload/'database/data',write=True) as db:
            db.execute('DROP TABLE recovery_state');db.execute("DELETE FROM migrations WHERE migration='2026_11_20_000001_create_recovery_state'")
            before={r[0]:[tuple(x) for x in db.execute('SELECT * FROM "'+r[0]+'"')] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('migrations','sqlite_sequence')")}
        f.adapter.migrate(f.p['release'],payload,{})
        with database(payload/'database/data') as db:
            for table,rows in before.items():self.assertEqual(rows,[tuple(x) for x in db.execute('SELECT * FROM "'+table+'"')])
            self.assertEqual((0,'00000000-0000-0000-0000-000000000000'),tuple(db.execute('SELECT epoch,active_set FROM recovery_state').fetchone()))
        # This does not invent protected D5B state for a pre-D5B live origin.
        with database(f.p['targets']['database']) as db:self.assertEqual(original_marker,tuple(db.execute('SELECT epoch,active_set FROM recovery_state').fetchone()))

    def test_generic_d5b_commands_cannot_accept_or_resume_an_update(self):
        f=self.fixture();candidate,plan=self.candidate(f);f.adapter.failure='ready'
        with self.assertRaises(ValueError):f.update.install(candidate,plan['confirmation'],True)
        operation=self.operation(f);plain=Recovery(f.adapter)
        for action in (lambda:plain.recover(operation,'rollback'),lambda:plain.accept(operation),lambda:plain.resume(operation)):
            with self.assertRaises(ValueError):action()
        self.stopped(f)

if __name__=='__main__':unittest.main()
