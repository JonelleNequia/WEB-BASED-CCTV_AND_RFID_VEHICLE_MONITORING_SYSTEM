"""Installed native update boundary. No native mutations occur on import."""
import importlib.util
import json
import os
from pathlib import Path
import platform
import plistlib
import shutil
import subprocess
import sys
from contracts import c, require, load, no_links, canonical, sha
from coordination import atomic, identity, phase
from recovery_platform import NativeRecovery
from maintenance import layout, command
from recovery import copy_file, tree_hash, inventory, sync_dir


def copy_program(source,destination):
    """Copy immutable code/runtime only; no environment, history or live storage."""
    source=Path(source);destination=Path(destination);destination.mkdir(mode=0o700,parents=True,exist_ok=True)
    for path in source.iterdir():
        rel=path.name
        if rel in ('.env','storage') or rel.startswith('.env.') or source.name=='database' and rel.startswith('database.sqlite') or source.name=='bootstrap' and rel=='cache':continue
        no_links(path)
        if path.is_dir():copy_program(path,destination/rel)
        else:copy_file(path,destination/rel)


class NativeUpdate(NativeRecovery):
    def os_version(self):
        if self.p['windows']:
            v=sys.getwindowsversion();return f'{v.major}.{v.minor}.{v.build}'
        return platform.mac_ver()[0]

    def capsule(self):
        receipt=load(self.p['base'].parent/'update-controller.json')
        require(receipt['installation']==identity(self.p['base']),'Controller belongs to another installation')
        __import__('uuid').UUID(receipt['id'])
        path=self.p['base'].parent/'update-controller'/receipt['id'];no_links(path)
        require(tree_hash(path)==receipt['sha256'],'Independent recovery controller is missing/corrupt')
        return path

    def require_capsule(self):
        path=self.capsule();require(Path(sys.executable).resolve().is_relative_to((path/'runtime').resolve()),'Run installation/recovery using the retained controller interpreter; do not overwrite the sole updater')

    def admin(self):
        if self.p['windows']:
            import ctypes
            require(bool(ctypes.windll.shell32.IsUserAnAdmin()),'Run installed controller as Administrator')
        else:require(sys.platform=='darwin' and os.geteuid()==0,'Run installed Mac controller with sudo')
        for p in (self.p['root'],self.p['base']):no_links(p)
        require((self.p['base']/'identity.json').is_file(),'Initialized D5A/D5B installation required; legacy adoption is separate')
        if (self.p['base'].parent/'update-controller.json').exists():self.capsule()
        else:super().admin()

    def private(self,path):
        if not self.p['windows'] or not (self.p['base'].parent/'update-controller.json').exists():return super().private(path)
        old=self.p['app'];self.p['app']=self.capsule()/'app'
        try:return super().private(path)
        finally:self.p['app']=old

    def permissions_for(self,role,path,verify=False):
        if not self.p['windows'] or not (self.p['base'].parent/'update-controller.json').exists():return super().permissions_for(role,path,verify)
        old=self.p['app'];self.p['app']=self.capsule()/'app'
        try:return super().permissions_for(role,path,verify)
        finally:self.p['app']=old

    def verify_installed_program(self):
        info=load(self.p['release']/'BUILD-INFO.json')
        architecture=platform.machine().lower()
        require((self.p['windows'] and architecture in ('amd64','x86_64') and info['architecture']=='win_amd64') or (not self.p['windows'] and architecture==info['architecture']),'Installed runtime/platform differs from this native host')
        manifest=load(self.p['release']/'CONTENT-MANIFEST.json');expected={}
        def immutable(name):
            return name.startswith(('app/','runtime/')) and name not in ('app/.env','app/database/database.sqlite','app/database/database.sqlite-wal','app/database/database.sqlite-shm','app/database/database.sqlite-journal') and not name.startswith(('app/storage/','app/bootstrap/cache/'))
        for name,item in manifest['files'].items():
            c.safe_path(name)
            if immutable(name):
                file=self.p['release']/name;no_links(file)
                require(file.is_file() and file.stat().st_size==item['bytes'] and c.sha_file(file)==item['sha256'],'Installed immutable program/runtime differs from its preserved inventory')
                expected[name]=True
        require(expected,'Missing installed immutable program/runtime inventory')
        for component in ('app','runtime'):
            for directory,dirs,files in os.walk(self.p['release']/component):
                relative=Path(directory).relative_to(self.p['release'])
                dirs[:]=[d for d in dirs if immutable((relative/d).as_posix()+'/')]
                for d in dirs:no_links(Path(directory)/d)
                for name in files:
                    rel=(relative/name).as_posix()
                    if immutable(rel):require(rel in expected,'Unexpected installed program/runtime file')
        return True

    def bootstrap_controller(self,confirm_source):
        require(confirm_source==self.source_binding()['source_sha256'],'Independently verified installed source confirmation required')
        self.verify_installed_program();self.check_configuration()
        size=sum(item['bytes'] for item in load(self.p['release']/'CONTENT-MANIFEST.json')['files'].values())
        self.free(size)
        root=self.p['base'].parent/'update-controller';self.private(root)
        receipt=self.p['base'].parent/'update-controller.json';require(not receipt.exists(),'Retained controller already exists; never overwrite its sole executable')
        candidate=root/str(__import__('uuid').uuid4());self.private(candidate)
        for role in ('app','runtime','config','scripts','checks','BUILD-INFO.json','SOURCE-INFO.json','CONTENT-MANIFEST.json'):
            source=self.p['release']/role
            if role=='config' and self.p['windows']:continue
            if source.is_dir():copy_program(source,candidate/role)
            elif source.is_file():copy_file(source,candidate/role)
        (candidate/'config').mkdir(mode=0o700,exist_ok=True)
        original=self.p['ini'].read_text();original=original.replace(str(self.p['release']/'runtime/php'),str(candidate/'runtime/php'))
        (candidate/'config/recovery-php.ini').write_text(original)
        self.program_permissions(candidate)
        # Parent remains administrator-only; executable permission is needed on Mac.
        atomic(receipt,{'schema':1,'id':candidate.name,'installation':identity(self.p['base']),'sha256':tree_hash(candidate)})
        self.permissions_for('security',receipt)
        controls=self.p['root']/'Controls';controls.mkdir(mode=0o750,exist_ok=True)
        if self.p['windows']:
            copy_file(candidate/'app/deploy/windows/update.ps1',controls/'Update-Recovery.ps1')
            (controls/'lib').mkdir(mode=0o700,exist_ok=True);copy_file(candidate/'app/deploy/windows/lib/common.ps1',controls/'lib/common.ps1')
            self.program_permissions(controls)
        else:
            copy_file(candidate/'app/deploy/macos/update.command',controls/'Update-Recovery.command');(controls/'Update-Recovery.command').chmod(0o700)
        return {'controller':str(candidate/'app/deploy/updates/cli.py'),'python':str(candidate/('runtime/python/python.exe' if self.p['windows'] else 'runtime/python/bin/python3'))}

    def php_verifier(self):
        root=self.capsule() if (self.p['base'].parent/'update-controller.json').exists() else self.p['release']
        return [str(root/('runtime/php/php.exe' if self.p['windows'] else 'runtime/php/bin/php')),'-c',str(root/'config/recovery-php.ini' if (root/'config/recovery-php.ini').exists() else self.p['ini'])]

    def crypto_check(self,payload):
        # D5B validates data using the old independent helper/vendor even if the
        # incoming application cannot boot or app/runtime renames were interrupted.
        capsule=self.capsule();trust=payload/'update_trust/trust.json'
        argument={'database':str(payload/'database/data'),'environment':str(payload/'environment/data'),'web':str(payload/'tls'),
                  'tls_identity':str(payload/'tls_identity/data'),'public_root':str(payload/'public_root/data'),
                  'public_keys':{k:v['public_key'] for k,v in load(trust)['keys'].items()} if trust.exists() else {}}
        result=subprocess.run(self.php_verifier()+[str(capsule/'app/deploy/lifecycle/recovery-check.php')],input=json.dumps(argument),capture_output=True,text=True,timeout=60)
        require(result.returncode==0,'Retained verifier rejected secrets/issuer; keep maintenance')
        return json.loads(result.stdout)

    def mac(self):
        if not (self.p['base'].parent/'update-controller.json').exists():return super().mac()
        path=self.capsule()/'app/deploy/macos/control.py'
        spec=importlib.util.spec_from_file_location('philcst_retained_mac_control',path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module

    def windows(self,action,receipt=None):
        if not (self.p['base'].parent/'update-controller.json').exists():return super().windows(action,receipt)
        capsule=self.capsule()
        return json.loads(command(['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',capsule/'app/deploy/windows/lifecycle-adapter.ps1',
            '-Root',self.p['root'],'-Action',action,'-ReceiptJson',json.dumps(receipt or {}),'-RecoveryCapsule',capsule],os.environ|{'PHILCST_LIFECYCLE_CONTROLLER_PID':str(os.getpid())}) or '{}')

    def program_permissions(self,package):
        # Incoming filesystem modes/ACLs never become authoritative.
        package=Path(package)
        for path in [package]+list(package.rglob('*')):
            if path.is_symlink():continue # Only controller-created Mac mutable-state links.
            if os.name=='posix':
                if not self.fixture:os.chown(path,0,0)
                executable=path.is_dir() or path.name in ('php','php-fpm','python3','python3.12','python','caddy','go2rtc') or path.suffix=='.command'
                path.chmod(0o755 if executable else 0o644)
        if self.p['windows']:
            trusted=(self.capsule()/'app' if (self.p['base'].parent/'update-controller.json').exists() else self.p['app'])
            command(['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',trusted/'deploy/windows/update-program-acl.ps1','-Path',package])

    def activate_program(self,journal,rollback=False):
        if self.p['windows']:return # Fixed root app/runtime paths and owned service SIDs remain.
        ctl=self.mac();shared=self.p['root']/'shared';release=self.p['release']
        # All these writes occur while stopped and under the journal. Re-running
        # them repairs interrupted config/plist writes; no trust/IP/DNS change.
        for role in ctl.ROLES:
            file=Path('/Library/LaunchDaemons')/(ctl.LABEL+role+'.plist')
            require(file.is_file() and ctl.plist_owned(plistlib.loads(file.read_bytes()),self.p['root'],role),'Unowned service plist; native recovery requires administrator review')
            with file.open('wb') as out:plistlib.dump(ctl.service_plist(self.p['root'],release,shared,role),out);out.flush();os.fsync(out.fileno())
            file.chmod(0o644);os.chown(file,0,0)
        self.permissions_for('configuration',shared/'config');self.permissions_for('tls',self.p['targets']['tls'])

    def stage_native_configuration(self,package,payload,journal):
        if self.p['windows']:return
        ctl=self.mac();shared=self.p['root']/'shared';future=self.p['root']/'releases'/('update-'+journal['candidate'])
        values={'ROOT':str(self.p['root']),'RELEASE':str(future),'SHARED':str(shared)}
        for source,name in [('php.ini.template','php.ini'),('php-fpm.conf.template','php-fpm.conf')]:
            text=(package/'config'/source).read_text()
            for key,value in values.items():text=text.replace('{{'+key+'}}',value)
            require('{{' not in text,'Unknown target configuration placeholder')
            (payload/'configuration'/name).write_text(text)
        sys.path.insert(0,str(self.capsule()/'app/deploy/network'))
        import policy
        network=load(self.p['targets']['network'])['config'];web=self.p['targets']['tls']
        text=policy.caddy_config((package/'config/Caddyfile.template').read_text(),future/'app/public',web/'tls',web/'logs/access.log',network,pinned=True)
        (payload/'tls/Caddyfile').write_text(text)
        self.permissions_for('configuration',payload/'configuration');self.permissions_for('tls',payload/'tls')

    def install_worker(self):
        self.require_capsule();capsule=self.capsule()
        if self.p['windows']:
            command(['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',capsule/'app/deploy/windows/update-worker.ps1','-Root',self.p['root'],'-Install'])
        else:
            file=Path('/Library/LaunchDaemons/com.philcst.vms.update-preparation.plist')
            arguments=[str(capsule/'runtime/python/bin/python3'),'-I','-B',str(capsule/'app/deploy/updates/cli.py'),'tick','--root',str(self.p['root'])]
            if file.exists():require(plistlib.loads(file.read_bytes()).get('ProgramArguments')==arguments,'Unowned preparation daemon exists')
            data={'Label':'com.philcst.vms.update-preparation','ProgramArguments':arguments,'StartInterval':60,'RunAtLoad':False,'UserName':'root','GroupName':'wheel','Umask':0o077,'StandardOutPath':'/dev/null','StandardErrorPath':'/dev/null'}
            with file.open('wb') as out:plistlib.dump(data,out);out.flush();os.fsync(out.fileno())
            file.chmod(0o644);os.chown(file,0,0)
            command(['/bin/launchctl','bootstrap','system',file])
        return {'optional_preparation_worker_installed':True,'automatic_installation':False}

    def validate_target(self,package):
        require((package/'app/vendor/autoload.php').is_file() and (package/'app/artisan').is_file(), 'Complete target application is required')
        php=package/('runtime/php/php.exe' if self.p['windows'] else 'runtime/php/bin/php')
        python=package/('runtime/python/python.exe' if self.p['windows'] else 'runtime/python/bin/python3')
        for executable in (php,python,package/('runtime/caddy/caddy.exe' if self.p['windows'] else 'runtime/caddy/caddy'),package/('runtime/go2rtc/go2rtc.exe' if self.p['windows'] else 'runtime/go2rtc/go2rtc')):
            require(executable.is_file(),'Complete native service/runtime closure is missing')
        self.program_permissions(package)
        capsule=self.capsule()
        import tempfile
        with tempfile.TemporaryDirectory(prefix='target-readiness-',dir=self.p['recovery']) as tmp:
            work=Path(tmp);env={k:v for k,v in os.environ.items() if k in ('SystemRoot','WINDIR')}
            env.update({'PATH':str(python.parent)+os.pathsep+os.defpath,'PHP_INI_SCAN_DIR':'','PYTHONDONTWRITEBYTECODE':'1','PYTHONNOUSERSITE':'1',
                        'PHILCST_PACKAGED_CPU':'1','PHILCST_PACKAGED_OFFLINE':'1','YOLO_OFFLINE':'true','YOLO_AUTOINSTALL':'false',
                        'YOLO_CONFIG_DIR':str(work/'yolo'),'MPLCONFIGDIR':str(work/'matplotlib')})
            ini=work/'php.ini';text=(self.p['ini']).read_text().replace(str(self.p['release']/'runtime/php'),str(package/'runtime/php'));ini.write_text(text)
            command([php,'-c',ini,capsule/'checks/php-readiness.php'],env)
            command([python,'-I','-B',capsule/'checks/inference.py',package,'--imports-only'],env)
            if not self.p['windows']:command([python,'-I','-B',capsule/'checks/evidence.py',package],env)

    def migrate(self,package,payload,path):
        envfile=payload/'environment/data';from backup_contract import env_values
        values=env_values(envfile);storage=payload/'storage';cache=payload/'migration-cache';cache.mkdir(mode=0o700)
        for name in ('framework/views','framework/sessions','framework/cache/data','logs'):(storage/name).mkdir(mode=0o700,parents=True,exist_ok=True)
        env={k:v for k,v in os.environ.items() if k in ('SystemRoot','WINDIR','COMSPEC')}
        env.update(values);env.update({'APP_ENV':'production','APP_BASE_PATH':str(package/'app'),'APP_STORAGE_PATH':str(storage),'DB_DATABASE':str(payload/'database/data'),
            'DB_URL':'','PHILCST_LIFECYCLE_ROOT':'','MONITORING_SERVICES':'managed','QUEUE_CONNECTION':'sync','CACHE_STORE':'array','SESSION_DRIVER':'array',
            'CAMERA_FILES_PATH':str(storage/'app/camera'),'DEVICE_FILES_PATH':str(storage/'app/devices'),'PHP_INI_SCAN_DIR':'',
            'TMP':str(cache),'TEMP':str(cache),'TMPDIR':str(cache),'APP_CONFIG_CACHE':str(cache/'config.php'),'APP_ROUTES_CACHE':str(cache/'routes.php'),'APP_SERVICES_CACHE':str(cache/'services.php'),
            'APP_PACKAGES_CACHE':str(cache/'packages.php'),'APP_EVENTS_CACHE':str(cache/'events.php')})
        php=package/('runtime/php/php.exe' if self.p['windows'] else 'runtime/php/bin/php')
        if not self.fixture:require(php.is_file(),'Authenticated target PHP is missing')
        self.program_permissions(package)
        ini=cache/'php.ini';text=self.p['ini'].read_text() if self.p['ini'].is_file() else ''
        text=text.replace(str(self.p['release']/'runtime/php'),str(package/'runtime/php'))
        ini.write_text(text+'\nallow_url_fopen=0\nopcache.enable_cli=0\nerror_log="'+str(cache/'php-errors.log')+'"\nsession.save_path="'+str(storage/'framework/sessions')+'"\nsys_temp_dir="'+str(cache)+'"\n')
        arguments=[php,'-c',ini,'-d','open_basedir='+str(package)+os.pathsep+str(payload),'-d',
            'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,dl',package/'app/artisan','migrate','--force','--no-interaction']
        result=subprocess.run(list(map(str,arguments)),env=env,cwd=package/'app',capture_output=True,text=True,timeout=300)
        atomic(cache/'migration-result.json',{'exit':result.returncode,'stdout':result.stdout[-32768:],'stderr':result.stderr[-32768:]})
        require(result.returncode==0,'Authenticated target migration failed on staging; active data was not migrated')
        with __import__('backup_contract').database(payload/'database/data',write=True) as db:
            db.execute('PRAGMA journal_mode=DELETE')
        expected={Path(n).stem for n in load(package/'BUILD-INFO.json')['source']['files'] if n.startswith('database/migrations/')}
        require({r['migration'] for r in __import__('backup_contract').sqlite_info(payload/'database/data')['migrations']}==expected,'Target migration ledger is incomplete')


def installed(root):
    native=NativeUpdate(layout(root));native.admin();return native
