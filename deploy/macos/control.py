"""Offline Mac installation/control. Only explicit install/start/stop mutate OS services.
Never run install on the development checkout. Fixture tests call pure helpers.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import plistlib
import re
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

DEFAULT_ROOT=Path('/Library/Application Support/PHILCST-VMS')
SERVICE_USER='_philcstvms'
ROLES=('php','web','live','detector','devices','scheduler')
LABEL='local.philcst.vms.'
PORTS=(443,8080,9001,1984,8555,8765)

def run(args,env=None,cwd=None,timeout=180,check=True):
    result=subprocess.run([str(x) for x in args],env=env,cwd=cwd,capture_output=True,text=True,timeout=timeout)
    if check and result.returncode:
        # Native diagnostics may contain paths but never dump installation .env/config.
        raise RuntimeError('Command failed: '+Path(str(args[0])).name+'; '+redact((result.stderr or result.stdout)[-1200:]))
    return result


def redact(value):
    value=re.sub(r'(\w+://)[^/\s]*@',r'\1***@',value)
    value=re.sub(r'(?im)((?:APP_KEY|DETECTOR_API_KEY|password|code)\s*[=:]\s*)[^\s,]+',r'\1***',value)
    return value


def sha(path):
    h=hashlib.sha256()
    with open(path,'rb') as stream:
        for block in iter(lambda:stream.read(1024*1024),b''):h.update(block)
    return h.hexdigest()


def version(value):return tuple(int(x) for x in value.split('.')[:3])+(0,)*(3-len(value.split('.')[:3]))


def verify(payload,installed=False):
    payload=Path(payload).resolve();data=json.loads((payload/'CONTENT-MANIFEST.json').read_text())
    if data.get('format')!=1:raise ValueError('Unsupported content manifest')
    expected=set(data['files'])
    for name,entry in data['files'].items():
        p=Path(name)
        if p.is_absolute() or '..' in p.parts:raise ValueError('Unsafe manifest path')
        f=payload/p
        if any(x.is_symlink() for x in [f,*list(f.parents)[:len(p.parts)-1]]):raise ValueError('Package link refused: '+name)
        if not f.is_file() or sha(f)!=entry['sha256']:raise ValueError('Missing or corrupt dependency: '+name+'. Re-extract the complete trusted offline package; no download attempted.')
    actual={f.relative_to(payload).as_posix() for f in payload.rglob('*') if f.is_file()}
    extras=actual-expected-{'CONTENT-MANIFEST.json','CONTENT-SIGNATURE.json'}
    if installed:extras={n for n in extras if not n.startswith(('app/storage/','app/bootstrap/cache/','app/public/storage/')) and n!='app/.env'}
    if extras:raise ValueError('Unexpected package files: '+', '.join(sorted(extras)[:3]))
    return data


def platform_policy(info,system,architecture,os_version,elevated,free_bytes,required_bytes):
    if system!='Darwin':raise ValueError('Native macOS required')
    if architecture!=info['architecture']:raise ValueError('Wrong package architecture; use the native '+architecture+' candidate. Rosetta is not a supported server baseline.')
    if version(os_version)<version(info['minimum_macos']):raise ValueError('This package requires macOS '+info['minimum_macos']+' or newer, from its actual native dependencies.')
    if not elevated:raise ValueError('Run installation using sudo as a local administrator.')
    if free_bytes<required_bytes*2+2*1024**3:raise ValueError('Insufficient local free space for staging and retained state.')


def env_values(text):
    return {m[1]:m[2].strip().strip('"\'') for m in re.finditer(r'^([A-Z_][A-Z_0-9]*)=(.*)$',text,re.M)}


def existing_policy(root,info):
    root=Path(root);shared=root/'shared';marker=shared/'installation.json'
    if not root.exists():return 'fresh'
    if root.is_symlink():raise ValueError('Installation root may not be a symlink')
    if not any(root.iterdir()):return 'fresh'
    if not marker.is_file():raise ValueError('Unrecognized/legacy nonempty root. Preserve it; conversion/update requires D5.')
    old=json.loads(marker.read_text())
    if old.get('source_sha256')!=info['source']['source_sha256'] or old.get('architecture')!=info['architecture']:raise ValueError('Existing installation requires D5 update/compatibility workflow; no files changed.')
    if old.get('provisioning')=='initializing':raise ValueError('Interrupted first installation; explicit D5 recovery is required before services/provisioning resume.')
    env=shared/'.env';db=shared/'database/database.sqlite'
    if not env.is_file() or not db.is_file():raise ValueError('Existing keys/database incomplete. Explicit D5 recovery required; no replacement database or setup code will be created.')
    values=env_values(env.read_text())
    if values.get('DB_CONNECTION')!='sqlite':raise ValueError('Non-SQLite installation; separate conversion workflow required.')
    if values.get('DB_DATABASE')!=str(db):raise ValueError('Database must remain at the expected local shared-state path.')
    if not values.get('APP_KEY') or not values.get('DETECTOR_API_KEY'):raise ValueError('Existing encryption/integration keys missing; recover them explicitly.')
    return 'same-source'


def no_redirects(path):
    for part in [Path(path),*Path(path).parents]:
        if part.is_symlink():raise ValueError('State/install path redirects through a symlink: '+str(part))


def local_disk(path,mount_output):
    # Longest matching mount wins; network/removable volumes are never SQLite roots.
    candidates=[]
    for line in mount_output.splitlines():
        match=re.match(r'(.+) on (.+) \(([^,)]+)',line)
        if match:
            device,mount,kind=match.groups()
            if str(path)==mount or str(path).startswith(mount.rstrip('/')+'/'):candidates.append((len(mount),device,kind))
    if not candidates:raise ValueError('Cannot determine installation filesystem')
    _,device,kind=max(candidates)
    if kind not in ('apfs','hfs') or not device.startswith('/dev/'):raise ValueError('Use main-computer local APFS/HFS storage; network/mapped databases are unsupported.')


def plist_owned(data,root,role):
    args=data.get('ProgramArguments',[])
    return (data.get('Label')==LABEL+role and len(args)>=5 and str(args[0]).startswith(str(root/'releases')+'/') and str(args[0]).endswith('/runtime/python/bin/python3') and str(args[1]).startswith(str(root/'releases')+'/') and str(args[1]).endswith('/app/deploy/macos/service_runner.py') and args[2]=='--root' and args[3]==str(root) and args[-1]==role)


def identity_record_policy(user,group,shared):
    if user is None and group is None:return 'fresh'
    if user is None or group is None:raise ValueError('Unowned or incomplete service user/group; nothing was changed.')
    uid=re.search(r'^UniqueID: (\d+)$',user,re.M);primary=re.search(r'^PrimaryGroupID: (\d+)$',user,re.M);gid=re.search(r'^PrimaryGroupID: (\d+)$',group,re.M)
    if ('UserShell: /usr/bin/false' not in user or 'NFSHomeDirectory: '+str(shared/'home') not in user
        or not uid or not primary or not gid or primary[1]!=gid[1] or not 400<=int(uid[1])<500 or not 400<=int(gid[1])<500):
        raise ValueError('Service identity belongs to another installation; nothing was changed.')
    return 'owned'


def port_owners(port,udp=False):
    args=['/usr/sbin/lsof','-nP',('-iUDP:' if udp else '-iTCP:')+str(port)]
    if not udp:args+=['-sTCP:LISTEN']
    result=run(args+['-Fp'],check=False)
    return [int(x[1:]) for x in result.stdout.splitlines() if x.startswith('p') and x[1:].isdigit()]


def executable_owned(pid,root):
    result=run(['/usr/sbin/lsof','-p',str(pid),'-a','-d','txt','-Fn'],check=False)
    return any(x.startswith('n'+str(root/'releases')+'/') and '/runtime/' in x for x in result.stdout.splitlines())


def preflight(payload,root,allow_development=False,network_config=None):
    payload=Path(payload).resolve();root=Path(root).absolute();info=json.loads((payload/'BUILD-INFO.json').read_text())
    if info.get('channel')!='release' and not allow_development:raise ValueError('NON-RELEASE development package: explicitly pass --allow-development only in an isolated acceptance environment.')
    if info.get('channel')=='release' and not info.get('publisher_authenticated'):raise ValueError('Release publisher signing/notarization is incomplete; do not install as an authenticated release.')
    manifest=verify(payload)
    if root==payload or root.is_relative_to(payload) or payload.is_relative_to(root):raise ValueError('Extract to a separate staging folder outside the installation root')
    if not root.is_absolute() or str(root)=='/' or re.search(r'[^A-Za-z0-9 /_.-]',str(root)):raise ValueError('Unsupported installation path')
    no_redirects(root)
    for state in ['shared','shared/database','shared/database/database.sqlite','shared/.env','shared/storage','releases']:no_redirects(root/state)
    parent=root
    while not parent.exists():parent=parent.parent
    import platform
    native=run(['/usr/sbin/sysctl','-n','hw.optional.arm64'],check=False).stdout.strip()
    architecture='arm64' if native=='1' else 'x86_64'
    platform_policy(info,platform.system(),architecture,run(['/usr/bin/sw_vers','-productVersion']).stdout.strip(),os.geteuid()==0,shutil.disk_usage(parent).free,manifest['total_bytes'])
    local_disk(parent,run(['/sbin/mount']).stdout)
    mode=existing_policy(root,info)
    if mode!='fresh' and ((root/'shared/lifecycle').exists() or 'PHILCST_LIFECYCLE_ROOT=' in (root/'shared/.env').read_text()):
        raise ValueError('D5A reinstall/update requires the later certified lifecycle path; existing state retained')
    sys.path.insert(0,str(payload/'app/deploy/network'))
    import server as network
    network.preflight_configuration(network.layout(root,release_override=root/'releases'/info['version']),json.loads(Path(network_config).read_text()) if network_config else None)
    for service_user in [SERVICE_USER]:
        account=run(['/usr/bin/dscl','.','-read','/Users/'+service_user],check=False)
        group=run(['/usr/bin/dscl','.','-read','/Groups/'+service_user],check=False)
        identity_record_policy(account.stdout if account.returncode==0 else None,group.stdout if group.returncode==0 else None,root/'shared')
    for role in ROLES:
        file=Path('/Library/LaunchDaemons')/(LABEL+role+'.plist')
        if file.exists() and not plist_owned(plistlib.loads(file.read_bytes()),root,role):raise ValueError('Conflicting launchd service: '+LABEL+role+'; nothing was stopped')
        job=run(['/bin/launchctl','print','system/'+LABEL+role],check=False)
        if job.returncode==0 and not file.exists():raise ValueError('Loaded unowned launchd service without its expected plist')
    for port in PORTS:
        for pid in port_owners(port)+ (port_owners(port,True) if port==8555 else []):
            if not executable_owned(pid,root):raise ValueError('Required port '+str(port)+' belongs to another process; no process was killed')
    processes=run(['/bin/ps','-axo','pid=,command=']).stdout
    for line in processes.splitlines():
        if re.search(r'(?:camera_service|device_service)\.py(?:\s|$)',line) and str(root/'releases')+'/' not in line:raise ValueError('Another detector/device collector is active. Stop its owner explicitly before installation.')
    for path in Path('/Library/LaunchAgents').glob('*philcst*'):
        raise ValueError('Conflicting development LaunchAgent: '+path.name)
    return info,mode


def paths(root):
    root=Path(root);release=(root/'current').resolve(strict=True);shared=root/'shared'
    if not release.is_relative_to((root/'releases').resolve()):raise ValueError('Current release link points outside owned releases')
    return root,release,shared


def runtime_env(release,shared,role=None):
    env={'PATH':str(release/'runtime/python/bin')+':/usr/bin:/bin:/usr/sbin:/sbin',
        'HOME':str(shared/'home'),'APP_ENV':'production','APP_ENV_PATH':str(shared),
        'APP_CONFIG_CACHE':str(shared/'cache/config.php'),'APP_ROUTES_CACHE':str(shared/'cache/routes-v7.php'),
        'APP_SERVICES_CACHE':str(shared/'cache/services.php'),'APP_PACKAGES_CACHE':str(shared/'cache/packages.php'),
        'APP_EVENTS_CACHE':str(shared/'cache/events.php'),'PHP_INI_SCAN_DIR':'','PYTHONDONTWRITEBYTECODE':'1',
        'PYTHONNOUSERSITE':'1','YOLO_OFFLINE':'true','YOLO_AUTOINSTALL':'false','PHILCST_PACKAGED_CPU':'1',
        'PHILCST_PACKAGED_OFFLINE':'1','MJPEG_STREAM_BIND_HOST':'127.0.0.1',
        'YOLO_CONFIG_DIR':str(shared/'storage/app/ultralytics'),'MPLCONFIGDIR':str(shared/'cache/matplotlib'),
        'DETECTOR_PYTHON':str(release/'runtime/python/bin/python3'),'PHILCST_VMS_ROOT':str(shared.parent)}
    if role=='web':
        # Root-run Caddy must never write autosaves/cache through an app-owned home.
        env.update({'HOME':str(shared/'web'),'XDG_CONFIG_HOME':str(shared/'web/config'),
            'XDG_DATA_HOME':str(shared/'web/data'),'XDG_CACHE_HOME':str(shared/'web/cache')})
    if (shared/'lifecycle/identity.json').is_file():env['PHILCST_LIFECYCLE_ROOT']=str(shared/'lifecycle')
    return env


def render_configs(release,shared):
    config=shared/'config';config.mkdir(parents=True,exist_ok=True)
    values={'ROOT':str(shared.parent),'RELEASE':str(release),'SHARED':str(shared)}
    for source,name in [('php.ini.template','php.ini'),('php-fpm.conf.template','php-fpm.conf')]:
        text=(release/'config'/source).read_text()
        for k,v in values.items():text=text.replace('{{'+k+'}}',v)
        if re.search(r'{{\w+}}',text):raise ValueError('Unresolved configuration placeholder')
        (config/name).write_text(text)


def service_plist(root,release,shared,role):
    args=[str(release/'runtime/python/bin/python3'),str(release/'app/deploy/macos/service_runner.py'),'--root',str(root),role]
    data={'Label':LABEL+role,'ProgramArguments':args,'WorkingDirectory':str(release/'app'),'RunAtLoad':True,
        # Native bind probe confirmed 443 requires privilege on the available Mac.
        # Only Caddy and the existing Devices workaround run as root; PHP/OCR stay unprivileged.
        'UserName':'root' if role in ['devices','web'] else SERVICE_USER,'GroupName':SERVICE_USER,
        'EnvironmentVariables':runtime_env(release,shared,role),'ThrottleInterval':10,'Umask':0o007 if role=='devices' else 0o027,
        'StandardOutPath':str(shared/('web/logs/launchd.log' if role=='web' else 'logs/launchd-'+role+'.log')),
        'StandardErrorPath':str(shared/('web/logs/launchd.log' if role=='web' else 'logs/launchd-'+role+'.log'))}
    if role=='scheduler':data['StartInterval']=60
    else:data['KeepAlive']=True
    return data


def identity(shared,service_user=SERVICE_USER):
    existing=run(['/usr/bin/dscl','.','-read','/Users/'+service_user],check=False)
    group=run(['/usr/bin/dscl','.','-read','/Groups/'+service_user],check=False)
    state=identity_record_policy(existing.stdout if existing.returncode==0 else None,group.stdout if group.returncode==0 else None,shared)
    if state=='fresh':
        used=run(['/usr/bin/dscl','.','-list','/Users','UniqueID']).stdout+run(['/usr/bin/dscl','.','-list','/Groups','PrimaryGroupID']).stdout
        ids={int(x) for x in re.findall(r'\b\d+\b',used)};uid=next((n for n in range(450,499) if n not in ids),None)
        if uid is None:raise ValueError('No reserved service identity slot available')
        for kind,items in [('Groups',{'PrimaryGroupID':str(uid)}),('Users',{'UniqueID':str(uid),'PrimaryGroupID':str(uid),'UserShell':'/usr/bin/false','NFSHomeDirectory':str(shared/'home'),'IsHidden':'1','RealName':'PHILCST monitoring service'})]:
            for key,value in items.items():run(['/usr/bin/dscl','.','-create','/'+kind+'/'+service_user,key,value])
    import pwd,grp
    return pwd.getpwnam(service_user).pw_uid,grp.getgrnam(service_user).gr_gid


def permissions(shared,uid,gid):
    for directory,dirs,files in os.walk(shared):
        # The dedicated Caddy identity owns private TLS state; PHP cannot read it.
        dirs[:]=[name for name in dirs if not (Path(directory)==shared and name in ['web','lifecycle'])]
        os.chown(directory,uid,gid);os.chmod(directory,0o750)
        for name in files:
            f=Path(directory)/name
            if f.is_symlink():raise ValueError('Unexpected shared-state link')
            os.chown(f,uid,gid);os.chmod(f,0o640)
    os.chmod(shared/'.env',0o600)
    # The app may write designated child directories, but cannot replace protected
    # network/issuer identity receipts by unlinking them from the shared parent.
    os.chown(shared,0,gid);os.chmod(shared,0o750)
    setup=shared/'setup'
    if setup.exists():
        for directory,dirs,files in os.walk(setup):
            os.chown(directory,0,0);os.chmod(directory,0o700)
            for name in files:os.chown(Path(directory)/name,0,0);os.chmod(Path(directory)/name,0o600)


def artisan(release,shared,args):
    return run([release/'runtime/php/bin/php','-c',shared/'config/php.ini',release/'app/artisan',*args],runtime_env(release,shared),release/'app')


def launch(root,action):
    root,release,shared=paths(root)
    lifecycle_enabled=(shared/'lifecycle').exists() or ((shared/'.env').is_file() and 'PHILCST_LIFECYCLE_ROOT=' in (shared/'.env').read_text())
    if lifecycle_enabled and action=='stop':raise ValueError('D5A Stop requires owned maintenance Enter; no writer was stopped')
    if action=='start' and lifecycle_enabled:
        state=json.loads((shared/'lifecycle/maintenance.json').read_text())
        if state['phase']!='running':raise ValueError('Maintenance persists. Use the owned D5A resume command, not Start.')
    if os.geteuid()!=0:raise ValueError('Start/Stop require sudo to manage system-owned launchd services')
    for role in (ROLES if action=='start' else reversed(ROLES)):
        file=Path('/Library/LaunchDaemons')/(LABEL+role+'.plist')
        if not file.exists():continue
        if not plist_owned(plistlib.loads(file.read_bytes()),root,role):raise ValueError('Refusing unrelated launchd service')
        if action=='stop':run(['/bin/launchctl','bootout','system',file],check=False)
        else:
            job=run(['/bin/launchctl','print','system/'+LABEL+role],check=False)
            if job.returncode:run(['/bin/launchctl','bootstrap','system',file])
            run(['/bin/launchctl','kickstart','system/'+LABEL+role])


def install(payload,root,allow_development=False,network_config=None):
    info,mode=preflight(payload,root,allow_development,network_config);payload=Path(payload);root=Path(root)
    # Native runtime probes before creating installation data or changing services.
    readiness(payload,package_only=True,inference=True)
    release=root/'releases'/info['version'];shared=root/'shared'
    if mode=='same-source':launch(root,'stop')
    root.mkdir(parents=True,exist_ok=True);release.parent.mkdir(exist_ok=True)
    if release.exists():
        # Generated state links are expected only in this exact same-source release.
        verify(release,installed=True)
    else:
        shutil.copytree(payload,release)
        for directory,dirs,files in os.walk(release):
            os.chmod(directory,0o755)
            for name in files:
                f=Path(directory)/name;os.chown(f,0,0);os.chmod(f,0o755 if os.access(f,os.X_OK) or f.suffix=='.command' else 0o644)
    for name in ['database','storage/app/public','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','cache','home','logs','config','setup']:(shared/name).mkdir(parents=True,exist_ok=True)
    env=shared/'.env'
    if mode=='fresh':
        import base64
        values={'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'API_KEY':secrets.token_hex(24),'SHARED':str(shared),'RELEASE':str(release)}
        text=(release/'config/env.template').read_text()
        for k,v in values.items():text=text.replace('{{'+k+'}}',v)
        env.write_text(text);env.chmod(0o600);(shared/'database/database.sqlite').touch(mode=0o600)
        (shared/'installation.json').write_text(json.dumps({'architecture':info['architecture'],'source_sha256':info['source']['source_sha256'],'version':info['version'],'provisioning':'initializing'}))
    # Mutable state is outside root-owned release code; only explicit state links.
    for link,target in [(release/'app/.env',env),(release/'app/storage',shared/'storage'),(release/'app/bootstrap/cache',shared/'cache')]:
        if link.is_symlink():
            if link.resolve()!=target.resolve():raise ValueError('Unexpected release state link')
        else:
            if link.is_dir():shutil.rmtree(link)
            elif link.exists():raise ValueError('Unexpected release state file')
            link.symlink_to(target,target_is_directory=target.is_dir())
    current=root/'current'
    if current.is_symlink():
        if current.resolve()!=release.resolve():raise ValueError('Different active release requires D5')
    elif current.exists():raise ValueError('Unexpected current path')
    else:current.symlink_to(release,target_is_directory=True)
    render_configs(release,shared);uid,gid=identity(shared);permissions(shared,uid,gid)
    if mode=='fresh':
        for args in [['migrate','--force'],['db:seed','--class=Database\\Seeders\\InstallSeeder','--force'],['system:first-admin','--initialize','--output-file='+str(shared/'setup/first-admin.txt'),'--json']]:artisan(release,shared,args)
    for args in [['config:clear'],['storage:link'],['detector:start'],['go2rtc:start']]:
        result=artisan(release,shared,args) if args[0] not in ['detector:start','go2rtc:start'] else run([release/'runtime/php/bin/php','-c',shared/'config/php.ini',release/'app/artisan',*args],runtime_env(release,shared),release/'app',check=False)
    for name in ['config:cache','route:cache','view:cache']:artisan(release,shared,[name])
    permissions(shared,uid,gid)
    sys.path.insert(0,str(release/'app/deploy/network'))
    import server as network
    selection=json.loads(Path(network_config).read_text()) if network_config else (json.loads((shared/'network.json').read_text())['config'] if (shared/'network.json').exists() else dict(network.DEFAULT))
    network.configure(network.layout(root),selection,initialize=not (shared/'tls-identity.json').exists(),prepare_only=True)
    sys.path.insert(0,str(release/'app/deploy/lifecycle'))
    import maintenance
    if mode=='fresh':maintenance.initialize_installed(maintenance.layout(root))
    elif not (shared/'lifecycle/identity.json').is_file():raise ValueError('Missing persistent D5A identity; recover it explicitly')
    else:maintenance.permissions(maintenance.layout(root))
    if mode=='fresh':
        marker=json.loads((shared/'installation.json').read_text());marker['provisioning']='pending personal first-admin setup';(shared/'installation.json').write_text(json.dumps(marker));os.chown(shared/'installation.json',uid,gid)
    for role in ROLES:
        file=Path('/Library/LaunchDaemons')/(LABEL+role+'.plist');file.write_bytes(plistlib.dumps(service_plist(root,release,shared,role)));file.chmod(0o644);os.chown(file,0,0)
    # Small explicit operator entry points; no account data embedded.
    shortcuts=root/'Controls';shortcuts.mkdir(exist_ok=True)
    for action in ['Open','Status','Start','Stop']:
        text='#!/bin/sh\n'+('exec /usr/bin/open https://philcst.vms.internal\n' if action=='Open' else 'exec '+('sudo ' if action in ['Status','Start','Stop'] else '')+'"'+str(release/'runtime/python/bin/python3')+'" "'+str(release/'app/deploy/macos/control.py')+'" '+action.lower()+' --root "'+str(root)+'"\n')
        file=shortcuts/(action+'.command');file.write_text(text);file.chmod(0o755)
    launch(root,'start')
    print('Installed candidate. Explicitly enroll the public root after verifying shared/public-root.crt and its SHA-256. Then open https://philcst.vms.internal. First-admin delivery: '+str(shared/'setup/first-admin.txt')+' (root-only; deliver privately and delete). See NETWORK-SETUP.md. D5 recovery remains pending.')


def readiness(root,package_only=False,inference=False):
    root=Path(root).resolve();release=root if package_only else paths(root)[1];shared=None if package_only else root/'shared'
    info=json.loads((release/'BUILD-INFO.json').read_text());verify(release,installed=not package_only)
    with tempfile.TemporaryDirectory(prefix='philcst-readiness-') as work:
        work=Path(work)
        if package_only:
            phpini=work/'php.ini';text=(release/'config/php.ini.template').read_text().replace('{{SHARED}}',str(work)).replace('{{RELEASE}}',str(release));phpini.write_text(text)
            env=os.environ|{'PHP_INI_SCAN_DIR':'','PYTHONDONTWRITEBYTECODE':'1','PYTHONNOUSERSITE':'1'}
        else:phpini=shared/'config/php.ini';env=runtime_env(release,shared)
        args=[release/'runtime/php/bin/php','-c',phpini,release/'checks/php-readiness.php']
        if not package_only:args+=[shared/'database/database.sqlite']
        php=run(args,env,timeout=60)
        args=[release/'runtime/python/bin/python3','-B',release/'checks/inference.py',release]
        if not inference:args+=['--imports-only']
        python=run(args,env,timeout=360)
        evidence=run([release/'runtime/python/bin/python3','-B',release/'checks/evidence.py',release],env,timeout=60)
        if shared:
            for directory in [shared/'database',shared/'storage',shared/'cache']:
                with tempfile.TemporaryFile(dir=directory) as f:f.write(b'readiness-only')
    result={'application_ready':True,'device_readiness':'not checked; cameras/readers are separate','version':info['version'],'source':info['source']['source_sha256'],'evidence':json.loads(evidence.stdout.strip().splitlines()[-1]),'php':json.loads(php.stdout.strip().splitlines()[-1]),'python':json.loads(python.stdout.strip().splitlines()[-1])}
    print(json.dumps(result));return result


def status(root):
    root,release,shared=paths(root);info=json.loads((release/'BUILD-INFO.json').read_text());services={}
    for role in ROLES:
        result=run(['/bin/launchctl','print','system/'+LABEL+role],check=False);services[role]='running' if 'state = running' in result.stdout else ('scheduled' if role=='scheduler' and result.returncode==0 else 'not running')
    web=False
    try:web=urllib.request.urlopen('http://127.0.0.1:8080/up',timeout=3).status==200
    except Exception:pass
    device={}
    for name,file in [('detector',shared/'storage/app/camera/camera_status.json'),('readers',shared/'storage/app/devices/device_service_status.json')]:
        device[name]={'status_file':file.is_file(),'age_seconds':int(time.time()-file.stat().st_mtime) if file.exists() else None}
    network={}
    try:
        result=run([release/'runtime/python/bin/python3','-B',release/'app/deploy/network/server.py','status','--root',root],check=False);network=json.loads(result.stdout) if result.returncode==0 else {'diagnostic':'Run network status as root for certificate/clock/HTTPS diagnostics.'}
    except Exception:network={'diagnostic':'Network status unavailable'}
    print(json.dumps({'network_https':network,'version':info['version'],'source':info['source']['source_sha256'],'mode':json.loads((shared/'network.json').read_text())['config']['mode'] if (shared/'network.json').exists() else 'unconfigured','url':'https://philcst.vms.internal','services':services,'application_ready':web and all(x in ['running','scheduled'] for x in services.values()),'device_readiness':device,'dependency_readiness':'Run readiness --inference; service state alone does not prove OCR readiness','recovery':'D5 pending'}))


def main():
    parser=argparse.ArgumentParser();parser.add_argument('action',choices=['preflight','install','readiness','status','start','stop','open']);parser.add_argument('--root',type=Path,default=DEFAULT_ROOT);parser.add_argument('--payload',type=Path);parser.add_argument('--network-config',type=Path);parser.add_argument('--allow-development',action='store_true');parser.add_argument('--package-only',action='store_true');parser.add_argument('--inference',action='store_true');args=parser.parse_args()
    if args.action=='preflight':info,mode=preflight(args.payload,args.root,args.allow_development,args.network_config);print(json.dumps({'preflight':'passed','existing':mode,'source':info['source']['source_sha256']}))
    elif args.action=='install':install(args.payload,args.root,args.allow_development,args.network_config)
    elif args.action=='readiness':readiness(args.root,args.package_only,args.inference)
    elif args.action=='status':status(args.root)
    elif args.action in ['start','stop']:launch(args.root,args.action)
    else:run(['/usr/bin/open','https://philcst.vms.internal'])

if __name__=='__main__':
    try:main()
    except Exception as error:print('PHILCST: '+redact(str(error)),file=sys.stderr);sys.exit(1)
