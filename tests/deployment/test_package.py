import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('vms_package', ROOT/'build/windows/package.py')
pkg = importlib.util.module_from_spec(spec);spec.loader.exec_module(pkg)


class PackageBoundary(unittest.TestCase):
    def test_source_exclusions(self):
        for name in ['.env', '.env.backup', 'storage/backups/data.sqlite', 'storage/app/camera/config.json', 'public/storage/x.jpg', 'x/.venv/bin/python', 'first-admin.txt',
                     'state/lifecycle/identity.json','shared/lifecycle/maintenance.json','any/lifecycle/operation.lock','any/lifecycle/delivery/outbox.sqlite-journal']:
            self.assertFalse(pkg.source_allowed(name), name)
        for name in ['.env.example', 'public/vendor/hls/hls.light.min.js', 'build/windows/runtimes.json', 'tests/Feature/X.php', 'storage/framework/sessions/.gitignore','deploy/lifecycle/maintenance.py','deploy/lifecycle/coordination.py']:
            self.assertTrue(pkg.source_allowed(name), name)

    def test_stage_keeps_local_browser_vendor_assets_and_license(self):
        with tempfile.TemporaryDirectory() as d:
            source=Path(d)/'source';stage=Path(d)/'stage';(source/'build/windows/checks').mkdir(parents=True)
            files={}
            for name,body in [('public/vendor/hls/hls.light.min.js','local browser fixture'),('public/vendor/hls/LICENSE','retained notice')]:
                f=source/name;f.parent.mkdir(parents=True,exist_ok=True);f.write_text(body);files[name]=pkg.sha(f)
                self.assertTrue(pkg.source_allowed(name))
            (source/'SOURCE-INFO.json').write_text(json.dumps({'files':files}))
            pkg.stage_source(source,stage)
            self.assertEqual((stage/'app/public/vendor/hls/hls.light.min.js').read_text(),'local browser fixture')
            self.assertEqual((stage/'app/public/vendor/hls/LICENSE').read_text(),'retained notice')

    def test_manifest_verifies_extracted_bytes_and_rejects_mutation(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)/'PHILCST-VMS';(root/'app/public').mkdir(parents=True)
            (root/'app/public/index.php').write_text('<?php echo 1;')
            pkg.manifest(root);self.assertEqual(pkg.verify(root),1)
            archive=Path(d)/'kit.zip'
            with zipfile.ZipFile(archive,'w') as z:
                for f in root.rglob('*'):
                    if f.is_file():z.write(f,'PHILCST-VMS/'+f.relative_to(root).as_posix())
            pkg.extract(archive,Path(d)/'out');self.assertEqual(pkg.verify(Path(d)/'out/PHILCST-VMS'),1)
            (root/'app/public/index.php').write_text('corrupt')
            with self.assertRaisesRegex(ValueError,'Missing or corrupt'):pkg.verify(root)

    def test_extra_file_and_missing_dependency_refused(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'runtime').mkdir();(root/'runtime/model').write_bytes(b'fixture')
            pkg.manifest(root);(root/'extra').write_text('unexpected')
            with self.assertRaisesRegex(ValueError,'Unexpected'):pkg.verify(root)
            (root/'extra').unlink();(root/'runtime/model').unlink()
            with self.assertRaisesRegex(ValueError,'Missing or corrupt'):pkg.verify(root)

    def test_assembled_boundary_rejects_installation_data_and_private_paths(self):
        for name,body in [('app/.env','APP_KEY=secret'),('app/storage/app/media.jpg','x'),('app/database/x.sqlite','x'),('runtime/config.json','{"python":"/Users/fixture/dev/.venv/bin/python"}')]:
            with tempfile.TemporaryDirectory() as d:
                f=Path(d)/name;f.parent.mkdir(parents=True);f.write_text(body)
                with self.assertRaises(ValueError):pkg.scan(d)

    def test_zip_slip_and_link_refused(self):
        for name in ['../x','/absolute','C:/outside','PHILCST-VMS/../../x','PHILCST-VMS/..\\x']:
            with tempfile.TemporaryDirectory() as d:
                z=Path(d)/'bad.zip'
                with zipfile.ZipFile(z,'w') as f:f.writestr(name,'x')
                with self.assertRaises(ValueError):pkg.extract(z,Path(d)/'out')

    def test_windows_archive_case_collision_refused(self):
        with tempfile.TemporaryDirectory() as d:
            archive=Path(d)/'collision.zip'
            with zipfile.ZipFile(archive,'w') as z:
                z.writestr('Runtime/File.dll','first');z.writestr('runtime/file.DLL','second')
            with self.assertRaisesRegex(ValueError,'Duplicate Windows'):pkg.extract(archive,Path(d)/'out')

    def test_dirty_release_refused_and_development_is_frozen(self):
        with tempfile.TemporaryDirectory() as d:
            repo=Path(d)/'source';repo.mkdir()
            def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL).decode().strip()
            git('init','-q');git('config','user.email','fixture@example.invalid');git('config','user.name','Isolated fixture')
            (repo/'app.txt').write_text('first');git('add','app.txt');git('commit','-qm','synthetic fixture only')
            commit=git('rev-parse','HEAD');(repo/'app.txt').write_text('uncommitted fix')
            (repo/'.env').write_text('PRIVATE fixture should never be copied')
            with self.assertRaisesRegex(ValueError,'Dirty'):pkg.source_snapshot(repo,Path(d)/'release',commit,False)
            info=pkg.source_snapshot(repo,Path(d)/'dev',None,True)
            self.assertEqual(info['channel'],'development');self.assertEqual((Path(d)/'dev/app.txt').read_text(),'uncommitted fix')
            self.assertFalse((Path(d)/'dev/.env').exists());self.assertEqual(info['files']['app.txt'],pkg.sha(Path(d)/'dev/app.txt'))
            self.assertIn('?? .env',git('status','--porcelain'))
            (repo/'app.txt').write_text('another uncommitted change')
            later=pkg.source_snapshot(repo,Path(d)/'dev-later',None,True)
            self.assertNotEqual(info['source_sha256'],later['source_sha256'])

    def test_dependency_closure_checks_windows_markers_and_missing_transitives(self):
        with tempfile.TemporaryDirectory() as d:
            wheel=Path(d)/'parent.whl'
            with zipfile.ZipFile(wheel,'w') as z:
                z.writestr('parent-1.dist-info/METADATA','Name: parent\nVersion: 1\nLicense: Caf=C3=A9\nRequires-Dist: child>=2; sys_platform == "win32"\n')
            with self.assertRaisesRegex(ValueError,'dependency closure failed'):pkg.closure(d,Path(d)/'result.json')
            with zipfile.ZipFile(Path(d)/'child.whl','w') as z:z.writestr('child-2.dist-info/METADATA','Name: child\nVersion: 2\n')
            pkg.closure(d,Path(d)/'result.json')
            self.assertEqual(json.loads((Path(d)/'result.json').read_text())['closure'],'passed')

    def test_every_download_has_sha_and_vc_publisher(self):
        data=json.loads((ROOT/'build/windows/runtimes.json').read_text())
        for group in ['runtimes','models','fixtures','notices']:
            for name,entry in data[group].items():
                self.assertRegex(entry['sha256'],r'^[a-f0-9]{64}$',name)
                self.assertNotIn('aka.ms',entry['urls'][0])
        self.assertEqual(data['runtimes']['vc_redist']['signed_by'],'Microsoft Corporation')
        self.assertIn('/archives/',data['runtimes']['php']['urls'][0])

    def test_license_inventory_includes_frontend_and_copies_its_notice(self):
        with tempfile.TemporaryDirectory() as d:
            stage=Path(d)/'stage';source=Path(d)/'source';(stage/'app').mkdir(parents=True)
            notice=source/'node_modules/example/LICENSE';notice.parent.mkdir(parents=True);notice.write_text('fixture notice')
            (source/'package-lock.json').write_text(json.dumps({'packages':{'':{},'node_modules/example':{'version':'1','license':'MIT'}}}))
            (stage/'app/composer.lock').write_text('{"packages":[]}');(stage/'PYTHON-DEPENDENCIES.json').write_text('{"packages":[]}');(stage/'RUNTIME-INPUTS.json').write_text('{}')
            import sys
            subprocess.run([sys.executable,str(ROOT/'build/windows/license-inventory.py'),str(stage),str(source)],check=True)
            inventory=json.loads((stage/'DEPENDENCY-LICENSES.json').read_text())
            self.assertEqual(inventory['frontend_and_build_dependencies'][0]['version'],'1')
            self.assertEqual((stage/'licenses/frontend/example/LICENSE').read_text(),'fixture notice')

    def test_packaged_cpu_and_tesseract_paths_are_explicit(self):
        import ast, os
        from unittest import mock
        # Execute the real small policy functions without starting detector/device threads.
        def function(file,name,scope):
            tree=ast.parse((ROOT/file).read_text());node=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name==name)
            node.decorator_list=[]
            exec(compile(ast.Module(body=[node],type_ignores=[]),str(file),'exec'),scope)
            return scope[name]
        device=function('school-vehicle-monitoring-detector/detector_service.py','yolo_device',{'os':os,'_YOLO_DEVICE':{'resolved':None}})
        with mock.patch.dict(os.environ,{'PHILCST_PACKAGED_CPU':'1'}):
            self.assertEqual(device('auto'),'cpu');self.assertEqual(device('cuda:0'),'cpu')
        with mock.patch.dict(os.environ,{'PHILCST_PACKAGED_CPU':'0'}):self.assertEqual(device('cuda:0'),'cuda:0')
        which=mock.Mock(return_value='unexpected-PATH-tesseract.exe')
        import types
        available=function('school-vehicle-monitoring-detector/anpr.py','tesseract_binary_available',{'os':os,'shutil':types.SimpleNamespace(which=which)})
        with mock.patch.dict(os.environ,{'PHILCST_PACKAGED_OFFLINE':'1'}):self.assertFalse(available())
        which.assert_not_called()
        with mock.patch.dict(os.environ,{'PHILCST_PACKAGED_OFFLINE':'0'}):self.assertTrue(available())

    def test_missing_packaged_model_fails_before_download(self):
        import ast,os
        from unittest import mock
        file=ROOT/'school-vehicle-monitoring-detector/detector_service.py';tree=ast.parse(file.read_text())
        node=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='offline_model_path')
        scope={'os':os,'Path':Path};exec(compile(ast.Module(body=[node],type_ignores=[]),str(file),'exec'),scope)
        with tempfile.TemporaryDirectory() as d, mock.patch.dict(os.environ,{'PHILCST_PACKAGED_OFFLINE':'1'}):
            path=Path(d)/'yolov8n.pt'
            with self.assertRaisesRegex(FileNotFoundError,'No download attempted'):scope['offline_model_path'](str(path))
            path.write_bytes(b'fixture');self.assertEqual(scope['offline_model_path'](str(path)),str(path))
        with mock.patch.dict(os.environ,{'PHILCST_PACKAGED_OFFLINE':'0'}):self.assertEqual(scope['offline_model_path']('development-missing.pt'),'development-missing.pt')

    def test_cpu_wheel_lock_and_optional_capability_contract(self):
        pins=json.loads((ROOT/'build/windows/wheels-win64.json').read_text())['packages']
        self.assertEqual(next(x['version'] for x in pins if x['name']=='lap'),'0.5.13')
        for name in ['torch','torchvision']:
            wheel=next(x for x in pins if x['name']==name)
            self.assertTrue(wheel['version'].endswith('+cpu'));self.assertIn('/cpu/',wheel['url'])
        self.assertIn('PHILCST_PACKAGED_OFFLINE',(ROOT/'school-vehicle-monitoring-detector/anpr.py').read_text())
        self.assertIn("iceServers: []",(ROOT/'public/js/live-video.js').read_text())


if __name__=='__main__':unittest.main()
