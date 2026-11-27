"""Actual capsule identity handoff; native OS services remain private adapters."""
import importlib.util,sys,unittest,shutil
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('d6_transfer_pair',ROOT/'tests/transfer/fixture.py');pair=importlib.util.module_from_spec(spec);spec.loader.exec_module(pair)
from backup_contract import load,file_sha
from coordination import atomic,identity
from native import NativeUpdate,copy_program
NativeTransfer=pair.nt.NativeTransfer
class RetainedAdapter(pair.Adapter,NativeTransfer):pass
class RetainedTransfer(unittest.TestCase):
    def fixture(self):
        f=pair.Pair();self.addCleanup(f.close)
        for a in (f.source,f.target):
            a.__class__=RetainedAdapter
            copy_program(ROOT/'deploy',a.p['app']/'deploy')
            shutil.copy2(ROOT/'build/updates/contract.py',a.p['app']/'deploy/lifecycle/update_contract.py');shutil.copy2(ROOT/'build/updates/signature.php',a.p['app']/'deploy/lifecycle/signature.php')
            a.p['ini'].parent.mkdir(parents=True,exist_ok=True);a.p['ini'].write_text('; private fixture ini\n')
            manifest=load(a.p['release']/'CONTENT-MANIFEST.json');manifest['files']={p.relative_to(a.p['release']).as_posix():{'bytes':p.stat().st_size,'sha256':file_sha(p)} for component in ('app','runtime') for p in (a.p['release']/component).rglob('*') if p.is_file() and not p.is_symlink() and not p.relative_to(a.p['release']).as_posix().startswith(('app/storage/','app/bootstrap/cache/')) and p.name!='.env'};atomic(a.p['release']/'CONTENT-MANIFEST.json',manifest)
            NativeUpdate.bootstrap_controller(a,a.source_binding()['source_sha256'])
        return f
    def test_transfer_rebinds_only_existing_authenticated_target_controller(self):
        f=self.fixture();old=load(f.target.p['base'].parent/'update-controller.json');path=NativeUpdate.capsule(f.target);f.activate();new=load(f.target.p['base'].parent/'update-controller.json')
        self.assertEqual((old['id'],old['sha256']),(new['id'],new['sha256']));self.assertEqual(identity(f.source.p['base']),new['installation']);self.assertEqual(path,NativeUpdate.capsule(f.target))
    def test_partial_controller_handoff_requires_exact_signed_d5d_context(self):
        f=self.fixture();result=f.prepared();f.t.import_set(f.archive,pair.sha(pair.canonical(result['manifest'])))
        with patch.object(f.target,'finish_transfer_controller',side_effect=RuntimeError('Synthetic crash before controller receipt')):
            with self.assertRaises(RuntimeError):f.t.promote()
        with self.assertRaises(ValueError):NativeUpdate.capsule(f.target)
        self.assertTrue(f.target.capsule().is_dir())
        state=f.t.state();bad=__import__('copy').deepcopy(state);bad['import_manifest']['signature']='invalid';f.t.save(bad)
        with self.assertRaises(ValueError):f.target.capsule()
        f.t.save(state);f.t.promote();self.assertTrue(NativeUpdate.capsule(f.target).is_dir())
if __name__=='__main__':unittest.main()
