import hashlib
import importlib.util
import json
import os
from pathlib import Path
import plistlib
import struct
import tarfile
import tempfile
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[2]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
ctl=module('maccontrol','deploy/macos/control.py');rt=module('macruntime','build/macos/runtime.py');pkg=module('sharedpackage','build/windows/package.py')

class MacContracts(unittest.TestCase):
    def info(self):return {'architecture':'arm64','minimum_macos':'14.0','version':'1.0.0-dev.fixture','source':{'source_sha256':'fixture'}}
    def existing(self,root):
        shared=root/'shared';(shared/'database').mkdir(parents=True);(shared/'database/database.sqlite').write_bytes(b'synthetic fixture')
        (shared/'.env').write_text('DB_CONNECTION=sqlite\nDB_DATABASE='+str(shared/'database/database.sqlite')+'\nAPP_KEY=fixture\nDETECTOR_API_KEY=fixture\n')
        (shared/'installation.json').write_text(json.dumps({'source_sha256':'fixture','architecture':'arm64'}));return shared
    def test_platform_minimum_arch_and_elevation(self):
        ctl.platform_policy(self.info(),'Darwin','arm64','14.0',True,10*1024**3,1024)
        for system,arch,version,elevated in [('Linux','arm64','14',True),('Darwin','x86_64','14',True),('Darwin','arm64','13.9',True),('Darwin','arm64','14',False)]:
            with self.assertRaises(ValueError):ctl.platform_policy(self.info(),system,arch,version,elevated,10*1024**3,1024)
        with self.assertRaisesRegex(ValueError,'space'):ctl.platform_policy(self.info(),'Darwin','arm64','14',True,1024,1024)
    def test_local_storage_rejects_network_mapping(self):
        ctl.local_disk('/Library/Application Support/PHILCST-VMS','/dev/disk3s5 on / (apfs, local)')
        with self.assertRaisesRegex(ValueError,'local'):ctl.local_disk('/Volumes/School/VMS','/dev/disk3s5 on / (apfs, local)\n//server/share on /Volumes/School (smbfs, mounted)')
    def test_same_source_inspection_preserves_all_state_bytes(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);shared=self.existing(root);(shared/'media').write_bytes(b'historical');before={f:sha(f) for f in shared.rglob('*') if f.is_file()}
            self.assertEqual(ctl.existing_policy(root,self.info()),'same-source');self.assertEqual(before,{f:sha(f) for f in before})
    def test_legacy_and_changed_source_refuse(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'legacy').touch()
            with self.assertRaisesRegex(ValueError,'legacy'):ctl.existing_policy(root,self.info())
            self.existing(root);info=self.info();info['source']['source_sha256']='different'
            with self.assertRaisesRegex(ValueError,'D5'):ctl.existing_policy(root,info)
    def test_interrupted_provisioning_refuses_without_reopening_setup(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);shared=self.existing(root);marker=shared/'installation.json';data=json.loads(marker.read_text());data['provisioning']='initializing';marker.write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError,'Interrupted'):ctl.existing_policy(root,self.info())
            self.assertFalse((shared/'setup/first-admin.txt').exists())

    def test_missing_keys_database_non_sqlite_and_external_db_refuse(self):
        for change in ['missing-env','missing-db','mysql','external','key']:
            with tempfile.TemporaryDirectory() as d:
                root=Path(d);shared=self.existing(root);env=shared/'.env'
                if change=='missing-env':env.unlink()
                elif change=='missing-db':(shared/'database/database.sqlite').unlink()
                else:env.write_text(env.read_text().replace('DB_CONNECTION=sqlite','DB_CONNECTION=mysql') if change=='mysql' else env.read_text().replace(str(shared/'database/database.sqlite'),'/Volumes/remote/data.sqlite') if change=='external' else env.read_text().replace('APP_KEY=fixture','APP_KEY='))
                with self.assertRaises(ValueError):ctl.existing_policy(root,self.info())
    def test_existing_service_group_cannot_be_overwritten_by_account_creation(self):
        shared=Path('/Library/Application Support/PHILCST-VMS/shared')
        self.assertEqual(ctl.identity_record_policy(None,None,shared),'fresh')
        with self.assertRaisesRegex(ValueError,'Unowned'):ctl.identity_record_policy(None,'PrimaryGroupID: 450',shared)
        user='UniqueID: 450\nPrimaryGroupID: 450\nUserShell: /usr/bin/false\nNFSHomeDirectory: '+str(shared/'home')+'\n'
        self.assertEqual(ctl.identity_record_policy(user,'PrimaryGroupID: 450',shared),'owned')
        with self.assertRaises(ValueError):ctl.identity_record_policy(user,'PrimaryGroupID: 0',shared)
        with self.assertRaises(ValueError):ctl.identity_record_policy(user.replace('UniqueID: 450','UniqueID: 0'),'PrimaryGroupID: 450',shared)

    def test_state_symlink_redirect_refused(self):
        with tempfile.TemporaryDirectory() as d:
            (Path(d)/'link').symlink_to('/tmp')
            with self.assertRaisesRegex(ValueError,'symlink'):ctl.no_redirects(Path(d)/'link/database')
    def test_launchd_ownership_identities_and_local_environment(self):
        root=Path('/Library/Application Support/PHILCST-VMS');release=root/'releases/fixture';shared=root/'shared'
        for role in ctl.ROLES:
            p=ctl.service_plist(root,release,shared,role);self.assertTrue(ctl.plist_owned(plistlib.loads(plistlib.dumps(p)),root,role))
            self.assertEqual(p['UserName'],'root' if role in ['devices','web'] else ctl.SERVICE_USER)
            self.assertEqual(p['EnvironmentVariables']['MJPEG_STREAM_BIND_HOST'],'127.0.0.1');self.assertEqual(p['EnvironmentVariables']['YOLO_AUTOINSTALL'],'false')
            self.assertEqual(p['EnvironmentVariables']['PHILCST_PACKAGED_CPU'],'1')
        p=ctl.service_plist(root,release,shared,'web');p['ProgramArguments'][0]='/unrelated/python';self.assertFalse(ctl.plist_owned(p,root,'web'))
    def test_manifest_verifier_tamper_extra_hidden_and_missing(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);(root/'.hidden').write_bytes(b'fixture');pkg.manifest(root);ctl.verify(root)
            (root/'extra').touch()
            with self.assertRaisesRegex(ValueError,'Unexpected'):ctl.verify(root)
            (root/'extra').unlink();(root/'.hidden').write_bytes(b'tamper')
            with self.assertRaisesRegex(ValueError,'corrupt'):ctl.verify(root)
    def test_mac_inputs_are_pinned_and_architecture_specific(self):
        for arch,count in [('arm64',57),('x86_64',59)]:
            lock=json.loads((ROOT/f'build/macos/runtimes-{arch}.json').read_text());wheels=json.loads((ROOT/f'build/macos/wheels-{arch}.json').read_text())
            self.assertFalse(wheels['unresolved']);self.assertEqual(len(wheels['packages']),count)
            for entry in lock['php']['bottles']+[lock[x] for x in ['python','caddy','go2rtc']]+wheels['packages']:self.assertRegex(entry['sha256'],r'^[a-f0-9]{64}$')
            self.assertEqual(next(x['version'] for x in wheels['packages'] if x['name']=='torch'),'2.11.0' if arch=='arm64' else '2.2.2')
            self.assertIn('macosx',next(x['url'] for x in wheels['packages'] if x['name']=='torch'))
    def test_source_excludes_mac_artifacts_but_keeps_local_hls(self):
        self.assertFalse(pkg.source_allowed('build/macos/dist/PHILCST-VMS/runtime/file'));self.assertFalse(pkg.source_allowed('build/macos/cache/input'))
        self.assertTrue(pkg.source_allowed('public/vendor/hls/hls.light.min.js'))
    def test_mac_dependency_markers_require_native_dependency(self):
        import zipfile
        with tempfile.TemporaryDirectory() as d:
            with zipfile.ZipFile(Path(d)/'parent.whl','w') as z:z.writestr('parent-1.dist-info/METADATA','Name: parent\nVersion: 1\nRequires-Dist: mac-child; sys_platform == "darwin"\n')
            with self.assertRaisesRegex(ValueError,'closure failed'):pkg.closure(d,Path(d)/'result.json','macos-arm64','3.12.16')
    def test_tar_traversal_and_external_link_refused(self):
        for name,link in [('../outside',None),('python/bad','/outside')]:
            with tempfile.TemporaryDirectory() as d:
                archive=Path(d)/'bad.tar.gz'
                with tarfile.open(archive,'w:gz') as t:
                    item=tarfile.TarInfo(name)
                    if link:item.type=tarfile.SYMTYPE;item.linkname=link
                    t.addfile(item)
                with self.assertRaises(ValueError):rt.extract(archive,Path(d)/'out',links=True)
    def test_macho_reads_actual_arch_and_dependency_minimum(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/'fixture';header=struct.pack('<8I',0xfeedfacf,0x100000c,0,2,1,24,0,0);cmd=struct.pack('<6I',0x32,24,1,(14<<16)|(2<<8),0,0);path.write_bytes(header+cmd)
            data=rt.mach(path);self.assertEqual(data['arch'],'arm64');self.assertEqual(data['minimum'],(14,2,0))
    def test_manual_workflow_has_no_push_or_publish(self):
        main=(ROOT/'.github/workflows/offline-packages.yml').read_text();win=(ROOT/'.github/workflows/windows-kit.yml').read_text()
        self.assertIn('workflow_dispatch:',main);self.assertIn('source_commit:',main);self.assertIn('build_identity:',main)
        self.assertIn('macos-15-intel',main);self.assertNotIn('\n  push:',main+win);self.assertNotIn('gh release',main+win);self.assertNotIn('contents: write',main+win)
    def test_paths_with_spaces_are_quoted_in_environment_and_template_paths(self):
        text=(ROOT/'build/macos/templates/env.template').read_text()
        self.assertIn('DB_DATABASE="{{SHARED}}/database/database.sqlite"',text)
        self.assertIn('DETECTOR_PYTHON="{{RELEASE}}/runtime/python/bin/python3"',text)
        values=ctl.env_values('DB_DATABASE="/Library/Application Support/PHILCST-VMS/shared/database/database.sqlite"\n')
        self.assertEqual(values['DB_DATABASE'],'/Library/Application Support/PHILCST-VMS/shared/database/database.sqlite')

    def test_builder_removes_build_console_scripts_and_keeps_offline_native_check(self):
        text=(ROOT/'build/macos/build.py').read_text()
        self.assertIn("shutil.rmtree(site/'bin')",text);self.assertIn("--package-only','--inference'",text)
        self.assertIn('minimum_from_macho',text);self.assertIn('package.verify(extracted)',text)

    def test_redaction(self):
        text=ctl.redact('rtsp://user:private@host/video APP_KEY=secret password=hidden')
        self.assertNotIn('private',text);self.assertNotIn('secret',text);self.assertNotIn('hidden',text)


def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
if __name__=='__main__':unittest.main()
