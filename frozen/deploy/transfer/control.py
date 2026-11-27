"""Target-bound handoff. Separate from same-host D5B restore and D5C updates."""
from contextlib import contextmanager
import copy
import datetime as dt
import json
import os
from pathlib import Path
import shutil
import uuid
from contract import c,now,stamp,public,certificate_public,sign,verify,timestamped,compatible,PRODUCT
from backup_contract import require,canonical,sha,load,no_links,file_sha,tree_hash,inventory,database,sqlite_info,inspect_bundle,env_values,ROLES
from coordination import atomic,identity,phase,barrier,jobs
from maintenance import Coordinator,process_alive,secret_fingerprints
from recovery import Recovery,copy_file,copy_tree,durable_tree,sync_dir
from recovery_security import validate_ledger,reconcile_database,reset_leases,security_state
from recovery_platform import NativeRecovery


class Capture(Recovery):
    # Only invoked under this controller's verified transfer intent and local lock.
    # Ordinary Recovery.host() remains ownership-checked and host-bound.
    def host(self):
        value=load(self.root/'host.json')
        require(value['machine']==self.adapter.machine() and value['root']==sha(str(self.p['root']).encode()) and value['installation']==identity(self.base)['installation'],'Source recovery host does not agree with transfer authority')
        return value


class Proof(Capture):
    def validate_trust(self,directory):
        if not directory.exists():return None
        trust=load(directory/'trust.json');state=load(directory/'state.json');c.validate_trust(trust,self.adapter.fixture);c.validate_state(state,self.adapter.fixture)
        expected=self.expected_binding
        require(state['version']==expected['version'] and state['platform']==expected['platform'] and state['schema_id']==expected['schema_id'] and state['runtime_id']==expected['runtime_id'],'Transferred publisher state is incompatible')
        require(state['source']=={'commit':expected['commit'],'sha256':expected['source_sha256'],'build_identity':expected['build_identity']},'Transferred update source changed')
        if (directory/'control.json').exists():
            value=load(directory/'control.json');require(value['trust']==trust and value['state']==state,'Transferred publisher catalog/mirrors disagree')
            require(c.timestamp(value['clock_floor'])<=now()+dt.timedelta(minutes=5),'Target clock is behind protected publisher clock floor; correct it independently before transfer activation')
        return trust


class Transfer:
    def __init__(self,adapter,checkpoint=None):
        self.adapter=adapter;self.p=adapter.p;self.base=self.p['base'];self.root=self.base.parent/'transfer';self.rec=Capture(adapter);self.ctl=Coordinator(self.base,adapter)
        self.checkpoint=checkpoint or (lambda _:None)

    @contextmanager
    def owned(self):
        self.adapter.admin();self.adapter.private(self.root);lock=self.root/'owner.lock'
        try:fd=os.open(lock,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
        except FileExistsError:raise ValueError('Concurrent/interrupted transfer owner; review its PID explicitly') from None
        try:
            with os.fdopen(fd,'w') as f:f.write(str(os.getpid()));f.flush();os.fsync(f.fileno())
            yield
        finally:lock.unlink()

    def state(self):
        v=load(self.root/'control.json');require(v['schema']=='philcst.transfer-control.v1' and v['local_host']==self.adapter.machine() and v['local_root']==sha(str(self.p['root']).encode()),'Transfer control belongs to another host/root')
        return v

    def save(self,value,stage=None,**extra):
        value=dict(value,**extra)
        if stage:value['phase']=stage
        atomic(self.root/'control.json',value);self.adapter.permissions_for('private',self.root)
        self.checkpoint('transfer:'+value['phase']);return value

    def ownership(self,value):
        atomic(self.base/'maintenance.json',dict(phase(self.base),ownership_required=True))
        atomic(self.base/'ownership.json',value);self.adapter.permissions_for('security',self.base/'ownership.json')
        self.checkpoint('ownership:'+value['state'])

    def bootstrap(self,private,public_sha256,source_hash):
        with self.owned():
            require(not (self.root/'control.json').exists(),'Transfer authority already registered; never silently replace it')
            require(source_hash==self.adapter.source_binding()['source_sha256'],'Independently authenticated installed package/source confirmation required')
            self.adapter.verify_installed_program();self.rec.host();self.rec.ledger();self.adapter.check_configuration()
            no_links(private);pem=public(self.adapter,private);key=c.fingerprint(pem);require(key==public_sha256,'Independent transfer public fingerprint mismatch')
            trust=self.p['targets']['update_trust']/'trust.json'
            require(not trust.exists() or key not in load(trust)['keys'],'Transfer authority must not reuse a software publisher key')
            tls_certificate=self.p['targets']['tls']/'tls/pki/authorities/local/root.crt'
            require(c.fingerprint(certificate_public(self.adapter,tls_certificate))!=key,'Transfer authority must not reuse the TLS issuer key')
            copy_file(private,self.root/'authority.pem');self.adapter.permissions_for('private',self.root)
            authority={'key_id':key,'public_key':pem,'private':str(self.root/'authority.pem'),'host':self.adapter.machine(),'root':sha(str(self.p['root']).encode()),'environment':'fixture' if self.adapter.fixture else 'production'}
            value={'schema':'philcst.transfer-control.v1','local_host':self.adapter.machine(),'local_root':sha(str(self.p['root']).encode()),'authority':authority,'peers':{},'phase':'idle','used':[]}
            self.save(value)
            if (self.base/'ownership.json').exists():
                from ownership_guard import check
                check(self.base) # Fresh D6 owner is retained; enrolling a peer key never rotates transport identity.
            else:self.ownership({'schema':1,'state':'active','deployment':identity(self.base)['installation'],'owner':str(uuid.uuid4()),'epoch':0})
            return {k:authority[k] for k in ('key_id','public_key','host','root','environment')}

    def enroll(self,peer,fingerprint):
        with self.owned():
            c.fields(peer,['key_id','public_key','host','root','environment']);require(peer['key_id']==fingerprint==c.fingerprint(peer['public_key']),'Independent peer fingerprint differs')
            require(peer['environment']==('fixture' if self.adapter.fixture else 'production'),'Fixture peer cannot authorize production')
            c.digest(peer['root']);require(isinstance(peer['host'],str) and peer['host']!=self.adapter.machine(),'Peer must be a distinct independently verified host')
            value=self.state();old=value['peers'].get(fingerprint);require(old is None or old==peer,'Existing peer identity changed');value['peers'][fingerprint]=peer;self.save(value);return {'peer_registered':fingerprint}

    def peer(self,value,envelope):
        peer=value['peers'].get(envelope.get('key_id'));require(peer,'Unknown transfer signer; independently enroll it before consuming metadata');return peer

    def fresh_target(self):
        self.adapter.verify_installed_program();self.adapter.validate_target(self.p['release']);self.rec.host();self.rec.ledger();self.adapter.check_configuration()
        self.target_empty()

    def target_empty(self):
        with database(self.p['targets']['database']) as db:
            for table in ('users','vehicles','vehicle_rfid_tags','vehicle_events','visitor_records','vehicle_crossings','rfid_scan_logs','delivery_receipts'):
                require(db.execute('SELECT COUNT(*) FROM '+table).fetchone()[0]==0,'Target contains operational accounts/records; refusing conflicting installation')
        with database(self.base/'delivery/outbox.sqlite') as db:require(db.execute('SELECT COUNT(*) FROM operations').fetchone()[0]==0,'Target contains deliveries; refusing overwrite')

    def challenge(self,source_key,actor):
        with self.owned():
            value=self.state();require(value['phase']=='idle','Target challenge already reserved or consumed');require(source_key in value['peers'],'Independently verified source authority required');self.fresh_target()
            operation=str(uuid.uuid4());self.ctl.enter(operation,drain_seconds=0)
            with self.ctl.owned(operation),barrier(self.base,'control'):
                self.rec.quiescent(operation);self.fresh_target()
                body=timestamped('challenge',id=str(uuid.uuid4()),nonce=uuid.uuid4().hex+uuid.uuid4().hex,source_key=source_key,target_key=value['authority']['key_id'],target_host=self.adapter.machine(),target_root=sha(str(self.p['root']).encode()),target_installation=identity(self.base),target_binding=self.adapter.source_binding(),target_sql=sqlite_info(self.p['targets']['database'])['schema_sha256'],actor=actor)
                envelope=sign(self.adapter,value['authority'],body)
                self.save(value,'challenged',operation=operation,challenge=envelope)
                current=load(self.base/'ownership.json');self.ownership(dict(current,state='target_reserved',transfer=body['id']))
                return envelope

    def policy(self):return self.adapter.transfer_policy() if hasattr(self.adapter,'transfer_policy') else load(Path(__file__).with_name('compatibility.json'))

    def plan(self,challenge,decisions,actor):
        value=self.state();require(value['phase'] in ('idle','planning','active'),'An existing handoff must be resolved first')
        peer=self.peer(value,challenge);body=verify(self.adapter,challenge,peer,'challenge')
        require(body['source_key']==value['authority']['key_id'] and body['target_key']==peer['key_id'] and body['target_host']==peer['host'] and body['target_root']==peer['root'],'Target challenge substitution')
        require(body['id'] not in value['used'],'Challenge already consumed')
        self.adapter.verify_installed_program();self.rec.host();self.rec.ledger();self.adapter.check_configuration()
        sql=sqlite_info(self.p['targets']['database']);require(sql['schema_sha256']==body['target_sql'],'Different actual SQL schemas need a separately certified D5C path')
        compatible(self.adapter.source_binding(),body['target_binding'],sql['schema_sha256'],self.policy())
        c.fields(decisions,['tls','target_network','isolation_required','custody']);require(decisions['tls']=='preserve' and decisions['isolation_required'] is True and decisions['custody']=='approved-encrypted-volume','Issuer rotation/disaster policy is separate; protected encrypted-volume custody and old-server isolation required')
        from policy import validate as validate_network
        validate_network(decisions['target_network'])
        body={'schema':'philcst.transfer-plan.v1','product':PRODUCT,'challenge_sha256':sha(canonical(challenge)),'source_key':value['authority']['key_id'],'source_host':self.adapter.machine(),'source_paths':{k:str(p) for k,p in self.p['targets'].items()},'source_base':str(self.base),'source_binding':self.adapter.source_binding(),'source_sql':sql,'security_sha256':sha(canonical(security_state(self.p['targets']['database']))),'source_owner':load(self.base/'ownership.json'),'decisions':decisions,'actor':actor}
        return dict(body,confirmation=sha(canonical(body)))

    def prepare(self,challenge,decisions,actor,confirmation):
        with self.owned():
            value=self.state();plan=self.plan(challenge,decisions,actor);require(plan['confirmation']==confirmation,'Exact handoff approval changed')
            history=self.root/'history';self.adapter.private(history);atomic(history/(uuid.uuid4().hex+'.json'),value)
            value={k:value[k] for k in ('schema','local_host','local_root','authority','peers','used')}
            operation=str(uuid.uuid4());value=self.save(value,'preparing',operation=operation,plan=plan,challenge=challenge)
            self.ctl.enter(operation)
            with self.ctl.owned(operation),barrier(self.base,'control'):
                self.rec.quiescent(operation);require(self.plan_for_fenced(value)==plan['security_sha256'],'Stable security changed; retain fence and obtain renewed approval')
                self.rec.refresh_security();directory=self.root/'operations'/operation;self.adapter.private(directory)
                capture=directory/'capture';manifest=self.rec.capture(capture,operation)
                archive=self.rec.publish(capture,manifest,self.p['recovery']/'backups')
                self.save(self.state(),'captured',backup=str(archive),manifest=manifest)
                owner=dict(plan['source_owner'],state='retired',epoch=plan['source_owner']['epoch']+1,transfer=challenge['signed']['id'],target_key=challenge['signed']['target_key'])
                self.ownership(owner) # durable retirement precedes transferable bytes/signature
                return self.finish_export()

    def plan_for_fenced(self,value):
        self.adapter.verify_installed_program();self.rec.ledger();return sha(canonical(security_state(self.p['targets']['database'])))

    def finish_export(self):
        value=self.state();require(value['phase'] in ('captured','exported'),'No captured handoff is available');self.rec.quiescent(value['operation'])
        owner=load(self.base/'ownership.json');plan=value['plan'];challenge=value['challenge'];manifest=value['manifest']
        expected=dict(plan['source_owner'],state='retired',epoch=plan['source_owner']['epoch']+1,transfer=challenge['signed']['id'],target_key=challenge['signed']['target_key'])
        if owner==plan['source_owner'] and value['phase']=='captured':self.ownership(expected);owner=expected
        require(owner==expected,'Source retirement evidence missing/mismatched')
        archive=Path(value['backup']);inspect_bundle(archive)
        body=timestamped('manifest',transfer=challenge['signed']['id'],challenge_sha256=sha(canonical(challenge)),challenge=challenge,
            source_host=self.adapter.machine(),source_root=value['authority']['root'],source_key=value['authority']['key_id'],source_identity=identity(self.base),source_paths=plan['source_paths'],source_base=plan['source_base'],
            target_key=challenge['signed']['target_key'],target_host=challenge['signed']['target_host'],target_root=challenge['signed']['target_root'],
            source_binding=plan['source_binding'],target_binding=challenge['signed']['target_binding'],source_sql=plan['source_sql'],recovery_point=manifest['created_at'],
            backup_manifest_sha256=sha(canonical(manifest)),archive_sha256=file_sha(archive/'backup.zip'),archive_bytes=(archive/'backup.zip').stat().st_size,receipt_sha256=file_sha(archive/'receipt.json'),
            retirement=owner,delivery=manifest['validation']['delivery'],decisions=plan['decisions'],approval={'actor':plan['actor'],'confirmation':plan['confirmation']})
        envelope=value.get('export') or sign(self.adapter,value['authority'],body)
        atomic(archive/'TRANSFER.json',envelope);value=self.save(value,'exported',export=envelope);return {'archive':str(archive),'manifest':envelope,'source_retired':True}

    def export(self,output,custody):
        with self.owned():
            require(custody=='approved-encrypted-volume','Transfer contains keys/secrets; approved encrypted-volume custody must be explicitly attested')
            value=self.state();require(value['phase']=='exported','No completed signed transfer export');output=Path(output);no_links(output);require(not output.exists(),'Use a new protected transfer destination')
            self.adapter.free((Path(value['backup'])/'backup.zip').stat().st_size)
            self.adapter.private(output)
            for name in ('backup.zip','receipt.json','TRANSFER.json'):copy_file(Path(value['backup'])/name,output/name)
            self.adapter.permissions_for('private',output);inspect_bundle(output);return {'exported':str(output),'encrypted_by_tool':False,'custody':custody}

    def inspect(self,archive):
        value=self.state();archive=Path(archive);no_links(archive);envelope=c.load(archive/'TRANSFER.json');peer=self.peer(value,envelope);body=verify(self.adapter,envelope,peer,'manifest')
        require(body['target_key']==value['authority']['key_id'] and body['target_host']==self.adapter.machine() and body['target_root']==value['authority']['root'],'Transfer bound to another target')
        require(value['phase'] in ('challenged','importing','staged','promoting','verified','activating','active') and body['challenge']==value['challenge'] and body['transfer']==value['challenge']['signed']['id'],'One-time challenge mismatch')
        verify(self.adapter,body['challenge'],value['authority'],'challenge')
        require(body['source_host']==peer['host'] and body['source_root']==peer['root'] and body['source_key']==peer['key_id'],'Source host/authority substitution')
        require(body['retirement']['state']=='retired' and body['retirement']['transfer']==body['transfer'] and body['retirement']['target_key']==value['authority']['key_id'],'Missing authenticated source retirement')
        require(body['challenge_sha256']==sha(canonical(value['challenge'])),'Challenge hash mismatch')
        require((archive/'backup.zip').stat().st_size==body['archive_bytes'] and file_sha(archive/'backup.zip')==body['archive_sha256'] and file_sha(archive/'receipt.json')==body['receipt_sha256'],'Damaged/replaced transfer bytes')
        manifest=inspect_bundle(archive)
        require(sha(canonical(manifest))==body['backup_manifest_sha256'] and manifest['compatibility']==body['source_binding'] and manifest['installation']['identity']==body['source_identity'],'Signed recovery identity does not bind payload')
        compatible(body['source_binding'],self.adapter.source_binding(),body['source_sql']['schema_sha256'],self.policy())
        return body,manifest,envelope

    def import_set(self,archive,confirmation):
        with self.owned():
            value=self.state();require(value['phase']=='challenged','Target challenge already consumed or import interrupted; use explicit recovery')
            self.target_empty();require(identity(self.base)==value['challenge']['signed']['target_installation'],'Target installation identity changed after challenge')
            body,manifest,envelope=self.inspect(archive);require(sha(canonical(envelope))==confirmation,'Exact authenticated import confirmation required')
            self.adapter.verify_installed_program();self.adapter.free(sum(v['size'] for v in manifest['files'].values())*3+body['archive_bytes'])
            self.rec.quiescent(value['operation']);value=self.save(value,'importing',import_manifest=envelope)
            directory=self.root/'incoming';self.adapter.private(directory)
            local=directory/'source';self.adapter.private(local)
            for name in ('backup.zip','receipt.json','TRANSFER.json'):copy_file(Path(archive)/name,local/name)
            self.inspect(local) # verify protected copied bytes again, before extraction
            staged=directory/'extracted';inspect_bundle(local,staged);self.checkpoint('target:extracted')
            self.verify_payload(staged/'payload',manifest,body,source=True)
            return self.stage_target(staged,manifest,body)

    def recover_import(self):
        with self.owned():
            value=self.state();require(value['phase']=='importing','Only an interrupted pre-promotion import can be restaged')
            local=self.root/'incoming/source';body,manifest,_=self.inspect(local)
            staged=self.root/'incoming/extracted'
            if staged.exists():shutil.rmtree(staged)
            inspect_bundle(local,staged);self.verify_payload(staged/'payload',manifest,body,source=True)
            return self.stage_target(staged,manifest,body)

    def verify_payload(self,payload,manifest,body,source=False):
        adapter=copy.copy(self.adapter);adapter.p=dict(self.p);validation=self.root/'validation-identity';self.adapter.private(validation)
        atomic(validation/'identity.json',body['source_identity'])
        proof=Proof(adapter);proof.base=validation;proof.expected_binding=body['source_binding'] if source else self.adapter.source_binding()
        if source:
            # Validate source path strings without redirecting the target's
            # installed crypto verifier/capsule lookup into source-host paths.
            import types
            context=types.SimpleNamespace(p=dict(self.p,base=Path(body['source_base']),targets={k:Path(v) for k,v in body['source_paths'].items()}))
            adapter.environment_binding=lambda path:NativeRecovery.environment_binding(context,path)
            proof.p=dict(self.p,windows=body['source_binding']['platform']=='windows-x64')
        expected=manifest['validation'] if source else None
        return proof.validate_payload(payload,manifest['components'],expected)

    def stage_target(self,staged,manifest,body):
        payload=staged/'payload';value=self.state()
        ledger=load(payload/'security/data');ledger['epoch']+=1;ledger['active_set']=str(uuid.uuid4());ledger['blocked_by_set'][ledger['active_set']]=dict(ledger['blocked_by_set'][load(payload/'security/data')['active_set']])
        # Current source security wins, while all sessions/codes are invalidated on copies.
        reconcile_database(payload/'database/data',payload/'database/data',ledger,ledger['active_set']);reset_leases(payload/'delivery/outbox.sqlite');atomic(payload/'security/data',ledger)
        for path in (payload/'setup',payload/'storage/app/update-inbox'):
            if path.exists():shutil.rmtree(path)
        if manifest['components']['setup']['present']:(payload/'setup').mkdir(mode=0o700)
        self.adapter.relocate_transfer(payload,manifest,body)
        if (payload/'update_trust/control.json').exists():
            catalog=load(payload/'update_trust/control.json');catalog['candidate']=None;catalog['approvals']={};catalog.pop('installation_request',None)
            state=catalog['state'];binding=self.adapter.source_binding();state.update(platform=binding['platform'],runtime_id=binding['runtime_id'],os_version=self.adapter.os_version())
            catalog['result']={'outcome':'Transferred to new authenticated owner; prepared downloads invalidated'}
            atomic(payload/'update_trust/control.json',catalog);atomic(payload/'update_trust/state.json',state)
        proof=self.verify_payload(payload,manifest,body)
        require(proof['database']['foreign_key_defects']==body['source_sql']['foreign_key_defects'],'Transfer introduced historical relationship corruption')
        durable_tree(payload)
        moves=[];directory=self.root/'promotion';self.adapter.private(directory)
        # Keep the live barrier inode; source has no outstanding jobs. Target owns its own barrier.
        for role in sorted(ROLES-{'coordination'}):
            target=self.p['targets'][role];prepared=payload/role
            if manifest['components'][role]['kind']=='file':prepared=prepared/'data'
            moves.append({'role':role,'target':str(target),'prepared':str(prepared),'old':str(directory/'old'/role),'old_hash':tree_hash(target),'new_hash':tree_hash(prepared)})
        for suffix in ('-wal','-shm','-journal'):
            path=Path(str(self.p['targets']['database'])+suffix)
            moves.append({'role':'database'+suffix,'target':str(path),'prepared':str(payload/('absent-database'+suffix)),'old':str(directory/'old'/('database'+suffix)),'old_hash':tree_hash(path),'new_hash':None})
        value=self.save(value,'staged',moves=moves,staged=str(staged),source_manifest=manifest,body=body)
        return {'staged':True,'transfer':body['transfer'],'activation_blocked':'Requires verified isolation attestation and full target verification'}

    def promote(self):
        value=self.state();require(value['phase'] in ('staged','promoting'),'No authenticated staged transfer')
        # Recheck source-signed metadata and protected copied bytes on every retry.
        self.inspect(self.root/'incoming/source');value=self.save(value,'promoting')
        with self.ctl.owned(value['operation']),barrier(self.base,'control'):
            self.adapter.verify(phase(self.base)['adapter'])
            for move in value['moves']:
                target=Path(move['target']);prepared=Path(move['prepared']);old=Path(move['old']);actual=tree_hash(target)
                if actual==move['new_hash']:continue
                require(actual in (None,move['old_hash']),'Unexpected target bytes; refuse guessed promotion')
                require(tree_hash(prepared)==move['new_hash'],'Staged transfer state missing/corrupt')
                value=self.save(value,intent=move['role'])
                if target.exists():
                    require(not old.exists(),'Ambiguous retained target component');old.parent.mkdir(mode=0o700,parents=True,exist_ok=True);os.rename(target,old);sync_dir(target.parent);self.checkpoint('target:old:'+move['role'])
                else:require(move['old_hash'] is None or tree_hash(old)==move['old_hash'],'Missing prior target component')
                if move['new_hash'] is not None:target.parent.mkdir(parents=True,exist_ok=True);os.rename(prepared,target);sync_dir(target.parent)
                if move['role'] in ROLES:self.adapter.permissions_for(move['role'],target)
                self.checkpoint('target:new:'+move['role'])
            if hasattr(self.adapter,'finish_transfer_controller'):self.adapter.finish_transfer_controller()
            return self.verify_target()

    def verify_target(self):
        value=self.state();require(value['phase'] in ('promoting','verified'),'Transfer is not ready for verification')
        for move in value['moves']:require(tree_hash(Path(move['target']))==move['new_hash'],'Target component verification failed')
        # Recapture validates target paths, copied generation, secrets/media/publisher state.
        host={'schema':'philcst.recovery-host.v1','installation':identity(self.base)['installation'],'machine':self.adapter.machine(),'root':sha(str(self.p['root']).encode()),'registration':str(uuid.uuid4())}
        atomic(self.p['recovery']/'host.json',host)
        capture=self.root/('target-proof-'+uuid.uuid4().hex);manifest=self.rec.capture(capture,value['operation'])
        require(manifest['validation']['database']['schema_sha256']==value['body']['source_sql']['schema_sha256'],'Transferred schema changed')
        state=phase(self.base);atomic(self.base/'maintenance.json',dict(state,secret_fingerprints=secret_fingerprints(self.p['targets']['environment'])))
        value=self.save(value,'verified',target_proof=manifest['validation']);return {'verified':True,'device_readiness':'Not tested; cameras/readers are separate','writers_reopened':False}

    def attest_isolation(self,transfer,method,evidence,actor,address_conflict_checked=False):
        with self.owned():
            value=self.state();require(value['phase']=='verified' and transfer==value['body']['transfer'],'Exact verified transfer required')
            require(method in ('powered-off-and-disconnected','administratively-network-isolated') and isinstance(evidence,str) and 20<=len(evidence)<=2000 and address_conflict_checked is True,'Verified old-server physical/network isolation and explicit address conflict inspection are required; timeout is not fencing')
            attestation=sign(self.adapter,value['authority'],timestamped('isolation',transfer=transfer,manifest_sha256=sha(canonical(value['import_manifest'])),method=method,evidence=evidence,actor=actor,address_conflict_checked=True))
            self.save(value,isolation=attestation);return attestation

    def activate(self,confirmation):
        with self.owned():
            value=self.state();require(value['phase'] in ('verified','activating'),'One-time activation already consumed or target incomplete')
            require(confirmation==sha(canonical(value['import_manifest'])),'Exact transfer activation confirmation required')
            verify(self.adapter,value['isolation'],value['authority'],'isolation')
            self.adapter.validate_transfer_activation(value['body'])
            require(value['isolation']['signed']['manifest_sha256']==confirmation,'Isolation evidence does not authorize this package')
            value=self.save(value,'activating') # may-have-accepted-traffic boundary; never cancel afterwards
            owner=value['body']['retirement'];self.ownership({'schema':1,'state':'active','deployment':identity(self.base)['installation'],'owner':str(uuid.UUID(value['challenge']['signed']['id'])),'epoch':owner['epoch'],'transfer':value['body']['transfer'],'source_retired':True})
            # New D5B receipt is generated locally by the authenticated target, not imported.
            journal_file=self.rec.operation_dir(value['operation'])/'journal.json'
            journal=self.rec.journal(value['operation']) if journal_file.exists() else self.rec.begin(value['operation'],'transfer',requires_prepare=True)
            self.rec.save(journal,'accepted',requires_prepare=phase(self.base)['phase']!='running')
            if phase(self.base)['phase']!='running':
                def prepared():
                    self.adapter.prepare();self.rec.save(self.rec.journal(value['operation']),'accepted',requires_prepare=False)
                self.ctl.resume(value['operation'],prepare=prepared)
            self.checkpoint('target:running')
            receipt=sign(self.adapter,value['authority'],timestamped('activation',transfer=value['body']['transfer'],manifest_sha256=confirmation,owner=load(self.base/'ownership.json'),target_host=self.adapter.machine(),target_identity=identity(self.base)))
            value['used'].append(value['body']['transfer']);self.save(value,'active',activation=receipt)
            return receipt

    def acknowledge(self,receipt):
        with self.owned():
            value=self.state();require(value['phase']=='exported','Source is not awaiting target activation');body=verify(self.adapter,receipt,self.peer(value,receipt),'activation')
            require(body['transfer']==value['export']['signed']['transfer'] and body['manifest_sha256']==sha(canonical(value['export'])) and body['target_host']==value['challenge']['signed']['target_host'],'Activation acknowledgement differs from source handoff')
            value['used'].append(body['transfer']);self.save(value,'retired',activation=receipt);return {'retired':True,'source_resume_allowed':False}

    def reject(self):
        with self.owned():
            value=self.state();require(value['phase'] in ('challenged','importing','staged','verified','promoting'),'Activation may have accepted traffic; returning to source requires a new coherent handoff')
            # Target stays permanently fenced; cancellation receipt consumes its challenge.
            challenge=value['challenge'];self.ownership(dict(load(self.base/'ownership.json'),state='target_cancelled'))
            receipt=sign(self.adapter,value['authority'],timestamped('rejection',transfer=challenge['signed']['id'],challenge_sha256=sha(canonical(challenge)),target_host=self.adapter.machine(),cancelled=True))
            value['used'].append(challenge['signed']['id']);self.save(value,'cancelled',rejection=receipt);return receipt

    def cancel_source(self,receipt):
        with self.owned():
            value=self.state();require(value['phase'] in ('preparing','exported','captured'),'Source handoff cannot be cancelled in this state');body=verify(self.adapter,receipt,self.peer(value,receipt),'rejection')
            require(body['transfer']==value['challenge']['signed']['id'] and body['challenge_sha256']==sha(canonical(value['challenge'])) and body['cancelled'] is True,'Missing target-signed cancellation/nonce consumption')
            self.ownership(dict(value['plan']['source_owner'],epoch=value['plan']['source_owner']['epoch']+1))
            value['used'].append(body['transfer']);self.save(value,'idle',rejection=receipt)
            if phase(self.base)['phase']!='running':self.ctl.resume(value['operation'])
            return {'source_running':True,'target_permanently_cancelled':True}

    def disaster_inspect(self,archive,authority=None):
        archive=Path(archive);candidates=[archive] if (archive/'backup.zip').is_file() else [p for p in archive.iterdir() if p.is_dir() and (p/'backup.zip').is_file()]
        usable=[];rejected=0
        for candidate in candidates[:100]:
            try:usable.append((inspect_bundle(candidate),candidate))
            except (ValueError,OSError):rejected+=1
        require(usable,'No complete coherent recovery set found; disaster activation remains blocked')
        manifest,path=max(usable,key=lambda x:dt.datetime.fromisoformat(x[0]['created_at']))
        return {'mode':'DISASTER RECOVERY — NOT ACTIVATABLE','recovery_point':manifest['created_at'],'selected':str(path),'other_invalid_sets':rejected,'selection':'Newest declared point among at most 100 supplied coherent sets; sender/time/newest-state not authenticated by checksums',
            'retained_delivery':manifest['validation']['delivery'],'later_activity':'Unknown; movements/security changes after this point may be missing','newer_revocations':'Not established by an older backup','activation_blocked':[
            'Independently held authenticated deployment/recovery authority and recovery-set signature required',
            'Current monotonic lost-UID/account/update-trust state or explicit institutional loss policy required',
            'Verified old-server isolation and authenticated target-bound recovery authorization required'],
            'keys':'Inspect/decrypt only with separately authenticated source APP_KEY and TLS material; never regenerate missing keys','active_state_mutated':False}
