import importlib.util,json,sys,tempfile,unittest,zipfile,hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('delivery_review',ROOT/'build/delivery/review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class Review(unittest.TestCase):
    def helper(self,root,platform='Mac',source='a'*64):
        path=root/('Connect-'+platform+'.zip');data=b'public helper';h=hashlib.sha256(data).hexdigest()
        info={'platform':platform,'version':'1.0.0-rc.1','channel':'development','source':{'commit':'b'*40,'sha256':source,'build_identity':'synthetic-d6'}}
        manifest={'Connect.command':{'bytes':len(data),'sha256':h}}
        with zipfile.ZipFile(path,'w') as z:
            z.writestr('Connect-to-PHILCST/HELPER-INFO.json',json.dumps(info));z.writestr('Connect-to-PHILCST/CONTENT-MANIFEST.json',json.dumps(manifest));z.writestr('Connect-to-PHILCST/Connect.command',data)
        return path
    def test_runtime_contract_module_cannot_shadow_the_build_validation_contract(self):
        from unittest.mock import patch
        import types
        with patch.dict(sys.modules, {'contract': types.ModuleType('synthetic_runtime_contract')}):
            spec=importlib.util.spec_from_file_location('isolated_delivery_review',ROOT/'build/delivery/review.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
            with tempfile.TemporaryDirectory() as d:
                self.assertEqual('client-Mac',module.inventory([self.helper(Path(d))])['artifacts'][0]['platform'])

    def test_partial_delivery_is_visible_and_never_certifies_native_acceptance(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.helper(Path(d));value=r.inventory([p]);self.assertFalse(value['complete_matrix']);self.assertIn('windows-x64',value['missing']);ledger=r.acceptance(value);self.assertTrue(all(x['status']=='BLOCKED' for x in ledger['results'] if x['platform'] in r.SERVERS));self.assertEqual('not performed',value['publication'])
    def test_mixed_helper_source_or_duplicate_platform_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d);a=self.helper(p);b=self.helper(p,'Windows','c'*64)
            with self.assertRaises(ValueError):r.inventory([a,b])
            with self.assertRaises(ValueError):r.inventory([a,a])
    def test_unexpected_private_files_are_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.helper(Path(d))
            with zipfile.ZipFile(p,'a') as z:z.writestr('Connect-to-PHILCST/private.key','synthetic forbidden key')
            with self.assertRaises(ValueError):r.inventory([p])
    def test_acceptance_is_bound_to_actual_artifact_and_evidence_bytes(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.helper(Path(d));e=Path(d)/'evidence.txt';e.write_text('Synthetic fixture evidence');ledger=r.acceptance(r.inventory([p]));r.record(ledger,'client-Mac','fixture','PASS',e,'synthetic test','fixture OS','fixture',False);self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),ledger['results'][-1]['artifact_sha256']);p.write_bytes(b'changed')
            with self.assertRaises(ValueError):r.record(ledger,'client-Mac','fixture','PASS',e,'synthetic test','fixture OS','fixture')
    def test_missing_evidence_or_invalid_status_never_passes(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.helper(Path(d));ledger=r.acceptance(r.inventory([p]))
            with self.assertRaises(ValueError):r.record(ledger,'client-Mac','fixture','GREEN',p,'test','OS','fixture')
    def test_frozen_frontend_cannot_skip_production_vendor_templates(self):
        spec=importlib.util.spec_from_file_location('delivery_frontend',ROOT/'build/delivery/frontend.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(ValueError):module.validate(Path(d))
            path=Path(d)/'vendor/laravel/framework/src/Illuminate/Pagination/resources/views/tailwind.blade.php';path.parent.mkdir(parents=True);path.write_text('synthetic pagination template')
            self.assertEqual(path,module.validate(Path(d)))

    def test_native_install_hooks_do_not_ship_fixture_trust_and_uninstall_preflight_precedes_removal(self):
        mac=(ROOT/'deploy/macos/control.py').read_text();win=(ROOT/'deploy/windows/install.ps1').read_text();iss=(ROOT/'build/windows/installer/PHILCST-VMS.iss').read_text()
        for source in (mac,win):
            self.assertIn('fresh_setup.py',source);self.assertNotIn("['storage:link']",source);self.assertNotIn("@('storage:link')",source)
        self.assertIn('-CheckOnly',iss);self.assertIn('Result := False; exit;',iss)
        self.assertEqual([],json.loads((ROOT/'deploy/transfer/compatibility.json').read_text())['paths'])
if __name__=='__main__':unittest.main()
