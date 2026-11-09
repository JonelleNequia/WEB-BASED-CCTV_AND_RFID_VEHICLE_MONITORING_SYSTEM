import base64
import copy
import datetime as dt
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
import zipfile

ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'build/updates'))
import contract as c
import generate as gen

NOW=dt.datetime(2026,11,8,12,tzinfo=dt.timezone.utc)


class Updates(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.work=tempfile.TemporaryDirectory(prefix='philcst-update-keys-');cls.keys=Path(cls.work.name);cls.php=[shutil.which('php'),'-n']
        if not cls.php[0] or not shutil.which('openssl'):raise unittest.SkipTest('Build/test PHP OpenSSL and openssl CLI required; no target tool is installed')
        cls.material=[]
        for n in range(2):
            private=cls.keys/f'private-{n}.pem';public=cls.keys/f'public-{n}.pem'
            subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(private)],check=True,capture_output=True);private.chmod(0o600)
            subprocess.run(['openssl','pkey','-in',str(private),'-pubout','-out',str(public)],check=True,capture_output=True)
            cls.material.append((private,public.read_text()))
    @classmethod
    def tearDownClass(cls):cls.work.cleanup()
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='philcst-update-fixture-');self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name)
        self.state={'schema':'philcst.installation-update-state.v1','product':c.PRODUCT,'environment':'fixture','platform':'macos-arm64','os_version':'14.0.0','version':'1.0.0','channel':'stable','source':{'commit':'a'*40,'sha256':'b'*64,'build_identity':'installed'},'schema_id':'c'*64,'runtime_id':'d'*64,'max_expanded_bytes':8*1024**2}
        key=self.material[0][1];kid=c.fingerprint(key)
        self.trust={'schema':'philcst.update-trust.v1','product':c.PRODUCT,'environment':'fixture','generation':1,'keys':{kid:self.key(key)}}
        self.stage,self.package=self.make_package();self.envelope=gen.create(self.stage,self.package,now=NOW)
        self.envelope['signed']['compatibility'].update(supported_upgrade_origins=[{'minimum_inclusive':'1.0.0','maximum_exclusive':'1.2.0'}],schema={'accepted':[self.state['schema_id']],'target':self.envelope['signed']['compatibility']['schema']['target']},runtime={'accepted':[self.state['runtime_id']],'target':self.envelope['signed']['compatibility']['runtime']['target']})
        self.resign()
    def key(self,pem):return {'public_key':pem,'channels':sorted(c.CHANNELS),'platforms':sorted(c.PLATFORMS),'roles':['update','rotate'],'not_before':'2026-01-01T00:00:00Z','not_after':'2027-01-01T00:00:00Z','revoked':False}
    def sign(self,body,n=0):
        signature=subprocess.run(['openssl','dgst','-sha256','-sign',str(self.material[n][0])],input=c.canonical(body),capture_output=True,check=True).stdout
        return {'signed':body,'signatures':[{'algorithm':'RSA-PKCS1-SHA256','key_id':c.fingerprint(self.material[n][1]),'value':base64.b64encode(signature).decode()}]}
    def resign(self):self.envelope=self.sign(self.envelope['signed'])
    def metadata(self,**kwargs):return c.validate_metadata(self.envelope,self.state,self.trust,self.php,fixture=True,now=NOW,**kwargs)
    def validate(self,**kwargs):return c.validate_import(self.envelope,self.package,self.state,self.trust,self.php,fixture=True,now=NOW,**kwargs)
    def make_package(self,platform='macos-arm64',suffix=''):
        stage=self.root/('stage'+suffix);(stage/'app').mkdir(parents=True)
        info={'product':'PHILCST Vehicle Monitoring','version':'1.1.0','commit':'e'*40,'source':{'source_sha256':'f'*64,'files':{'database/migrations/fixture.php':'1'*64}},'channel':'release','build_identity':'fixture-build','architecture':{'macos-arm64':'arm64','macos-x86_64':'x86_64','windows-x64':'win_amd64'}[platform],'runtimes':{'php':'8.4'},'dependency_locks':{'fixture':'2'*64}}
        if platform!='windows-x64':info['minimum_macos']='14.0.0'
        (stage/'BUILD-INFO.json').write_text(json.dumps(info));(stage/'app/program.php').write_text('<?php // synthetic fixture only')
        files={f.relative_to(stage).as_posix():{'sha256':c.sha_file(f),'bytes':f.stat().st_size} for f in stage.rglob('*') if f.is_file()}
        (stage/'CONTENT-MANIFEST.json').write_text(json.dumps({'format':1,'files':files,'total_bytes':sum(x['bytes'] for x in files.values())}))
        path=self.root/('fixture'+suffix+('.zip' if platform=='windows-x64' else '.tar.gz'));self.archive(stage,path,platform)
        return stage,path
    def archive(self,stage,path,platform='macos-arm64',extra=None):
        if platform=='windows-x64':
            with zipfile.ZipFile(path,'w') as z:
                for f in stage.rglob('*'):
                    if f.is_file():z.write(f,'PHILCST-VMS/'+f.relative_to(stage).as_posix())
                if extra:z.writestr(extra,'unexpected')
        else:
            with tarfile.open(path,'w:gz') as t:
                t.add(stage,arcname='PHILCST-VMS')
                if extra:
                    m=tarfile.TarInfo(extra);m.size=1;t.addfile(m,io.BytesIO(b'x'))
    def rehash(self):
        self.envelope['signed']['package'].update(size=self.package.stat().st_size,sha256=c.sha_file(self.package));self.resign()

    def test_manifest_parsing_rejects_missing_truncated_duplicate_and_nonfinite(self):
        for raw in [b'',b'{"signed":',b'{"schema":1,"schema":2}',b'{"value":NaN}',b'\xff']:
            with self.assertRaises(c.Rejected):c.parse(raw)
        with self.assertRaises(c.Rejected):c.load(self.root/'missing.json')
        for field in ['product','platform','compatibility','package','source']:
            body=copy.deepcopy(self.envelope['signed']);body.pop(field)
            with self.assertRaises(c.Rejected):c.validate_manifest(body)
    def test_semver_order_numeric_prerelease_and_build_metadata(self):
        sequence=['1.0.0-alpha','1.0.0-alpha.1','1.0.0-alpha.beta','1.0.0-beta','1.0.0-beta.2','1.0.0-beta.11','1.0.0-rc.1','1.0.0','1.0.1','1.1.0','2.0.0']
        for a,b in zip(sequence,sequence[1:]):self.assertLess(c.Version(a),c.Version(b))
        self.assertEqual(c.Version('1.0.0+abc'),c.Version('1.0.0+xyz'))
        for bad in ['1','01.0.0','1.0.0-01','1.2.3-','v1.0.0','1\u0661.0.0']:
            with self.assertRaises(c.Rejected):c.Version(bad)
    def test_stable_default_and_explicit_prerelease_development(self):
        self.assertEqual(self.metadata()['status'],'eligible-for-D5-review')
        for channel,version in [('prerelease','1.1.0-rc.1'),('development','1.1.0-dev.1')]:
            self.envelope['signed'].update(channel=channel,version=version);self.resign()
            with self.assertRaisesRegex(c.Rejected,'explicitly'):self.metadata()
            self.assertEqual(self.metadata(channel=channel)['status'],'eligible-for-D5-review')
    def test_version_channel_mismatch_and_unknown_fields_refused(self):
        self.envelope['signed']['channel']='stable';self.envelope['signed']['version']='1.1.0-rc.1'
        with self.assertRaisesRegex(c.Rejected,'channel mismatch'):self.metadata()
        self.envelope['signed']['channel']='nightly'
        with self.assertRaises(c.Rejected):self.metadata()
    def test_platform_wrong_architecture_and_old_os(self):
        for platform in ['windows-x64','macos-x86_64']:
            state=copy.deepcopy(self.state);state['platform']=platform
            with self.assertRaisesRegex(c.Rejected,'architecture'):c.validate_metadata(self.envelope,state,self.trust,self.php,fixture=True,now=NOW)
        self.state['os_version']='13.9'
        with self.assertRaisesRegex(c.Rejected,'OS version'):self.metadata()
    def test_unsupported_origins_schema_runtime_and_downgrade(self):
        for target in ['origin','schema','runtime']:
            envelope=copy.deepcopy(self.envelope);cpt=envelope['signed']['compatibility']
            if target=='origin':cpt['supported_upgrade_origins']=[]
            else:cpt[target]['accepted']=[]
            envelope=self.sign(envelope['signed'])
            with self.assertRaises(c.Rejected):c.validate_metadata(envelope,self.state,self.trust,self.php,fixture=True,now=NOW)
        self.state['version']='2.0.0'
        with self.assertRaisesRegex(c.Rejected,'downgrade'):self.metadata()
    def test_already_installed_and_same_version_rebuilt_identity_rejected(self):
        body=self.envelope['signed'];self.state.update(version=body['version'],source=body['source'],schema_id=body['compatibility']['schema']['target'],runtime_id=body['compatibility']['runtime']['target'])
        self.assertEqual(self.metadata()['status'],'already-installed');self.state['source']=dict(body['source'],build_identity='other')
        with self.assertRaisesRegex(c.Rejected,'identity mismatch'):self.metadata()
    def test_trusted_signature_and_tampered_manifest(self):
        self.metadata();self.envelope['signed']['source']['build_identity']='tampered'
        with self.assertRaisesRegex(c.Rejected,'signature'):self.metadata()
    def test_unsigned_untrusted_replacement_revoked_expired_and_scoped_key(self):
        original=copy.deepcopy(self.envelope)
        self.envelope['signatures']=[]
        with self.assertRaises(c.Rejected):self.metadata()
        self.envelope=self.sign(original['signed'],1)
        with self.assertRaises(c.Rejected):self.metadata()
        self.envelope=original
        key=next(iter(self.trust['keys'].values()))
        for field,value in [('revoked',True),('not_after','2026-05-01T00:00:00Z'),('channels',['development']),('platforms',['windows-x64'])]:
            saved=key[field];key[field]=value
            with self.assertRaises(c.Rejected):self.metadata()
            key[field]=saved
        self.envelope['replacement_key']=self.material[1][1]
        with self.assertRaises(c.Rejected):self.metadata()
    def test_fixture_keys_never_accepted_for_production_state(self):
        with self.assertRaisesRegex(c.Rejected,'environment'):c.validate_metadata(self.envelope,self.state,self.trust,self.php,now=NOW)
        state=copy.deepcopy(self.state);state['environment']='production'
        with self.assertRaises(c.Rejected):c.validate_metadata(self.envelope,state,self.trust,self.php,fixture=True,now=NOW)
    def test_manifest_time_window_and_signature_encoding(self):
        for now in [NOW-dt.timedelta(days=1),NOW+dt.timedelta(days=31)]:
            with self.assertRaisesRegex(c.Rejected,'not current'):c.validate_metadata(self.envelope,self.state,self.trust,self.php,fixture=True,now=now)
        self.envelope['signatures'][0]['value']='%%%'
        with self.assertRaises(c.Rejected):self.metadata()
    def test_local_and_downloaded_delivery_use_identical_validator(self):
        a=self.validate(delivery='local');b=self.validate(delivery='downloaded')
        self.assertEqual({k:v for k,v in a.items() if k!='delivery'},{k:v for k,v in b.items() if k!='delivery'});self.assertFalse(a['active_data_mutated']);self.assertFalse(a['installation_performed'])
    def test_missing_truncated_tampered_and_wrong_filename_payload(self):
        raw=self.package.read_bytes();self.package.unlink()
        with self.assertRaises(c.Rejected):self.validate()
        self.package.write_bytes(raw[:50])
        with self.assertRaises(c.Rejected):self.validate()
        self.package.write_bytes(raw[:-1]+bytes([raw[-1]^1]))
        with self.assertRaises(c.Rejected):self.validate()
        self.package.write_bytes(raw);other=self.package.with_name('other.tar.gz');self.package.rename(other)
        with self.assertRaises(c.Rejected):c.validate_import(self.envelope,other,self.state,self.trust,self.php,fixture=True,now=NOW)
    def test_content_manifest_tamper_and_unexpected_extracted_file(self):
        (self.stage/'app/program.php').write_text('changed');self.archive(self.stage,self.package);self.rehash()
        with self.assertRaisesRegex(c.Rejected,'corrupt extracted'):self.validate()
        (self.stage/'app/program.php').write_text('<?php // synthetic fixture only');self.archive(self.stage,self.package,extra='PHILCST-VMS/extra');self.rehash()
        with self.assertRaisesRegex(c.Rejected,'Unexpected extracted'):self.validate()
    def test_archive_traversal_links_duplicate_case_and_limits(self):
        for bad in ['../outside','/absolute','PHILCST-VMS/../outside','PHILCST-VMS/a\\b','PHILCST-VMS/CON.txt','PHILCST-VMS/name.']:
            self.archive(self.stage,self.package,extra=bad);self.rehash()
            with self.assertRaises(c.Rejected):self.validate()
        self.archive(self.stage,self.package,extra='PHILCST-VMS/BUILD-info.json');self.rehash()
        with self.assertRaisesRegex(c.Rejected,'colliding'):self.validate()
        self.archive(self.stage,self.package);self.rehash();self.state['max_expanded_bytes']=1
        with self.assertRaisesRegex(c.Rejected,'limits'):self.validate()
        self.state['max_expanded_bytes']=8*1024**2
        with tarfile.open(self.package,'w:gz') as t:
            m=tarfile.TarInfo('PHILCST-VMS/link');m.type=tarfile.SYMTYPE;m.linkname='/outside';t.addfile(m)
        self.rehash()
        with self.assertRaisesRegex(c.Rejected,'links'):self.validate()
    def test_windows_zip_and_both_mac_architectures(self):
        for i,platform in enumerate(c.PLATFORMS):
            stage,path=self.make_package(platform,str(i));envelope=gen.create(stage,path,now=NOW);body=envelope['signed'];body['compatibility']['supported_upgrade_origins']=[{'minimum_inclusive':'1.0.0','maximum_exclusive':'1.2.0'}]
            body['compatibility']['schema']['accepted']=[self.state['schema_id']];body['compatibility']['runtime']['accepted']=[self.state['runtime_id']];envelope=self.sign(body);state=dict(self.state,platform=platform,os_version='14.0.0' if platform.startswith('macos') else '10.0.0')
            self.assertEqual(c.validate_import(envelope,path,state,self.trust,self.php,fixture=True,now=NOW)['status'],'eligible-for-D5-review')
    def test_signed_metadata_must_match_actual_package_build_and_contract(self):
        self.envelope['signed']['source']['build_identity']='other';self.resign()
        with self.assertRaisesRegex(c.Rejected,'identity mismatch'):self.validate()
        self.envelope['signed']['source']['build_identity']='fixture-build';self.envelope['signed']['compatibility']['runtime']['target']='0'*64;self.resign()
        with self.assertRaisesRegex(c.Rejected,'contract mismatch'):self.validate()
    def test_discovery_unavailable_invalid_and_rapid_candidate_selection_do_not_write(self):
        active=self.root/'active';active.mkdir()
        for name in ['database.sqlite','.env','services.plist']: (active/name).write_text('unchanged '+name)
        before={p:c.sha_file(p) for p in active.iterdir()}
        self.assertEqual(c.select_candidate([],self.state,self.trust,self.php,fixture=True,now=NOW)['status'],'no-eligible-update')
        bogus=copy.deepcopy(self.envelope);bogus['signed']['version']='9.0.0'
        selected=c.select_candidate([bogus,self.envelope],self.state,self.trust,self.php,fixture=True,now=NOW)
        self.assertEqual(selected['manifest']['signed']['version'],'1.1.0');self.assertEqual(selected['rejected'],1)
        self.validate();self.assertEqual(before,{p:c.sha_file(p) for p in before})
    def test_key_rotation_requires_current_root_and_generation_and_never_writes(self):
        nexttrust=copy.deepcopy(self.trust);nexttrust['generation']=2;nexttrust['keys'][c.fingerprint(self.material[1][1])]=self.key(self.material[1][1])
        body={'schema':'philcst.update-key-rotation.v1','product':c.PRODUCT,'previous_trust_sha256':c.sha_bytes(c.canonical(self.trust)),'generation':2,'replacement_trust':nexttrust,'issued_at':'2026-11-08T00:00:00Z','expires_at':'2026-12-01T00:00:00Z'}
        before=c.canonical(self.trust);proposal=c.validate_rotation(self.sign(body),self.trust,self.php,fixture=True,now=NOW);self.assertFalse(proposal['trust_written']);self.assertEqual(c.canonical(self.trust),before)
        with self.assertRaises(c.Rejected):c.validate_rotation(self.sign(body,1),self.trust,self.php,fixture=True,now=NOW)
        with self.assertRaisesRegex(c.Rejected,'trust|generation'):c.validate_rotation(self.sign(body),nexttrust,self.php,fixture=True,now=NOW)
    def test_matrix_requires_all_three_common_source_version_channel_and_build(self):
        entries=[]
        for i,platform in enumerate(c.PLATFORMS):
            stage,path=self.make_package(platform,'matrix'+str(i));entries.append(gen.create(stage,path,now=NOW))
        self.assertEqual(len(gen.matrix(entries)['platforms']),3)
        with self.assertRaises(c.Rejected):gen.matrix(entries[:2])
        entries[-1]['signed']['source']['build_identity']='different'
        with self.assertRaisesRegex(c.Rejected,'Mixed'):gen.matrix(entries)
    def test_defaults_are_unsigned_and_block_upgrade_compatibility(self):
        envelope=gen.create(self.stage,self.package,now=NOW);self.assertEqual(envelope['signatures'],[]);self.assertEqual(envelope['signed']['compatibility']['supported_upgrade_origins'],[])
        with self.assertRaises(c.Rejected):c.validate_metadata(envelope,self.state,self.trust,self.php,fixture=True,now=NOW)

    def cli_inputs(self):
        folder=self.root/'inputs with spaces';folder.mkdir()
        for name,value in [('manifest',self.envelope),('state',self.state),('trust',self.trust)]:
            (folder/(name+'.json')).write_text(json.dumps(value))
        return folder

    def test_cli_and_powershell_wrapper_local_downloaded_and_rejection_preserve_inputs(self):
        folder=self.cli_inputs();before={p:c.sha_file(p) for p in folder.iterdir()}
        args=['--manifest',str(folder/'manifest.json'),'--package',str(self.package),'--state',str(folder/'state.json'),'--trust-store',str(folder/'trust.json'),'--php',self.php[0]]
        for delivery in ['local','downloaded']:
            result=subprocess.run([sys.executable,'-B',str(ROOT/'build/updates/validate.py'),*args,'--delivery',delivery,'--fixture-trust'],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(json.loads(result.stdout)['delivery'],delivery)
        result=subprocess.run([sys.executable,'-B',str(ROOT/'build/updates/validate.py'),*args],capture_output=True,text=True)
        self.assertNotEqual(result.returncode,0);self.assertEqual(json.loads(result.stderr)['status'],'rejected')
        if shutil.which('pwsh'):
            result=subprocess.run(['pwsh','-NoProfile','-File',str(ROOT/'build/updates/validate.ps1'),'-Manifest',str(folder/'manifest.json'),'-Package',str(self.package),'-State',str(folder/'state.json'),'-TrustStore',str(folder/'trust.json'),'-Python',sys.executable,'-Php',self.php[0],'-FixtureTrust'],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr);self.assertEqual(json.loads(result.stdout)['status'],'eligible-for-D5-review')
        self.assertEqual(before,{p:c.sha_file(p) for p in before})

    def test_build_signer_rejects_mismatched_key_and_emits_verifiable_signature(self):
        envelope=gen.create(self.stage,self.package,now=NOW)
        public=self.root/'public.pem';public.write_text(self.material[1][1])
        with self.assertRaises(c.Rejected):gen.sign(copy.deepcopy(envelope),self.material[0][0],public)
        public.write_text(self.material[0][1]);signed=gen.sign(envelope,self.material[0][0],public)
        c.authenticate(signed,self.trust,self.php,fixture=True,now=NOW)
        signed['signatures'][0]['algorithm']='unapproved'
        with self.assertRaises(c.Rejected):c.authenticate(signed,self.trust,self.php,fixture=True,now=NOW)

    def test_rotation_proposal_can_revoke_old_key_without_trusting_candidate_key(self):
        replacement=copy.deepcopy(self.trust);replacement['generation']=2
        next(iter(replacement['keys'].values()))['revoked']=True
        replacement['keys'][c.fingerprint(self.material[1][1])]=self.key(self.material[1][1])
        body={'schema':'philcst.update-key-rotation.v1','product':c.PRODUCT,'previous_trust_sha256':c.sha_bytes(c.canonical(self.trust)),'generation':2,'replacement_trust':replacement,'issued_at':'2026-11-08T00:00:00Z','expires_at':'2026-12-01T00:00:00Z'}
        proposed=c.validate_rotation(self.sign(body),self.trust,self.php,fixture=True,now=NOW)['trust']
        with self.assertRaises(c.Rejected):c.validate_metadata(self.envelope,self.state,proposed,self.php,fixture=True,now=NOW)
        self.assertEqual(c.validate_metadata(self.sign(self.envelope['signed'],1),self.state,proposed,self.php,fixture=True,now=NOW)['status'],'eligible-for-D5-review')
        with self.assertRaises(c.Rejected):c.validate_metadata(self.sign(self.envelope['signed'],1),self.state,self.trust,self.php,fixture=True,now=NOW)

    def test_sidecars_stay_outside_payload_and_cannot_overwrite_existing_metadata(self):
        output=self.root/'candidate.update.json'
        command=[sys.executable,'-B',str(ROOT/'build/updates/generate.py'),'--stage',str(self.stage),'--package',str(self.package),'--output',str(output)]
        before={p:c.sha_file(p) for p in self.stage.rglob('*') if p.is_file()}
        self.assertEqual(subprocess.run(command,capture_output=True).returncode,0)
        self.assertNotEqual(subprocess.run(command,capture_output=True).returncode,0)
        command[-1]=str(self.stage/'forbidden.update.json')
        self.assertNotEqual(subprocess.run(command,capture_output=True).returncode,0)
        self.assertFalse((self.stage/'forbidden.update.json').exists());self.assertEqual(before,{p:c.sha_file(p) for p in before})

    def test_discovery_selects_newest_valid_version_in_explicit_channel_only(self):
        newer=copy.deepcopy(self.envelope['signed']);newer['version']='1.2.0';newer=self.sign(newer)
        development=copy.deepcopy(newer['signed']);development.update(version='2.0.0-dev.1',channel='development');development=self.sign(development)
        candidates=[self.envelope,development,newer]
        chosen=c.select_candidate(candidates,self.state,self.trust,self.php,fixture=True,now=NOW)
        self.assertEqual(chosen['manifest']['signed']['version'],'1.2.0')
        chosen=c.select_candidate(candidates,self.state,self.trust,self.php,channel='development',fixture=True,now=NOW)
        self.assertEqual(chosen['manifest']['signed']['version'],'2.0.0-dev.1')

    def test_windows_zip_traversal_and_symlink_are_rejected_without_extraction(self):
        stage,path=self.make_package('windows-x64','unsafe-zip');envelope=gen.create(stage,path,now=NOW)
        for link in [False,True]:
            self.archive(stage,path,'windows-x64')
            with zipfile.ZipFile(path,'a') as z:
                if link:
                    item=zipfile.ZipInfo('PHILCST-VMS/link');item.create_system=3;item.external_attr=0o120777<<16;z.writestr(item,'../../outside')
                else:z.writestr('PHILCST-VMS/../../outside','unsafe')
            envelope['signed']['package'].update(size=path.stat().st_size,sha256=c.sha_file(path))
            with self.assertRaises(c.Rejected):c.inspect_archive(path,envelope['signed'],8*1024**2)
        self.assertFalse((self.root/'outside').exists())

    def test_unicode_normalization_collisions_and_directory_shadowing_fail(self):
        for paths in [('PHILCST-VMS/\u00e9.txt','PHILCST-VMS/e\u0301.txt'),('PHILCST-VMS/file','PHILCST-VMS/file/child'),('PHILCST-VMS/file/child','PHILCST-VMS/file')]:
            with tarfile.open(self.package,'w:gz') as t:
                for name in paths:
                    item=tarfile.TarInfo(name);item.size=1;t.addfile(item,io.BytesIO(b'x'))
            self.rehash()
            with self.assertRaisesRegex(c.Rejected,'colliding|shadows'):self.validate()

    def test_rotation_cli_returns_proposal_without_replacing_trust_file(self):
        folder=self.cli_inputs();replacement=copy.deepcopy(self.trust);replacement['generation']=2
        body={'schema':'philcst.update-key-rotation.v1','product':c.PRODUCT,'previous_trust_sha256':c.sha_bytes(c.canonical(self.trust)),'generation':2,'replacement_trust':replacement,'issued_at':'2026-11-08T00:00:00Z','expires_at':'2026-12-01T00:00:00Z'}
        (folder/'manifest.json').write_text(json.dumps(self.sign(body)));before={p:c.sha_file(p) for p in folder.iterdir()}
        result=subprocess.run([sys.executable,'-B',str(ROOT/'build/updates/validate.py'),'--manifest',str(folder/'manifest.json'),'--trust-store',str(folder/'trust.json'),'--php',self.php[0],'--rotation-proposal','--fixture-trust'],capture_output=True,text=True)
        self.assertEqual(result.returncode,0,result.stderr);self.assertFalse(json.loads(result.stdout)['trust_written']);self.assertEqual(before,{p:c.sha_file(p) for p in before})

if __name__=='__main__':unittest.main()
