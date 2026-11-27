"""Cross-version transaction; D5B same-source restore is never an update shortcut."""
import json
import os
from pathlib import Path
import shutil
import uuid
from contracts import c, canonical, sha, load, require, binding, exact_path, installed_state
from backup_contract import tree_hash, inventory, sqlite_info, snapshot_sqlite, database, no_links
from coordination import atomic, barrier, phase
from recovery import Recovery, copy_tree, copy_file, durable_tree, sync_dir
from recovery_platform import compatibility
from manager import Manager


def extract(package, envelope, destination):
    require(not destination.exists(),'New private extraction destination required');destination.mkdir(mode=0o700)
    for name,size,stream in c.archive_members(package,envelope['signed']['package']['format']):
        rel=c.safe_path(name).removeprefix('PHILCST-VMS/');
        if name=='PHILCST-VMS' or stream is None:continue
        path=destination/rel;path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        with path.open('xb') as out:shutil.copyfileobj(stream,out,1024**2);out.flush();os.fsync(out.fileno())
        path.chmod(0o600)
    return destination


def program_hash(path, role):
    if role=='current':return str(Path(path).resolve()) if Path(path).is_symlink() else None
    if role=='app' and Path(path).exists():
        entries={}
        for directory,dirs,files in os.walk(path):
            parent=Path(directory).relative_to(path)
            dirs[:]=[d for d in dirs if (parent/d).as_posix() not in ('storage','bootstrap/cache')]
            for d in dirs:no_links(Path(directory)/d)
            for name in files:
                rel=(parent/name).as_posix()
                if rel in ('.env','database/database.sqlite','database/database.sqlite-wal','database/database.sqlite-shm','database/database.sqlite-journal'):continue
                file=Path(directory)/name;no_links(file);entries[rel]={'size':file.stat().st_size,'sha256':c.sha_file(file)}
    else:entries=inventory(path)
    if entries is None:return None
    return sha(canonical(entries))


def program_release_hash(release):
    # Mutable links are controller-owned; hash every immutable code/runtime byte.
    return sha(canonical({name:program_hash(Path(release)/name,'app' if name=='app' else name) for name in ('app','runtime','scripts','checks','config','BUILD-INFO.json','CONTENT-MANIFEST.json','SOURCE-INFO.json','UPDATE-PATHS.json')}))


class UpdateRecovery(Recovery):
    def __init__(self,adapter,manager,checkpoint=None):
        super().__init__(adapter,checkpoint);self.manager=manager;self.context=None

    def validate_payload(self,payload,components,expected=None):
        old=self.p['release']
        if self.context:
            if '/incoming/' in str(payload) or 'proof-forward' in str(payload): self.p['release']=Path(self.context['target_metadata'])
            elif '/current/' in str(payload) or 'proof-rollback' in str(payload): self.p['release']=Path(self.context['prior_metadata'])
        try:return super().validate_payload(payload,components,expected)
        finally:self.p['release']=old

    def validate_trust(self,directory):
        # Staging still contains the preserved current trust/state until all data
        # validation finishes. Production roots never come from candidate files.
        if self.context and ('/incoming/' in str(directory) or '/current/' in str(directory)):
            old=self.p['release'];self.p['release']=Path(self.context['prior_metadata'])
            try:return super().validate_trust(directory)
            finally:self.p['release']=old
        return super().validate_trust(directory)

    def plan(self,candidate):
        self.host();self.ledger();envelope,package=self.manager.validate(candidate)
        state=self.manager.store.read()['state'];schema=sqlite_info(self.p['targets']['database'])
        require({k:v for k,v in state.items() if k not in ('os_version','max_expanded_bytes','channel')}=={k:v for k,v in installed_state(self.adapter).items() if k not in ('os_version','max_expanded_bytes','channel')},'Protected installed version/source/runtime state disagrees with active program')
        self.adapter.verify_installed_program();self.adapter.check_configuration()
        expected={Path(n).stem for n in load(self.p['release']/'BUILD-INFO.json')['source']['files'] if n.startswith('database/migrations/')}
        require({r['migration'] for r in schema['migrations']}==expected,'Actual installed migration ledger differs from its declared source')
        expanded=self.manager.last_validation['expanded_bytes']
        persistent=sum(item['size'] for role,p in self.p['targets'].items() if role!='update_trust' for item in (inventory(p) or {}).values())
        required=expanded*3+persistent*3
        self.adapter.free(required)
        with self.scratch() as tmp:
            payload=extract(package,envelope,tmp/'package');path=exact_path(payload,state,schema)
            self.adapter.validate_target(payload)
            if phase(self.base).get('ownership_required'):
                require((payload/'app/deploy/lifecycle/ownership_guard.py').is_file(),'Target update would remove the required D5D ownership fence')
                require('checkOwnership' in (payload/'app/app/Services/LifecycleBarrier.php').read_text(),'Target update does not preserve PHP ownership admission')
            for name,digest in load(payload/'BUILD-INFO.json')['source']['files'].items():
                if name.startswith('database/migrations/'):
                    require((payload/'app'/name).is_file() and c.sha_file(payload/'app'/name)==digest,'Authenticated migration inventory disagrees with program bytes')
        value={'schema':'philcst.install-update-plan.v1','candidate':candidate,'manifest_sha256':sha(canonical(envelope)),
            'from':state,'to':binding(load_info(package,envelope)),'path':path,'installation':self.host(),
            'current_schema':schema,'security_sha256':sha(canonical(__import__('recovery_security').security_state(self.p['targets']['database']))),'security_epoch':self.ledger()['epoch'],'trust_sha256':sha(canonical(self.manager.store.read()['trust'])),
            'required_disk_bytes':required,'downtime':'Monitoring stops for coherent backup, copy-based migrations, program/data promotion and readiness. Uncaptured traffic is unrecoverable.',
            'recovery':'Retained independent prior program/runtime controller, coherent safety backup and verified staged rollback; no automatic restart on failure.'}
        return dict(value,confirmation=sha(canonical(value)))

    def stage_program(self,journal,package):
        directory=self.operation_dir(journal['operation']);prior=directory/'prior-metadata';prior.mkdir(mode=0o700)
        copy_file(self.p['release']/'BUILD-INFO.json',prior/'BUILD-INFO.json')
        target=directory/'target-metadata';target.mkdir(mode=0o700);copy_file(package/'BUILD-INFO.json',target/'BUILD-INFO.json')
        self.context={'package':str(package),'prior_metadata':str(prior),'target_metadata':str(target)}
        return self.save(journal,context=self.context)

    def prepare_program(self,journal):
        package=Path(journal['context']['package']);directory=self.operation_dir(journal['operation']);moves=[]
        if self.p['windows']:
            app=package/'app'
            # New program initially carries exact old mutable bytes. D5B then
            # promotes the independently migrated/reconciled data under its fence.
            for rel in ('.env','storage','database/database.sqlite','bootstrap/cache'):
                src=self.p['app']/rel;dst=app/rel
                if dst.exists():shutil.rmtree(dst) if dst.is_dir() else dst.unlink()
                if src.exists():copy_tree(src,dst)
            for suffix in ('-wal','-shm','-journal'):
                src=Path(str(self.p['targets']['database'])+suffix)
                if src.exists():copy_file(src,app/'database'/src.name)
            roles=sorted({p.name for p in package.iterdir()}-{'config','state','shared','Controls','logs','current','releases'})
            require({'app','runtime','BUILD-INFO.json','CONTENT-MANIFEST.json'}<=set(roles),'Incomplete program promotion inventory')
            for role in roles:
                target=self.p['root']/role;prepared=package/role
                require(prepared.exists() or not target.exists(),'Required program component missing')
                moves.append({'role':role,'target':str(target),'prepared':str(prepared),'old':str(directory/'program-old'/role),
                              'old_hash':program_hash(target,role),'new_hash':program_hash(prepared,role)})
        else:
            # New immutable release lives beside old one; old controller remains usable.
            release=self.p['root']/'releases'/('update-'+journal['candidate'])
            require(not release.exists(),'Target immutable release already exists; inspect retained operation')
            os.rename(package,release);sync_dir(release.parent)
            self.context['package']=str(release);journal['context']=dict(self.context)
            for rel,target in (('.env',self.p['targets']['environment']),('storage',self.p['targets']['storage']),('bootstrap/cache',self.p['root']/'shared/cache')):
                link=release/'app'/rel
                if link.exists():shutil.rmtree(link) if link.is_dir() else link.unlink()
                link.parent.mkdir(parents=True,exist_ok=True);link.symlink_to(target,target_is_directory=target.is_dir())
            moves=[{'role':'current','target':str(self.p['root']/'current'),'prepared':str(release),'old':str(self.p['release']),
                    'old_hash':str(self.p['release']),'new_hash':str(release),'new_content':program_release_hash(release),'old_content':program_release_hash(self.p['release'])}]
        self.adapter.program_permissions(package if self.p['windows'] else Path(self.context['package']))
        if self.p['windows']:
            for role,rel in (('environment','.env'),('storage','storage'),('database','database/database.sqlite'),('storage','bootstrap/cache')):
                self.adapter.permissions_for(role,package/'app'/rel)
        return self.save(journal,program_moves=moves)

    def program(self,journal,rollback=False):
        self.context=journal['context'];directory=self.operation_dir(journal['operation'])
        if rollback and self.p['windows']:
            oldapp=directory/'program-old/app'
            if oldapp.exists():
                # Preserve the reconciled rollback DB/security epoch when switching code.
                for rel in ('.env','storage','database/database.sqlite','bootstrap/cache'):
                    src=self.p['app']/rel;dst=oldapp/rel
                    if dst.exists():shutil.rmtree(dst) if dst.is_dir() else dst.unlink()
                    if src.exists():copy_tree(src,dst)
                for suffix in ('-wal','-shm','-journal'):
                    require(not Path(str(self.p['targets']['database'])+suffix).exists(),'Unexpected active SQLite sidecar after data rollback')
                    (oldapp/'database'/('database.sqlite'+suffix)).unlink(missing_ok=True)
        for move in (reversed(journal['program_moves']) if rollback else journal['program_moves']):
            role=move['role'];target=Path(move['target']);desired=move['old_hash' if rollback else 'new_hash'];actual=program_hash(target,role)
            if role=='current':
                require(program_release_hash(Path(desired))==move['old_content' if rollback else 'new_content'],'Immutable release changed after staging; recovery refused')
            if actual==desired:continue
            mutable_stub=rollback and role=='app' and actual==sha(canonical({})) and program_hash(Path(move['old']),'app')==desired
            require(actual in (None,move['old_hash'],move['new_hash']) or mutable_stub,'Unexpected program bytes; do not guess recovery')
            self.save(journal,program_intent=('rollback:' if rollback else 'promote:')+role)
            if role=='current':
                nextlink=target.with_name('.update-current-'+journal['operation']);nextlink.unlink(missing_ok=True);nextlink.symlink_to(desired,target_is_directory=True);os.replace(nextlink,target);sync_dir(target.parent)
            else:
                source=Path(move['old'] if rollback else move['prepared']);retained=directory/('program-discarded' if rollback else 'program-old')/role
                if target.exists():
                    retained.parent.mkdir(mode=0o700,parents=True,exist_ok=True);require(not retained.exists(),'Ambiguous retained program component');os.rename(target,retained);sync_dir(target.parent)
                    self.checkpoint(('program:rollback-old:' if rollback else 'program:old:')+role)
                require(program_hash(source,role)==desired,'Required prior/new program is missing or corrupt')
                if desired is not None:
                    target.parent.mkdir(parents=True,exist_ok=True);os.rename(source,target);sync_dir(target.parent)
            self.checkpoint(('program:rollback-new:' if rollback else 'program:new:')+role)
        if not self.p['windows']:
            self.p['release']=Path(journal['program_moves'][0]['old_hash' if rollback else 'new_hash']);self.p['app']=self.p['release']/'app'
            self.p['php']=self.p['release']/'runtime/php/bin/php'
        info=load(self.p['release']/'BUILD-INFO.json')
        atomic(self.base/'maintenance.json',dict(phase(self.base),installed_build={'source_sha256':info['source']['source_sha256'],'architecture':info['architecture'],'version':info['version']}))
        self.save(journal,program_direction='rollback' if rollback else 'forward')

    def promote(self,journal,rollback=False):
        self.context=journal['context']
        if not rollback:self.program(journal)
        return super().promote(self.journal(journal['operation']),rollback)

    def verify_active(self,journal,rollback=False):
        if journal.get('program_moves'):
            if rollback:self.program(journal,True)
            self.adapter.activate_program(journal,rollback)
        else:self.adapter.verify_installed_program()
        for move in journal.get('program_moves',[]):
            if move['role']=='current':require(program_release_hash(Path(move['old_hash' if rollback else 'new_hash']))==move['old_content' if rollback else 'new_content'],'Immutable program/runtime changed')
            require(program_hash(Path(move['target']),move['role'])==move['old_hash' if rollback else 'new_hash'],'Program/runtime verification failed')
        return super().verify_active(journal,rollback)

    def install(self,candidate,confirmation,maintenance_confirmed=False):
        require(maintenance_confirmed,'Explicit version, downtime and recovery confirmation required')
        self.adapter.require_capsule()
        approved=self.plan(candidate);require(approved['confirmation']==confirmation,'Install approval is stale or for another candidate')
        operation=str(uuid.uuid4());self.ctl.enter(operation)
        with self.ctl.owned(operation),barrier(self.base,'control'):
            self.quiescent(operation);require(self.plan(candidate)['confirmation']==confirmation,'Compatibility/security decision changed after maintenance; obtain renewed approval')
            journal=self.begin(operation,'update');journal=self.save(journal,candidate=candidate,approved_plan=approved,writes_may_have_resumed=False)
            try:
                envelope,archive=self.manager.validate(candidate);directory=self.operation_dir(operation)
                package=extract(archive,envelope,directory/'package');journal=self.stage_program(journal,package)
                self.refresh_security();current=directory/'current';manifest=self.capture(current,operation)
                safety=self.publish(current,manifest,self.root/'backups');journal=self.save(journal,'safety_verified',safety_backup=str(safety))
                incoming=directory/'incoming';copy_tree(current,incoming)
                self.adapter.migrate(package,incoming/'payload',approved['path'])
                self.checkpoint('update:migrated')
                actual=sqlite_info(incoming/'payload/database/data')
                require(actual['schema_sha256']==approved['path']['database_schema_sha256'],'Target database SQL schema was not certified')
                require(actual['foreign_key_defects']==manifest['validation']['database']['foreign_key_defects'],'Migration introduced relationship corruption')
                journal=self.prepare_sets(incoming,current,manifest,self.journal(operation))
                # State changes with the matching authenticated program/data set, not download.
                value=load(incoming/'payload/update_trust/control.json');target_info=load(package/'BUILD-INFO.json')
                old_release=self.p['release'];self.p['release']=package
                try:
                    value['state']=installed_state(self.adapter);value['state']['channel']=envelope['signed']['channel']
                finally:self.p['release']=old_release
                value['candidate']=None;value['result']={'operation':operation,'outcome':'installation staged'}
                atomic(incoming/'payload/update_trust/control.json',value);atomic(incoming/'payload/update_trust/state.json',value['state'])
                for move in journal['moves']:
                    if move['role']=='update_trust':move['new_hash']=tree_hash(incoming/'payload/update_trust')
                journal=self.prepare_program(journal)
                self.adapter.stage_native_configuration(Path(journal['context']['package']),incoming/'payload',journal)
                for move in journal['moves']:
                    if move['role'] in ('configuration','tls'):move['new_hash']=tree_hash(incoming/'payload'/move['role']) if journal['target_components'][move['role']]['kind']=='directory' else tree_hash(incoming/'payload'/move['role']/'data')
                journal=self.save(journal,'prepared')
                self.checkpoint('update:before-promotion');journal=self.promote(journal)
            except BaseException:
                journal=self.journal(operation);self.save(journal,error='Update failed; writers remain fenced. Use the retained controller and explicit forward/rollback/current recovery.')
                self.manager.store.failure(operation);raise
        try:
            self.accept(operation);self.checkpoint('update:before-startup');self.resume(operation)
        except BaseException:
            self.manager.store.failure(operation);raise
        return {'operation':operation,'installed':True,'version':self.adapter.source_binding()['version'],'safety_backup':str(safety)}

    def recover(self,operation,decision):
        journal=self.journal(operation);self.context=journal.get('context')
        state=phase(self.base)
        if state.get('operation')==operation and state['phase'] in ('resuming','failed'):
            with self.ctl.owned(operation):
                self.adapter.quiesce(state['adapter']);self.adapter.stop(state['adapter']);self.adapter.verify(state['adapter'])
                self.ctl.set(state,'failed',error='Explicit update recovery; writers remain fenced')
        if decision=='forward':require(journal.get('program_moves'),'Program staging was interrupted before a complete forward plan; use the verified prior set through rollback')
        require(not journal.get('writes_may_have_resumed') or decision=='forward','Writes may have resumed; do not discard newer activity by rollback')
        if journal['state'] in ('accepted','rollback_accepted') and not journal.get('writes_may_have_resumed'):
            self.save(journal,'promoted_verified' if journal['state']=='accepted' else 'rollback_verified')
        return super().recover(operation,decision)

    def accept(self,operation):
        self.context=self.journal(operation).get('context');return super().accept(operation)

    def resume(self,operation):
        journal=self.journal(operation);self.context=journal.get('context')
        def prepare():
            self.adapter.prepare();current=self.journal(operation)
            for move in current.get('moves',[]):
                if move['role'] in self.p['targets']:move['new_hash' if current['state']=='accepted' else 'rollback_hash']=tree_hash(self.p['targets'][move['role']])
            self.save(current,requires_prepare=False)
        def reopening():
            self.checkpoint('update:before-writer-reopen');self.save(self.journal(operation),writes_may_have_resumed=True)
        self.ctl.resume(operation,prepare=prepare,before_reopen=reopening)
        value=self.manager.store.read();value['result']={'operation':operation,'outcome':'installed' if journal['state']=='accepted' else 'rolled back','version':self.adapter.source_binding()['version']}
        if value.get('candidate'):value['candidate']['install_ready']=False
        value.pop('installation_request',None);self.manager.store.write(value)
        return {'running':True,'operation':operation}


def load_info(archive,envelope):
    for name,size,stream in c.archive_members(archive,envelope['signed']['package']['format']):
        if name=='PHILCST-VMS/BUILD-INFO.json':return c.parse(stream.read(c.MAX_METADATA))
    raise ValueError('Build identity is missing')
