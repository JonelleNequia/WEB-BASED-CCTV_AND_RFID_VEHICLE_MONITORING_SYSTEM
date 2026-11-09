#!/usr/bin/env python3
"""D4 explicit server network controls. Never use on the development checkout.
Only configure/trust-root mutate OS state. Tests inject disposable platform adapters.
No migration, account, update, device or database mutation is performed here.
"""
import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import urllib.request
sys.path.insert(0,str(Path(__file__).resolve().parent))
from policy import HOST,URL,DEFAULT,validate,hosts_plan,env_plan,caddy_config,atomic_file,transaction,require


def run(args,check=True):
    result=subprocess.run(list(map(str,args)),capture_output=True,text=True,timeout=180)
    if check and result.returncode:
        error=re.sub(r'(\w+://)[^/\s]*@',r'\1***@',(result.stderr or result.stdout)[-1200:])
        raise RuntimeError(Path(str(args[0])).name+' failed: '+error)
    return result


def layout(root,windows=None,release_override=None):
    root=Path(root).absolute();windows=(os.name=='nt') if windows is None else windows
    if windows:
        release=root;shared=root/'state';config=root/'config';env=root/'app/.env'
        php=root/'runtime/php/php.exe';caddy=root/'runtime/caddy/caddy.exe'
    else:
        release=Path(release_override).resolve() if release_override else (root/'current').resolve(strict=True);shared=root/'shared';config=shared/'config';env=shared/'.env'
        require(release.is_relative_to((root/'releases').resolve()),'Current release is outside the installation')
        php=release/'runtime/php/bin/php';caddy=release/'runtime/caddy/caddy'
    web=shared/'web'
    return {'root':root,'release':release,'shared':shared,'web':web,'tls':web/'tls','state':shared/'network.json',
        'identity':shared/'tls-identity.json','public_root':shared/'public-root.crt','config':config,'caddyfile':web/'Caddyfile',
        'template':release/'config/Caddyfile.template','env':env,'php':php,'phpini':config/'php.ini','caddy':caddy,'windows':windows,
        'hosts':Path(os.environ.get('SystemRoot','C:\\Windows'))/'System32/drivers/etc/hosts' if windows else Path('/etc/hosts')}


def no_links(path):
    for part in [Path(path),*Path(path).parents]:require(not part.is_symlink() and not (hasattr(part,'is_junction') and part.is_junction()),'Unexpected state/config path redirect: '+str(part))


def preflight_configuration(paths,config=None,adapter=None):
    """Before fresh installer provisioning/copy: read-only mode, hosts, port and exposure checks."""
    adapter=adapter or Native(paths)
    for key in ('shared','web','state','identity','hosts'):no_links(paths[key])
    old=json.loads(paths['state'].read_text()) if paths['state'].exists() else None
    selected=config if config is not None else (old['config'] if old else dict(DEFAULT))
    validated=validate(selected,adapter.inspect()['interfaces'])
    hosts_plan(paths['hosts'].read_bytes().decode('utf-8'),'127.0.0.1')
    adapter.exposure_plan(validated,old)
    return validated


def certificate(paths):
    root=paths['tls']/'pki/authorities/local/root.crt'
    info=json.loads(run([paths['php'],'-c',paths['phpini'],Path(__file__).with_name('certificate.php'),root]).stdout)
    if paths['identity'].exists():
        require(json.loads(paths['identity'].read_text())['sha256']==info['sha256'],'Installation CA identity changed. Stop; explicit root recovery/rotation is required.')
    return info


def public_certificate(paths):
    info=certificate(paths)
    require(paths['public_root'].read_bytes()==(paths['tls']/'pki/authorities/local/root.crt').read_bytes(),
        'Public certificate handoff differs from the installation issuer; no export or trust change performed')
    return info


class Native:
    def __init__(self,paths):self.p=paths
    def win(self,action,config=None):
        # Fresh-install preflight runs from the already verified staging payload,
        # before the installation root contains any application files.
        adapter=Path(__file__).resolve().parents[1]/'windows/network-adapter.ps1'
        args=['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',adapter,'-Root',self.p['root'],'-Action',action]
        if config is not None:args+=['-ConfigJson',json.dumps(config,separators=(',',':'))]
        return json.loads(run(args).stdout or '{}')
    def inspect(self):
        if self.p['windows']:return self.win('Inspect')
        require(sys.platform=='darwin' and os.geteuid()==0,'Run this installed Mac server control with sudo.')
        text=run(['/sbin/ifconfig','-a']).stdout;interfaces=[];name=None
        for line in text.splitlines():
            m=re.match(r'^(\w+):',line)
            if m:name=m[1]
            m=re.search(r'inet ([0-9.]+) netmask (0x[0-9a-f]+)',line)
            if m and name and re.fullmatch(r'en\d+',name):interfaces.append({'name':name,'address':m[1],'prefix':bin(int(m[2],16)).count('1')})
        # Refuse unrelated 443/integration/media/FastCGI listeners before any change.
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'macos'));import control
        for port in (443,8080,1984,8555,8765,9001):
            for pid in control.port_owners(port)+(control.port_owners(port,True) if port==8555 else []):
                require(control.executable_owned(pid,self.p['root']),'Required port '+str(port)+' is owned by another program; nothing was killed')
        return {'interfaces':interfaces}
    def exposure_plan(self,config,old):
        if self.p['windows']:return self.win('Plan',{'config':config,'previous':old})
        fw='/usr/libexec/ApplicationFirewall/socketfilterfw';added=list((old or {}).get('exposure',{}).get('added_apps',[]))
        for path in added:require(path in [str(self.p['caddy']),str(self.p['release']/'runtime/go2rtc/go2rtc')],'Unexpected owned firewall receipt')
        enabled='enabled' in run([fw,'--getglobalstate']).stdout.lower()
        if config['mode']=='lan' and enabled:
            require('enabled' not in run([fw,'--getblockall']).stdout.lower(),'Mac firewall Block All is enabled; an administrator must resolve it explicitly')
            apps=run([fw,'--listapps']).stdout
            for binary in [str(self.p['caddy']),str(self.p['release']/'runtime/go2rtc/go2rtc')]:
                if binary in apps:
                    require('blocked' not in run([fw,'--getappblocked',binary]).stdout.lower().replace('not blocked','allowed'),'An existing firewall entry blocks this binary; nothing was overwritten')
                elif binary not in added:added.append(binary)
        return {'added_apps':added if config['mode']=='lan' else [],'previous_added_apps':list((old or {}).get('exposure',{}).get('added_apps',[])),'firewall_enabled':enabled,'media_scope':'selected IPv4 bind; no packet-level subnet isolation claimed'}
    def exposure(self,state):
        if self.p['windows']:self.win('Apply',state);return
        e=state['exposure'];fw='/usr/libexec/ApplicationFirewall/socketfilterfw'
        for path in e.get('previous_added_apps',[]):
            if path not in e['added_apps']:run([fw,'--remove',path])
        for path in e['added_apps']:
            run([fw,'--add',path]);run([fw,'--unblockapp',path])
    def stop(self):
        if self.p['windows']:self.win('Stop');return
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'macos'));import control
        for role in ['web','live','php']:
            f=Path('/Library/LaunchDaemons')/(control.LABEL+role+'.plist')
            if f.exists():
                import plistlib
                require(control.plist_owned(plistlib.loads(f.read_bytes()),self.p['root'],role),'Unowned service')
                run(['/bin/launchctl','bootout','system',f],False)
    def refresh(self):
        if self.p['windows']:self.win('Refresh');return
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'macos'));import control
        for args in [['config:clear'],['detector:start'],['go2rtc:start'],['config:cache']]:control.artisan(self.p['release'],self.p['shared'],args)
    def start(self):
        if self.p['windows']:self.win('Start');return
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'macos'));import control
        for role in ['php','live','web']:
            f=Path('/Library/LaunchDaemons')/(control.LABEL+role+'.plist')
            if f.exists():run(['/bin/launchctl','bootstrap','system',f])
    def permissions(self):
        if self.p['windows']:self.win('Permissions');return
        import grp
        uid=0;gid=0
        # Root-run configuration writers create app runtime files; restore the existing
        # application-state ownership before returning PHP/live relay to their identity.
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'macos'));import control,pwd
        appuid=pwd.getpwnam('_philcstvms').pw_uid;appgid=grp.getgrnam('_philcstvms').gr_gid
        control.permissions(self.p['shared'],appuid,appgid)
        # Root owns receipt/public handoff; web identity alone can read issuer private state.
        for directory,dirs,files in os.walk(self.p['web']):
            os.chown(directory,uid,gid);os.chmod(directory,0o700)
            for name in files:os.chown(Path(directory)/name,uid,gid);os.chmod(Path(directory)/name,0o600)
        for key in ('state','identity','public_root'):
            if self.p[key].exists():os.chown(self.p[key],0,appgid);os.chmod(self.p[key],0o640)
    def ready(self):
        deadline=time.monotonic()+30
        while time.monotonic()<deadline:
            try:
                if urllib.request.urlopen('http://127.0.0.1:8080/up',timeout=2).status==200:
                    self.https_ready();return
            except Exception:pass
            time.sleep(.5)
        raise ValueError('Internal app readiness failed. Config will be restored; no database reset attempted.')

    def https_ready(self):
        # Pin the installation root and canonical SNI without modifying machine trust.
        import ssl,http.client
        context=ssl.create_default_context(cafile=str(self.p['public_root']))
        with socket.create_connection(('127.0.0.1',443),timeout=3) as raw:
            with context.wrap_socket(raw,server_hostname=HOST) as tls:
                tls.sendall(('GET /up HTTP/1.0\r\nHost: '+HOST+'\r\n\r\n').encode())
                response=http.client.HTTPResponse(tls);response.begin()
                require(response.status==200,'HTTPS hostname/root/app readiness failed')


def configure(paths,config,initialize=False,prepare_only=False,adapter=None):
    lifecycle=paths['shared']/'lifecycle'
    if lifecycle.exists():
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'lifecycle'))
        import maintenance
        from coordination import phase
        current=phase(lifecycle)
        if prepare_only:
            require(current['phase']=='resuming' and current['operation']==os.environ.get('PHILCST_LIFECYCLE_CONTROL'),
                'Initialized installations require owned D5A maintenance for network configuration')
        else:
            preflight_configuration(paths,config,adapter)
            import uuid
            operation=str(uuid.uuid4());manager=maintenance.Coordinator(lifecycle,maintenance.Native(maintenance.layout(paths['root'])))
            manager.adapter.admin();manager.enter(operation)
            result={}
            def prepare():
                previous=os.environ.get('PHILCST_LIFECYCLE_CONTROL');os.environ['PHILCST_LIFECYCLE_CONTROL']=operation
                def verify_access():
                    manager.adapter.start_access(phase(lifecycle)['adapter']);manager.adapter.ready()
                try:result.update(_configure(paths,config,initialize,True,adapter,verification=verify_access,announce=False))
                finally:
                    if previous is None:os.environ.pop('PHILCST_LIFECYCLE_CONTROL',None)
                    else:os.environ['PHILCST_LIFECYCLE_CONTROL']=previous
            manager.resume(operation,prepare)
            print(json.dumps(result))
            return
    elif paths['env'].is_file() and 'PHILCST_LIFECYCLE_ROOT=' in paths['env'].read_text():
        raise ValueError('Persistent D5A state is missing; do not bypass maintenance or generate a replacement identity')
    return _configure(paths,config,initialize,prepare_only,adapter)


def _configure(paths,config,initialize=False,prepare_only=False,adapter=None,verification=None,announce=True):
    adapter=adapter or Native(paths);inspection=adapter.inspect();config=validate(config,inspection['interfaces'])
    for key in ('shared','web','state','identity','env','hosts','caddyfile'):no_links(paths[key])
    require(paths['env'].is_file() and paths['template'].is_file(),'Use an installed D4 package; no live legacy conversion is performed')
    # Preflight hosts and all owned exposure before Caddy can create any initial CA files.
    original_hosts=paths['hosts'].read_bytes().decode('utf-8');planned_hosts=hosts_plan(original_hosts,'127.0.0.1')
    old=json.loads(paths['state'].read_text()) if paths['state'].exists() else None
    exposure=adapter.exposure_plan(config,old)
    root=paths['tls']/'pki/authorities/local/root.crt';key=root.with_suffix('.key')
    if paths['identity'].exists():
        require(all((root.parent/n).is_file() for n in ['root.crt','root.key','intermediate.crt','intermediate.key']),'Persistent CA is incomplete; explicit recovery required. Never regenerate silently.')
        certificate(paths)
    else:require(initialize and not root.exists() and not key.exists(),'Explicit fresh --initialize-ca is required; unmarked or incomplete CA needs review')
    paths['web'].mkdir(parents=True,exist_ok=True);(paths['web']/'logs').mkdir(exist_ok=True)
    if not paths['windows']:paths['web'].chmod(0o700)
    def render(pinned):return caddy_config(paths['template'].read_text(),paths['release']/'app/public',paths['tls'],paths['web']/'logs/access.log',config,paths['windows'],pinned)
    # Validate syntax/provisioning without binding listeners. Existing roots are explicitly pinned.
    with tempfile.TemporaryDirectory(prefix='.validate-',dir=paths['web']) as temporary:
        candidate=Path(temporary)/'Caddyfile';candidate.write_text(render(paths['identity'].exists()))
        run([paths['caddy'],'validate','--config',candidate,'--adapter','caddyfile'])
    info=certificate(paths)
    if not paths['identity'].exists():atomic_file(paths['identity'],json.dumps(info))
    atomic_file(paths['public_root'],root.read_bytes(),0o640)
    state={'schema':'philcst.network.v1','config':config,'exposure':exposure,'root_sha256':info['sha256'],'url':URL}
    changes={paths['env']:env_plan(paths['env'].read_text(),config),paths['caddyfile']:render(True),paths['state']:json.dumps(state,indent=2)+'\n'}
    if not paths['windows']:changes[paths['hosts']]=planned_hosts
    # Hosts is a system file: preserve mode/owner, unlike private state (0600).
    hosts_stat=paths['hosts'].stat()
    def host_permissions():
        if not paths['windows']:
            os.chmod(paths['hosts'],stat.S_IMODE(hosts_stat.st_mode));os.chown(paths['hosts'],hosts_stat.st_uid,hosts_stat.st_gid)
    stopped=False
    def apply():
        nonlocal stopped
        host_permissions();adapter.exposure(dict(state,hosts_text=planned_hosts));adapter.refresh();adapter.permissions()
        if not prepare_only:adapter.start()
    def rollback():
        host_permissions()
        try:
            adapter.stop()
            # Remove the new owned exposure before reinstating the previous scope.
            fallback=dict(old or {'config':dict(DEFAULT),'exposure':{'added_apps':[]}},hosts_text=original_hosts)
            if not paths['windows']:
                fallback=json.loads(json.dumps(fallback));fallback['exposure']['previous_added_apps']=exposure.get('added_apps',[])
            adapter.exposure(fallback);adapter.refresh();adapter.permissions()
            if not prepare_only:adapter.start()
        except Exception as error:raise RuntimeError('Network rollback needs administrator review; public services remain stopped. Data/keys were retained.') from error
    if not prepare_only:adapter.stop();stopped=True
    transaction(changes,apply,rollback,verification or (lambda:None if prepare_only else adapter.ready()))
    if not paths['windows'] and isinstance(adapter,Native):run(['/usr/bin/dscacheutil','-flushcache'])
    result={'url':URL,'mode':config['mode'],'root_sha256':info['sha256'],'trust':'Explicit trust-root and client enrollment required; private CA never exported',
        'collector_delivery':'D5A owned quiescence and retained outbox used; capture pauses intentionally' if (paths['shared']/'lifecycle').exists() else 'D5A not provisioned here; integration briefly interrupts'}
    if announce:print(json.dumps(result))
    return result


def enroll_public_root(paths,info):
    lifecycle=paths['shared']/'lifecycle'
    if lifecycle.exists():
        sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'lifecycle'))
        from coordination import barrier
        with barrier(lifecycle):return _enroll_public_root(paths,info)
    if paths['env'].is_file() and 'PHILCST_LIFECYCLE_ROOT=' in paths['env'].read_text():
        raise ValueError('Persistent D5A state is missing; trust enrollment refused')
    return _enroll_public_root(paths,info)


def _enroll_public_root(paths,info):
    public_certificate(paths)
    if paths['windows']:Native(paths).win('Trust',info)
    else:
        require(os.geteuid()==0,'Explicit system trust enrollment requires sudo')
        # Explicit operator action; serialized against the D5A writer cut.
        receipt=paths['shared']/'server-trust.json'
        prior=json.loads(receipt.read_text()) if receipt.exists() else None
        present=info['sha256'].upper() in run(['/usr/bin/security','find-certificate','-a','-Z','/Library/Keychains/System.keychain'],False).stdout.upper()
        if not present or prior and prior.get('root_added'):
            run(['/usr/bin/security','add-trusted-cert','-d','-r','trustRoot','-p','ssl','-s',HOST,'-k','/Library/Keychains/System.keychain',paths['public_root']])
        else:raise ValueError('Selected root already exists with unowned trust settings. Review it in Keychain Access; the helper will not overwrite unrelated trust.')
        atomic_file(receipt,json.dumps({'sha256':info['sha256'],'root_added':True}))


def main():
    p=argparse.ArgumentParser();p.add_argument('action',choices=['preflight','configure','status','export-root','trust-root']);p.add_argument('--root',type=Path,required=True);p.add_argument('--config',type=Path);p.add_argument('--initialize-ca',action='store_true');p.add_argument('--prepare-only',action='store_true');p.add_argument('--output',type=Path);p.add_argument('--fingerprint');a=p.parse_args();paths=layout(a.root)
    if a.action=='preflight':print(json.dumps(preflight_configuration(paths,json.loads(a.config.read_text()) if a.config else None)));return
    if a.action=='configure':
        require(a.config is not None,'Provide an explicit local/LAN JSON configuration')
        configure(paths,json.loads(a.config.read_text()),a.initialize_ca,a.prepare_only);return
    info=certificate(paths)
    if a.action=='status':
        state=json.loads(paths['state'].read_text());certificates=[info]
        for kind,files in [('intermediate',[paths['tls']/'pki/authorities/local/intermediate.crt']),('leaf',list((paths['tls']/'certificates/local').rglob('*.crt')))]:
            for file in files:
                try:certificates.append(json.loads(run([paths['php'],'-c',paths['phpini'],Path(__file__).with_name('certificate.php'),file,kind]).stdout))
                except Exception as error:certificates.append({'kind':kind,'diagnostic':str(error)})
        try:Native(paths).https_ready();ready=True
        except Exception:ready=False
        print(json.dumps({'network':state,'certificates':certificates,'https_ready':ready,'clock':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'private_ca_exported':False}));return
    if a.action=='export-root':
        require(a.output is not None and not a.output.exists(),'Choose a new public-certificate output file')
        require(not a.output.resolve().is_relative_to(paths['root'].resolve()),'Export public trust outside the installation and immutable payload, never through its web root')
        public_certificate(paths)
        atomic_file(a.output,paths['public_root'].read_bytes(),0o644);print(json.dumps(info));return
    require(a.fingerprint==info['sha256'],'Verify the independently displayed SHA-256 fingerprint before explicit trust enrollment')
    enroll_public_root(paths,info)
    print('Explicitly enrolled the installation public root for '+URL)


if __name__=='__main__':
    try:main()
    except Exception as error:print('PHILCST network: '+str(error),file=sys.stderr);sys.exit(1)
