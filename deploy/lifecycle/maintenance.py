#!/usr/bin/env python3
"""D5A explicit local maintenance. No backup/restore/migration/update/transfer engine.
Native adapters mutate only owned services/tasks. Coordinator is separately fixture-testable.
"""
import argparse
from contextlib import contextmanager
import json
import hashlib
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import time
import uuid
sys.path.insert(0,str(Path(__file__).resolve().parent))
from coordination import atomic,barrier,identity,initialize,phase,Paused,jobs


def package_root():return Path(__file__).resolve().parents[2]
sys.path.insert(0,str(package_root()/'school-vehicle-monitoring-detector'))
from durable_delivery import Outbox


def layout(root):
    root=Path(root).absolute();windows=os.name=='nt'
    release=root if windows else (root/'current').resolve(strict=True)
    if not windows and not release.is_relative_to((root/'releases').resolve()):raise ValueError('Current release is outside the owned installation')
    return {'root':root,'release':release,'app':release/'app','base':root/('state' if windows else 'shared')/'lifecycle',
        'php':release/('runtime/php/php.exe' if windows else 'runtime/php/bin/php'),
        'ini':root/('config/php.ini' if windows else 'shared/config/php.ini'),'windows':windows}


def command(args,env=None):
    result=subprocess.run(list(map(str,args)),env=env,capture_output=True,text=True,timeout=90)
    if result.returncode:raise RuntimeError('Owned lifecycle command failed: '+Path(str(args[0])).name+'; inspect private operator logs')
    return result.stdout


def process_alive(pid):
    if os.name=='nt':
        import ctypes
        from ctypes import wintypes
        kernel=ctypes.WinDLL('kernel32',use_last_error=True)
        kernel.OpenProcess.argtypes=[wintypes.DWORD,wintypes.BOOL,wintypes.DWORD];kernel.OpenProcess.restype=wintypes.HANDLE
        kernel.CloseHandle.argtypes=[wintypes.HANDLE]
        handle=kernel.OpenProcess(0x1000,False,pid)
        alive=bool(handle) or ctypes.get_last_error()!=87
        if handle:kernel.CloseHandle(handle)
        return alive
    try:os.kill(pid,0);return True
    except ProcessLookupError:return False


def secret_fingerprints(envfile):
    values={}
    for line in Path(envfile).read_text().splitlines():
        if '=' in line and not line.startswith('#'):
            key,value=line.split('=',1)
            if key in ('APP_KEY','DETECTOR_API_KEY'):values[key]=value.strip().strip('"\'')
    if set(values)!= {'APP_KEY','DETECTOR_API_KEY'} or not all(values.values()):raise ValueError('Persistent encryption/integration secrets are missing; never replace them automatically')
    return {key:hashlib.sha256(value.encode()).hexdigest() for key,value in values.items()}


class Native:
    def __init__(self,paths):self.p=paths
    def admin(self):
        if self.p['windows']:
            import ctypes
            if not ctypes.windll.shell32.IsUserAnAdmin():raise PermissionError('Run installed maintenance as Administrator')
        elif sys.platform!='darwin' or os.geteuid()!=0:raise PermissionError('Run installed Mac maintenance with sudo')
        if not (self.p['release']/'BUILD-INFO.json').is_file() or not (self.p['app']/'artisan').is_file():
            raise ValueError('Use a verified installed package, never the working development checkout')
        for path in [self.p['root'],self.p['base'],*self.p['base'].parents]:
            if path.is_symlink() or hasattr(path,'is_junction') and path.is_junction():raise ValueError('Unexpected lifecycle state redirect')
    def windows(self,action,receipt=None):
        return json.loads(command(['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',
            self.p['app']/'deploy/windows/lifecycle-adapter.ps1','-Root',self.p['root'],'-Action',action,
            '-ReceiptJson',json.dumps(receipt or {})],os.environ|{'PHILCST_LIFECYCLE_CONTROLLER_PID':str(os.getpid())}) or '{}')
    def mac(self):
        sys.path.insert(0,str(self.p['app']/'deploy/macos'));import control
        return control
    def snapshot(self):
        self.admin()
        if self.p['windows']:return self.windows('Snapshot')
        ctl=self.mac();loaded=[]
        import plistlib
        for role in ctl.ROLES:
            file=Path('/Library/LaunchDaemons')/(ctl.LABEL+role+'.plist')
            if not file.is_file() or not ctl.plist_owned(plistlib.loads(file.read_bytes()),self.p['root'],role):raise ValueError('Missing/unowned managed LaunchDaemon')
            result=ctl.run(['/bin/launchctl','print','system/'+ctl.LABEL+role],check=False)
            if result.returncode==0:loaded.append(role)
        return {'loaded':loaded}
    def stop_roles(self,roles):
        ctl=self.mac()
        import plistlib
        for role in roles:
            file=Path('/Library/LaunchDaemons')/(ctl.LABEL+role+'.plist')
            if not ctl.plist_owned(plistlib.loads(file.read_bytes()),self.p['root'],role):raise ValueError('Unowned service; nothing killed')
            ctl.run(['/bin/launchctl','bootout','system',file],check=False)
    def quiesce(self,receipt):
        if self.p['windows']:self.windows('Quiesce',receipt)
        else:self.stop_roles(['scheduler','devices','detector'])
    def stop(self,receipt):
        if self.p['windows']:self.windows('Stop',receipt)
        else:self.stop_roles(['live','web','php'])
    def verify(self,receipt):
        if self.p['windows']:self.windows('Verify',receipt);return
        ctl=self.mac()
        for role in ctl.ROLES:
            if ctl.run(['/bin/launchctl','print','system/'+ctl.LABEL+role],check=False).returncode==0:
                raise ValueError('An owned service is still loaded; quiescence not established')
        # Inspect executable/cmdline ownership, not merely a free or busy port.
        for pid in ctl.port_owners(8080)+ctl.port_owners(8765)+ctl.port_owners(1984):
            if ctl.executable_owned(pid,self.p['root']):raise ValueError('Owned child remains after stop')
        for line in command(['/bin/ps','-axo','pid=,command=']).splitlines():
            fields=line.strip().split(None,1)
            if len(fields)==2 and fields[0].isdigit() and int(fields[0])!=os.getpid():
                pid=int(fields[0]);text=fields[1]
                packaged=str(self.p['release']/'runtime')+'/' in text and ctl.executable_owned(pid,self.p['root'])
                standalone=str(self.p['app'])+'/' in text and any(x in text for x in ('camera_service.py','device_service.py','detector_service.py','/artisan'))
                if standalone:
                    exe=Path(command(['/bin/ps','-p',str(pid),'-o','comm=']).strip()).name
                    standalone=exe.startswith(('python','Python','php'))
                if packaged or standalone:raise ValueError('Owned runtime/standalone writer remains; quiescence refused, no unrelated process killed')
    def check(self):
        info=json.loads((self.p['release']/'BUILD-INFO.json').read_text());state=phase(self.p['base'])
        expected={'source_sha256':info['source']['source_sha256'],'architecture':info['architecture'],'version':info['version']}
        if state.get('installed_build')!=expected:raise ValueError('Installed source/runtime binding changed; later certified D5 compatibility/promotion is required')
        if state.get('secret_fingerprints')!=secret_fingerprints(self.p['app']/'.env'):
            raise ValueError('Persistent secret identity changed; explicit verified recovery/rotation required')
        command([self.p['php'],'-c',self.p['ini'],self.p['app']/'artisan','system:delivery-check'],self.env())
        sys.path.insert(0,str(self.p['app']/'deploy/network'));import server
        paths=server.layout(self.p['root']);server.public_certificate(paths)
    def env(self):
        return os.environ|{'PHILCST_LIFECYCLE_ROOT':str(self.p['base']),
            'PHILCST_LIFECYCLE_CONTROL':str(phase(self.p['base']).get('operation') or '')}
    def start_access(self,receipt):
        if self.p['windows']:self.windows('Access',receipt);return
        ctl=self.mac()
        for role in ['php','live','web']:
            if role in receipt['loaded']:
                file=Path('/Library/LaunchDaemons')/(ctl.LABEL+role+'.plist')
                import plistlib
                if not ctl.plist_owned(plistlib.loads(file.read_bytes()),self.p['root'],role):raise ValueError('Unowned access service')
                if ctl.run(['/bin/launchctl','print','system/'+ctl.LABEL+role],check=False).returncode:
                    command(['/bin/launchctl','bootstrap','system',file])
    def ready(self):
        sys.path.insert(0,str(self.p['app']/'deploy/network'));import server
        server.Native(server.layout(self.p['root'])).ready()
    def reopen(self,receipt):
        if self.p['windows']:self.windows('Resume',receipt);return
        ctl=self.mac()
        for role in ['detector','devices','scheduler']:
            if role in receipt['loaded']:
                file=Path('/Library/LaunchDaemons')/(ctl.LABEL+role+'.plist')
                import plistlib
                if not ctl.plist_owned(plistlib.loads(file.read_bytes()),self.p['root'],role):raise ValueError('Unowned producer service')
                command(['/bin/launchctl','bootstrap','system',file])
    def drain(self,seconds):
        # API secrets are read now from the protected local environment, never from the spool.
        envfile=self.p['app']/'.env';settings={}
        for line in envfile.read_text().splitlines():
            if '=' in line and not line.startswith('#'):
                key,value=line.split('=',1);settings[key]=value.strip().strip('"\'')
        box=Outbox(self.p['base']);deadline=time.monotonic()+seconds
        while time.monotonic()<deadline:
            result=box.deliver_one('http://127.0.0.1:8080/api/v1/integration/deliveries',settings.get('DETECTOR_API_KEY',''))
            if result is None:time.sleep(.2)
            if sum(v for k,v in box.status()['counts'].items() if k!='acked')==0:break


class Coordinator:
    def __init__(self,base,adapter):self.base=Path(base);self.adapter=adapter;identity(base)
    @contextmanager
    def owned(self,operation):
        uuid.UUID(operation);lock=self.base/'operation.lock'
        temporary=self.base/('.owner-'+uuid.uuid4().hex)
        atomic(temporary,{'schema':1,'operation':operation,'pid':os.getpid()})
        try:
            try:os.link(temporary,lock)
            except FileExistsError:raise ValueError('Another or interrupted maintenance owner holds the lock. Explicit recovery required.')
        finally:temporary.unlink()
        try:yield
        finally:lock.unlink()
    def set(self,state,phase_name,**extra):
        value=dict(state,phase=phase_name,**extra);atomic(self.base/'maintenance.json',value);return value
    def status(self):
        lock=self.base/'operation.lock'
        result={'identity':identity(self.base),'maintenance':{k:v for k,v in phase(self.base).items() if k!='secret_fingerprints'},'operation_lock':lock.exists(),
            'lock_owner':json.loads(lock.read_text()) if lock.is_file() else None}
        try:result['active_producer_work']=jobs(self.base)
        except Exception:result.update(active_producer_work=None,barrier_error='Persistent writer barrier is missing or inconsistent; recovery required')
        try:result['outbox']=Outbox(self.base).status()
        except Exception:result.update(outbox=None,delivery_error='Persistent spool/identity is missing or inconsistent; recovery required')
        return result
    def enter(self,operation,drain_seconds=10,producer_wait_seconds=30):
        with self.owned(operation):
            state=phase(self.base)
            if state['phase']=='running':
                receipt=self.adapter.snapshot()
                state=self.set(state,'quiescing',operation=operation,entered_at=time.time(),adapter=receipt,services_touched=False)
            elif state['phase']=='quiescing' and state['operation']==operation and not state.get('services_touched'):
                receipt=state['adapter']
            else:raise ValueError('An existing maintenance operation must be verified/recovered/resumed explicitly')
            try:
                deadline=time.monotonic()+max(0,min(60,producer_wait_seconds))
                while True:
                    # Wait for registrations that began before admission closed to commit.
                    with barrier(self.base,'control',timeout=30):active=jobs(self.base)
                    if not active:break
                    if time.monotonic()>=deadline:raise Paused('Producer work has not persisted/finished; quiescence refused. Continue Enter with the same owner or Resume; never kill it to claim readiness.')
                    time.sleep(.1)
                state=self.set(state,'quiescing',services_touched=True)
                self.adapter.quiesce(receipt)
                self.adapter.drain(max(0,min(60,drain_seconds)))
                # Wait for the actual PHP/Python writer reservation, not just request admission.
                with barrier(self.base,'control',timeout=30):state=self.set(state,'fenced')
                self.adapter.stop(receipt);self.adapter.verify(receipt)
                with barrier(self.base,'control',timeout=30):
                    self.adapter.check()
                    pending=sum(v for k,v in Outbox(self.base).status()['counts'].items() if k!='acked')
                    state=self.set(state,'quiescent_pending' if pending else 'quiescent',pending=pending)
                return state
            except Paused:
                if not state.get('services_touched'):
                    self.set(state,'quiescing',error='Producer drain timed out; not quiescent. Active work retained in the producer; continue Enter or Resume explicitly.')
                    raise
                self.set(state,'failed',error='Quiescence failed; writers stay fenced.');raise
            except BaseException:
                self.set(state,'failed',error='Quiescence failed; do not reopen writers. Inspect owned services and retained deliveries.')
                raise
    def verify(self,operation):
        with self.owned(operation):
            state=phase(self.base)
            if state['operation']!=operation or state['phase'] not in ('quiescent','quiescent_pending'):raise ValueError('Not a completed owned quiescence')
            with barrier(self.base,'control',timeout=5):
                self.adapter.verify(state['adapter']);self.adapter.check();Outbox(self.base)
            return self.status()
    def resume(self,operation,prepare=None):
        with self.owned(operation):
            state=phase(self.base)
            if state['operation']==operation and state['phase']=='quiescing' and not state.get('services_touched'):
                # No OS writers were stopped and no D5B transaction began. Abort admission-only
                # quiescence after checks; do not kill in-memory work just to resume it.
                with barrier(self.base,'control',timeout=30):
                    Outbox(self.base);self.adapter.check();self.adapter.ready()
                    self.set(state,'running',operation=None,entered_at=None,error=None)
                return
            if state['operation']!=operation or state['phase'] not in ('quiescent','quiescent_pending','failed','fenced'):
                raise ValueError('Resume requires the exact existing maintenance operation')
            try:active=jobs(self.base)
            except Exception:
                self.set(state,'failed',error='Persistent barrier is missing or inconsistent; resume refused without replacement')
                raise
            if active:
                self.set(state,'failed',error='Outstanding producer work requires review; nothing was killed')
                raise Paused('Outstanding producer work requires completion or explicit dead-producer review; nothing was killed')
            try:
                self.adapter.quiesce(state['adapter']);self.adapter.stop(state['adapter']);self.adapter.verify(state['adapter'])
                with barrier(self.base,'control',timeout=30):
                    for path in [self.base/'coordination/barrier.sqlite',self.base/'delivery/outbox.sqlite']:
                        if not path.is_file():raise ValueError('Missing persistent state; no empty replacement is permitted')
                        db=sqlite3.connect(path)
                        try:
                            if db.execute('PRAGMA quick_check').fetchone()[0]!='ok':raise ValueError('Local state integrity failed')
                        finally:db.close()
                    Outbox(self.base)  # verifies retained installation/generation before replay
                    self.adapter.check()
                    state=self.set(state,'resuming')
                if prepare:prepare()
                self.adapter.check();self.adapter.start_access(state['adapter']);self.adapter.ready()
                # Producers remain closed until consistency and access readiness both pass.
                self.set(state,'running',operation=None,entered_at=None,error=None)
                self.adapter.reopen(state['adapter'])
            except BaseException:
                self.set(state,'failed',error='Resume failed; writers remain fenced. Retained work was not discarded.')
                try:self.adapter.quiesce(state['adapter']);self.adapter.stop(state['adapter'])
                except Exception:pass
                raise
    def recover(self,operation):
        lock=self.base/'operation.lock';state=phase(self.base)
        if state['operation'] not in (None,operation) or not lock.is_file():raise ValueError('No matching interrupted owner')
        owner=json.loads(lock.read_text())
        if owner.get('operation')!=operation:raise ValueError('Lock ownership mismatch')
        if process_alive(owner['pid']):raise ValueError('Recorded process is still present; inspect ownership, do not steal its lock')
        # Recovery never opens admission. It only makes an interrupted operation reviewable.
        receipt=state.get('adapter') or self.adapter.snapshot()
        target='quiescing' if not state.get('services_touched') and state['phase'] in ('running','quiescing') else 'failed'
        self.set(state,target,operation=operation,adapter=receipt,error='Explicit interrupted-owner recovery; admission remains closed. Verify/continue Enter or Resume separately.')
        lock.unlink()

    def review_dead_producers(self,operation):
        with self.owned(operation):
            state=phase(self.base)
            if state['operation']!=operation or state['phase'] not in ('quiescing','failed','fenced'):
                raise ValueError('Review requires an existing owned maintenance operation')
            reviewed=[]
            with barrier(self.base,'control') as db:
                for row in jobs(self.base):
                    if not process_alive(row['pid']):
                        db.execute('DELETE FROM producer_jobs WHERE token=?',(row['token'],));reviewed.append(row)
                self.set(state,state['phase'],reviewed_dead_producers=state.get('reviewed_dead_producers',[])+reviewed,
                    error='Explicit dead-producer review. Persisted deliveries retained; unpersisted in-memory work cannot be reconstructed.')
            return reviewed


def initialize_installed(paths):
    adapter=Native(paths);adapter.admin()
    envfile=paths['app']/'.env';text=envfile.read_text()
    if 'PHILCST_LIFECYCLE_ROOT=' in text:raise ValueError('Existing lifecycle configuration must be reviewed, not overwritten')
    fingerprints=secret_fingerprints(envfile)
    command([paths['php'],'-c',paths['ini'],paths['app']/'artisan','system:delivery-initializable'])
    info=initialize(paths['base']);Outbox(paths['base'],initialize=True)
    build=json.loads((paths['release']/'BUILD-INFO.json').read_text())
    atomic(paths['base']/'maintenance.json',dict(phase(paths['base']),secret_fingerprints=fingerprints,installed_build={
        'source_sha256':build['source']['source_sha256'],'architecture':build['architecture'],'version':build['version']}))
    # Root-only metadata and group-writable coordination/spool. Never grant the app state ownership.
    permissions(paths)
    with envfile.open('a') as f:f.write('\nPHILCST_LIFECYCLE_ROOT="'+str(paths['base'])+'"\n')
    command([paths['php'],'-c',paths['ini'],paths['app']/'artisan','config:clear'])
    command([paths['php'],'-c',paths['ini'],paths['app']/'artisan','system:delivery-init'],adapter.env())
    command([paths['php'],'-c',paths['ini'],paths['app']/'artisan','config:cache'],adapter.env())
    permissions(paths);return info


def permissions(paths):
    if paths['windows']:Native(paths).windows('Permissions');return
    import grp
    gid=grp.getgrnam('_philcstvms').gr_gid;base=paths['base']
    os.chown(base,0,gid);base.chmod(0o750)
    for name in ['identity.json','maintenance.json']:os.chown(base/name,0,gid);(base/name).chmod(0o640)
    for name in ['coordination','delivery']:
        for directory,dirs,files in os.walk(base/name):
            os.chown(directory,0,gid);os.chmod(directory,0o2770)
            for file in files:os.chown(Path(directory)/file,0,gid);os.chmod(Path(directory)/file,0o660)


def main():
    p=argparse.ArgumentParser();p.add_argument('action',choices=['status','initialize','enter','verify','resume','recover','review-dead-producers','retry','inspect','export-evidence']);p.add_argument('--root',type=Path,required=True);p.add_argument('--operation');p.add_argument('--delivery');p.add_argument('--output',type=Path);p.add_argument('--drain-seconds',type=int,default=10);p.add_argument('--producer-wait-seconds',type=int,default=30);a=p.parse_args()
    paths=layout(a.root);native=Native(paths);native.admin()
    if a.action=='initialize':print(json.dumps(initialize_installed(paths)));return
    ctl=Coordinator(paths['base'],native)
    if a.action=='status':result=ctl.status()
    elif a.action=='enter':result=ctl.enter(a.operation,a.drain_seconds,a.producer_wait_seconds)
    elif a.action=='verify':result=ctl.verify(a.operation)
    elif a.action=='resume':ctl.resume(a.operation);result=ctl.status()
    elif a.action=='recover':ctl.recover(a.operation);result=ctl.status()
    elif a.action=='review-dead-producers':result=ctl.review_dead_producers(a.operation)
    elif a.action=='inspect':result=Outbox(paths['base']).inspect(a.delivery)
    elif a.action=='export-evidence':
        if a.output is None:raise ValueError('Choose a new private output directory')
        result=Outbox(paths['base']).export_media(a.delivery,a.output,paths['root'])
    else:
        with barrier(paths['base']):Outbox(paths['base']).requeue(a.delivery)
        result=ctl.status()
    print(json.dumps(result,indent=2))


if __name__=='__main__':
    try:main()
    except Exception as e:print('PHILCST maintenance: '+str(e),file=sys.stderr);sys.exit(1)
