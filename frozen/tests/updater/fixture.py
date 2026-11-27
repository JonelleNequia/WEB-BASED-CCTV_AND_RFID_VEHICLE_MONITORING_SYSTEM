"""Ephemeral publisher and complete synthetic Laravel update; no native OS adapter calls."""
import base64
import copy
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import uuid
import zipfile
ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'deploy/updates'),str(ROOT/'deploy/lifecycle'),str(ROOT/'tests/recovery')]
spec=importlib.util.spec_from_file_location('d5b_fixture',ROOT/'tests/recovery/fixture.py');b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
from contracts import c,now,stamp,binding
from store import Store
from manager import Manager
from install import UpdateRecovery
from native import NativeUpdate,copy_program
from backup_contract import load,sha,canonical,database,sqlite_info,file_sha
from coordination import atomic


MATERIAL=tempfile.TemporaryDirectory(prefix='d5c-ephemeral-publisher-')
MATERIAL_ROOT=Path(MATERIAL.name).resolve();MATERIAL_ROOT.chmod(0o700)
SIGNER=MATERIAL_ROOT/'private.pem'
subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(SIGNER)],check=True,capture_output=True);SIGNER.chmod(0o600)
PACKAGES={}


class Adapter(b.Adapter):
    os_version=lambda self:'26.6.1'
    php_verifier=lambda self:['php']
    require_capsule=lambda self:None # OS controller ownership is represented by the fixture boundary.
    program_permissions=NativeUpdate.program_permissions
    def migrate(self,package,payload,path):
        try:return NativeUpdate.migrate(self,package,payload,path)
        except Exception:
            result=load(payload/'migration-cache/migration-result.json')
            print('SYNTHETIC MIGRATION:',result['stdout'][-3000:],result['stderr'][-3000:])
            raise
    def verify_installed_program(self):
        from unittest.mock import patch
        with patch('native.platform.machine',return_value='AMD64' if self.p['windows'] else 'arm64'):
            return NativeUpdate.verify_installed_program(self)
    def validate_target(self,package):
        if not (package/'app/vendor/autoload.php').is_file():raise ValueError('Missing fixture application')
    def program_permissions(self,package):
        for p in [package]+list(package.rglob('*')):
            if not p.is_symlink():p.chmod(0o755 if p.is_dir() or p.name in ('php','php.exe','php-fpm') else 0o644)
    def stage_native_configuration(self,*args):pass
    def activate_program(self,journal,rollback=False):
        if self.failure=='activation':raise OSError('Synthetic program activation failure')


class Fixture:
    def __init__(self,windows=False):
        self.installation=b.Installation(windows=windows);self.f=self.installation
        self.adapter=Adapter(self.f.p);self.adapter.p=self.f.p
        self.p=self.f.p;self.directory=self.f.directory
        (self.p['app']/'bootstrap/cache' if windows else self.p['root']/'shared/cache').mkdir(parents=True,exist_ok=True)
        for name in ('app','bootstrap','config','database','public','resources','routes'):
            copy_program(ROOT/name,self.p['app']/name)
        subprocess.run(['cp','-cR',str(ROOT/'vendor'),str(self.p['app']/'vendor')],check=True)
        (self.p['app']/'composer.json').write_bytes((ROOT/'composer.json').read_bytes());(self.p['app']/'composer.lock').write_bytes((ROOT/'composer.lock').read_bytes())
        (self.p['app']/'artisan').write_bytes((ROOT/'artisan').read_bytes());(self.p['app']/'program-version.txt').write_text('1.0')
        oldphp=self.p['release']/('runtime/php/php.exe' if windows else 'runtime/php/bin/php');oldphp.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(shutil.which('php'),oldphp)
        old=load(self.p['release']/'BUILD-INFO.json');old['version']='1.0.0';old['runtimes']={'php':subprocess.check_output(['php','-r','echo PHP_VERSION;']).decode()};old['minimum_macos']='14.0.0';atomic(self.p['release']/'BUILD-INFO.json',old)
        immutable={p.relative_to(self.p['release']).as_posix():{'bytes':p.stat().st_size,'sha256':file_sha(p)} for component in ('app','runtime') for p in (self.p['release']/component).rglob('*') if p.is_file() and not p.is_symlink() and not p.relative_to(self.p['release']).as_posix().startswith(('app/storage/','app/bootstrap/cache/'))}
        atomic(self.p['release']/'CONTENT-MANIFEST.json',{'format':1,'files':immutable})
        self.store=Store(self.adapter)
        self.private=self.directory/'ephemeral-publisher.pem'
        shutil.copy2(SIGNER,self.private);self.private.chmod(0o600)
        public=subprocess.check_output(['openssl','pkey','-in',str(self.private),'-pubout']).decode();key=c.fingerprint(public)
        clock=now();self.trust={'schema':'philcst.update-trust.v1','product':c.PRODUCT,'environment':'fixture','generation':1,'keys':{key:{'public_key':public,'roles':['update','rotate'],'platforms':[self.adapter.source_binding()['platform']],'channels':['stable','prerelease','development'],'not_before':stamp(clock-dt.timedelta(days=1)),'not_after':stamp(clock+dt.timedelta(days=60)),'revoked':False}}}
        self.store.bootstrap(self.trust,sha(canonical(self.trust)),old['source']['source_sha256'])
        self.manager=Manager(self.adapter);self.update=UpdateRecovery(self.adapter,self.manager)

    def sign(self,body):
        signature=subprocess.check_output(['openssl','dgst','-sha256','-sign',str(self.private)],input=c.canonical(body))
        return {'signed':body,'signatures':[{'algorithm':'RSA-PKCS1-SHA256','key_id':next(iter(self.trust['keys'])),'value':base64.b64encode(signature).decode()}]}

    def package(self,failing=False):
        key=(self.p['windows'],failing)
        if key in PACKAGES:
            oldmanifest,oldarchive=PACKAGES[key];manifest=self.directory/'fixture.update.json';archive=self.directory/oldarchive.name
            shutil.copy2(oldmanifest,manifest);shutil.copy2(oldarchive,archive);return manifest,archive
        package=self.directory/'target';package.mkdir()
        app=package/'app'
        copy_program(self.p['app'],app)
        (app/'program-version.txt').write_text('1.1')
        with (app/'routes/web.php').open('a') as routes:routes.write("\n\\Illuminate\\Support\\Facades\\Route::get('/d5c-fixture-feature', fn () => response()->json(['feature'=>\\Illuminate\\Support\\Facades\\DB::table('d5c_fixture_feature')->count(),'php'=>PHP_VERSION]));\n")
        native=ROOT/'build/macos/dist/Mac-arm64/PHILCST-VMS/runtime/php'
        # Use actual packaged PHP 8.4.24 for target migrations (host is PHP 8.5).
        if self.p['windows']:
            (package/'runtime/php').mkdir(parents=True);shutil.copy2(shutil.which('php'),package/'runtime/php/php.exe')
        else:
            require=native.is_dir()
            if not require:raise RuntimeError('Native PHP fixture is unavailable')
            subprocess.run(['cp','-cR',str(native),str(package/'runtime')],check=True)
            (package/'runtime').rename(package/'php-copy');(package/'runtime').mkdir();(package/'php-copy').rename(package/'runtime/php')
        migration='2026_11_27_999999_add_fixture_update_feature'
        (app/'database/migrations'/ (migration+'.php')).write_text('''<?php
return new class extends \\Illuminate\\Database\\Migrations\\Migration {
public function up(): void { '''+("throw new \\RuntimeException('Synthetic migration fault');" if failing else "\\Illuminate\\Support\\Facades\\Schema::create('d5c_fixture_feature', function (\\Illuminate\\Database\\Schema\\Blueprint $t) {$t->id();$t->string('value')->nullable();});")+''' }
public function down(): void { \\Illuminate\\Support\\Facades\\Schema::dropIfExists('d5c_fixture_feature'); }
};''')
        info=load(self.p['release']/'BUILD-INFO.json');info.update(version='1.1.0',commit='d'*40,build_identity='SYNTHETIC-D5C',channel='release');info['source']['source_sha256']='e'*64;info['source']['files'][f'database/migrations/{migration}.php']=file_sha(app/'database/migrations'/(migration+'.php'));info['runtimes']={'php':'8.4.24' if not self.p['windows'] else '8.5.3'}
        if self.p['windows']:info.pop('minimum_macos',None)
        atomic(package/'BUILD-INFO.json',info)
        # Certification is constructed from a real staging migration of a private DB.
        work=self.directory/'certification';self.update.capture(work,'synthetic') if False else None
        work.mkdir();payload=work/'payload';payload.mkdir();(payload/'database').mkdir();shutil.copy2(self.p['targets']['database'],payload/'database/data');(payload/'environment').mkdir();shutil.copy2(self.p['targets']['environment'],payload/'environment/data');(payload/'storage').mkdir()
        if not failing:self.adapter.migrate(package,payload,{})
        source=self.store.read()['state'];schema=sqlite_info(self.p['targets']['database'])
        origin={'version':source['version'],'platform':source['platform'],'source':source['source'],'schema_id':source['schema_id'],'runtime_id':source['runtime_id'],'database_schema_sha256':schema['schema_sha256']}
        paths={'schema':'philcst.upgrade-paths.v1','product':c.PRODUCT,'paths':[{'from':origin,'to':binding(info),'database_schema_sha256':sqlite_info(payload/'database/data')['schema_sha256'] if not failing else 'f'*64,'service_contract':'D4-D5A-D5B-v1'}]}
        atomic(package/'UPDATE-PATHS.json',paths)
        (package/'scripts').mkdir();(package/'scripts/version.txt').write_text('new scripts')
        # Remove certification side-effects outside the declared source package.
        for name in ('storage','bootstrap/cache'):
            path=app/name
            if path.exists():shutil.rmtree(path)
        files={p.relative_to(package).as_posix():{'sha256':file_sha(p),'bytes':p.stat().st_size} for p in package.rglob('*') if p.is_file()}
        atomic(package/'CONTENT-MANIFEST.json',{'format':1,'files':files,'total_bytes':sum(x['bytes'] for x in files.values())})
        archive=self.directory/('fixture-win64.zip' if self.p['windows'] else 'fixture-arm64.tar.gz')
        if self.p['windows']:
            with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as out:
                for p in package.rglob('*'):
                    if p.is_file():out.write(p,'PHILCST-VMS/'+p.relative_to(package).as_posix())
        else:
            with tarfile.open(archive,'w:gz') as out:out.add(package,arcname='PHILCST-VMS')
        body={'schema':c.SCHEMA,'product':c.PRODUCT,'version':'1.1.0','channel':'stable','source':{'commit':info['commit'],'sha256':info['source']['source_sha256'],'build_identity':info['build_identity']},
            'platform':{'id':source['platform'],'os':c.PLATFORMS[source['platform']][0],'architecture':c.PLATFORMS[source['platform']][1],'minimum_os':'10.0.0' if self.p['windows'] else '14.0.0'},
            'compatibility':{'supported_upgrade_origins':[{'minimum_inclusive':'1.0.0','maximum_exclusive':'1.0.1'}],'schema':{'accepted':[source['schema_id']],'target':binding(info)['schema_id']},'runtime':{'accepted':[source['runtime_id']],'target':binding(info)['runtime_id']},'installation_phase':'D5'},
            'package':{'filename':archive.name,'format':'zip' if self.p['windows'] else 'tar.gz','size':archive.stat().st_size,'sha256':file_sha(archive),'content_manifest_sha256':file_sha(package/'CONTENT-MANIFEST.json')},'issued_at':stamp(now()-dt.timedelta(minutes=1)),'expires_at':stamp(now()+dt.timedelta(days=2))}
        envelope=self.sign(body);manifest=self.directory/'fixture.update.json';atomic(manifest,envelope)
        cached=MATERIAL_ROOT/('cached-'+str(int(self.p['windows']))+'-'+str(int(failing)));cached.mkdir()
        shutil.copy2(manifest,cached/manifest.name);shutil.copy2(archive,cached/archive.name);PACKAGES[key]=(cached/manifest.name,cached/archive.name)
        return manifest,archive

    def target_response(self,path='/d5c-fixture-feature',packet=None):
        app=self.p['app']
        request="$r=Illuminate\\Http\\Request::create("+repr(path)+",'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);"
        if packet is not None:
            request="$r=Illuminate\\Http\\Request::create("+repr(path)+",'POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','HTTP_X_API_KEY'=>'synthetic-d5b-key','REMOTE_ADDR'=>'127.0.0.1'],"+repr(json.dumps(packet))+");"
        script="require "+repr(str(app/'vendor/autoload.php'))+";$a=require "+repr(str(app/'bootstrap/app.php'))+";$a->useStoragePath("+repr(str(self.p['targets']['storage']))+");$k=$a->make(Illuminate\\Contracts\\Http\\Kernel::class);"+request+"$s=$k->handle($r);echo "+("json_encode(['status'=>$s->getStatusCode(),'body'=>json_decode($s->getContent(),true)]);" if packet is not None else "$s->getContent();")+"$k->terminate($r,$s);"
        result=subprocess.run([str(self.p['php']),'-r',script],env=self.f.env|{'APP_BASE_PATH':str(app)},cwd=app,capture_output=True,text=True)
        if result.returncode:raise AssertionError(result.stdout+result.stderr)
        return json.loads(result.stdout)

    def close(self):self.f.close()
