"""Fresh D6 finalization on synthetic DB/runtime/service layouts only."""
import importlib.util,json,shutil,sys,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'deploy/lifecycle'),str(ROOT/'deploy/updates')]
spec=importlib.util.spec_from_file_location('d6_up_fixture',ROOT/'tests/updater/fixture.py');up=importlib.util.module_from_spec(spec);spec.loader.exec_module(up)
from fresh_setup import FreshSetup
from coordination import atomic,phase,identity,admission
from backup_contract import database,load,file_sha,tree_hash
from native import NativeUpdate,copy_program
from operator_control import control

class Adapter(up.Adapter):
    capsule=NativeUpdate.capsule
    bootstrap_controller=NativeUpdate.bootstrap_controller

class Fresh(unittest.TestCase):
    def fixture(self,windows=False):
        f=up.Fixture(windows);self.addCleanup(f.close)
        a=Adapter(f.p)
        f.p['ini'].parent.mkdir(parents=True,exist_ok=True);f.p['ini'].write_text('; synthetic fixture ini\n')
        # Model a fresh InstallSeeder DB, preserving the already initialized D5A
        # identity/schema. All removals below affect this disposable fixture only.
        import sqlite3
        db=sqlite3.connect(f.p['targets']['database']);db.execute('PRAGMA foreign_keys=OFF')
        for table in ('users','vehicles','vehicle_rfid_tags','vehicle_events','visitor_records','vehicle_crossings','rfid_scan_logs','guest_vehicle_observations','security_events','sessions','delivery_receipts'):db.execute('DELETE FROM '+table)
        db.execute('UPDATE account_security_state SET setup_completed_at=NULL');db.commit();db.close()
        for name in ('recovery-security.json','recovery-guard.json'):(f.p['base']/name).unlink()
        shutil.rmtree(f.p['recovery']);shutil.rmtree(f.p['targets']['update_trust'])
        (f.p['base'].parent/'update-status.json').unlink(missing_ok=True)
        state=phase(f.p['base']);state.pop('recovery_required',None);atomic(f.p['base']/'maintenance.json',state)
        copy_program(ROOT/'deploy',f.p['app']/'deploy')
        shutil.copy2(ROOT/'build/updates/contract.py',f.p['app']/'deploy/lifecycle/update_contract.py')
        shutil.copy2(ROOT/'build/updates/signature.php',f.p['app']/'deploy/lifecycle/signature.php')
        manifest=load(f.p['release']/'CONTENT-MANIFEST.json')
        manifest['files']={p.relative_to(f.p['release']).as_posix():{'bytes':p.stat().st_size,'sha256':file_sha(p)} for component in ('app','runtime') for p in (f.p['release']/component).rglob('*') if p.is_file() and not p.is_symlink() and not p.relative_to(f.p['release']).as_posix().startswith(('app/storage/','app/bootstrap/cache/')) and p.name!='.env'}
        atomic(f.p['release']/'CONTENT-MANIFEST.json',manifest)
        return f,a,FreshSetup(a)

    def test_complete_fresh_registration_retains_identity_and_does_not_invent_publisher_trust(self):
        f,a,c=self.fixture();before=identity(f.p['base']);env=f.p['targets']['environment'].read_bytes()
        self.assertEqual('complete',c.run(a.source_binding()['source_sha256'])['fresh_setup'])
        self.assertEqual(before,identity(f.p['base']));self.assertEqual(env,f.p['targets']['environment'].read_bytes());self.assertTrue(admission(f.p['base']))
        self.assertFalse(load(f.p['base'].parent/'update-status.json')['provisioned']);self.assertFalse((f.p['targets']['update_trust']/'trust.json').exists())
        self.assertTrue((a.capsule()/'app/deploy/transfer/control.py').exists())
        with database(f.p['targets']['database']) as db:self.assertEqual(0,db.execute('SELECT COUNT(*) FROM users').fetchone()[0])

    def test_completed_reentry_is_read_only_and_wrong_source_is_refused(self):
        f,a,c=self.fixture();c.run(a.source_binding()['source_sha256']);before=tree_hash(f.p['base']);c.run(a.source_binding()['source_sha256']);self.assertEqual(before,tree_hash(f.p['base']))
        with self.assertRaises(ValueError):c.run('0'*64)

    def test_known_interruptions_resume_without_replacing_keys_or_owner(self):
        for point in ('controller','recovery','prepared'):
            with self.subTest(point=point):
                f,a,c=self.fixture();c.checkpoint=lambda step:(_ for _ in ()).throw(RuntimeError('Synthetic interruption')) if step==point else None
                with self.assertRaises(RuntimeError):c.run(a.source_binding()['source_sha256'])
                self.assertFalse(admission(f.p['base']));c.checkpoint=lambda _:None
                c.run(a.source_binding()['source_sha256']);self.assertTrue(admission(f.p['base']));self.assertEqual(load(c.path)['owner'],load(f.p['base']/'ownership.json')['owner'])

    def test_foreign_or_existing_protected_state_is_never_adopted(self):
        f,a,c=self.fixture();atomic(f.p['base']/'recovery-security.json',{'unexpected':True})
        with self.assertRaises(ValueError):c.run(a.source_binding()['source_sha256'])
        self.assertFalse(c.path.exists())

    def test_missing_completed_owner_is_not_repaired_and_operator_cannot_resume_retired(self):
        f,a,c=self.fixture();c.run(a.source_binding()['source_sha256']);owner=load(f.p['base']/'ownership.json');atomic(f.p['base']/'ownership.json',dict(owner,state='retired'))
        with self.assertRaises(ValueError):control(a,'start')
        with self.assertRaises(ValueError):c.verify()
        (f.p['base']/'ownership.json').unlink()
        with self.assertRaises(ValueError):c.run(a.source_binding()['source_sha256'])

    def test_windows_layout_and_owned_stop_start_share_the_same_proof(self):
        f,a,c=self.fixture(True);c.run(a.source_binding()['source_sha256']);control(a,'stop');self.assertFalse(admission(f.p['base']));control(a,'start');self.assertTrue(admission(f.p['base']))

if __name__=='__main__':unittest.main()
