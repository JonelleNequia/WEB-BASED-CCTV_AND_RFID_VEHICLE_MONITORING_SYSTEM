"""Protected catalog is authoritative; D3 JSON files are verified recovery mirrors."""
from contextlib import contextmanager
import datetime as dt
import os
from pathlib import Path
import uuid
from contracts import c, canonical, sha, load, require, installed_state, now, stamp
from coordination import atomic, phase, identity


class Store:
    def __init__(self, adapter):
        self.adapter = adapter; self.base = adapter.p['base']; self.root = adapter.p['targets']['update_trust']
        self.catalog = self.root / 'control.json'; self.public = self.base.parent / 'update-status.json'

    @contextmanager
    def owned(self,maintenance=False):
        from coordination import producer_work
        from contextlib import nullcontext
        with (producer_work('update control',base=self.base) if not maintenance else nullcontext()):
            token=self.base/'update-operation.lock'
            try:fd=os.open(token,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
            except FileExistsError:raise ValueError('Another/interrupted update owner exists; inspect its PID before explicit recovery') from None
            try:
                with os.fdopen(fd,'w') as file:file.write(str(os.getpid()));file.flush();os.fsync(file.fileno())
                yield
            finally:token.unlink()

    def read(self):
        value = load(self.catalog)
        require(value['schema'] == 'philcst.update-control.v1' and value['installation'] == identity(self.base), 'Wrong protected update installation')
        c.validate_trust(value['trust'], self.adapter.fixture); c.validate_state(value['state'], self.adapter.fixture)
        clock = now()
        require(c.timestamp(value['clock_floor']) <= clock + dt.timedelta(minutes=5), 'Clock moved backwards; correct local clock and obtain fresh signed metadata')
        ledger = load(self.base / 'recovery-security.json')
        require(value['trust']['generation'] >= ledger['update_generation'] and all(k not in value['trust']['keys'] or value['trust']['keys'][k]['revoked'] for k in ledger['revoked_update_keys']), 'Protected update trust was rolled back')
        return value

    def write(self, value):
        value['clock_floor'] = max(value.get('clock_floor', stamp(now())), stamp(now()))
        if len(value.get('approvals',{}))>50:
            value['approvals']=dict(sorted(value['approvals'].items(),key=lambda item:item[1]['request']['created_at'],reverse=True)[:50])
        atomic(self.catalog, value)
        atomic(self.root / 'trust.json', value['trust']); atomic(self.root / 'state.json', value['state'])
        for name in ('control.json', 'trust.json', 'state.json'): self.adapter.permissions_for('update_trust', self.root / name)
        public = {k: value[k] for k in ('preferences','last_check','candidate','result')}
        if value.get('installation_request'): public['installation_request']=value['installation_request']
        public.update(schema=1, installed=value['state'], trust_generation=value['trust']['generation'], native_confirmation_required=True)
        atomic(self.public, public); self.adapter.permissions_for('network', self.public)

    def failure(self,operation):
        # Derived status is outside promoted trust/data, so even a partial swap
        # can explain the fence without inventing authoritative installed state.
        value=load(self.public) if self.public.exists() else {'schema':1,'candidate':None}
        value['result']={'operation':operation,'outcome':'Update failed; monitoring remains fenced. Local administrator recovery required.'}
        if value.get('candidate'):value['candidate']['install_ready']=False
        atomic(self.public,value);self.adapter.permissions_for('network',self.public)

    def bootstrap(self, trust, fingerprint, confirm_source):
        self.adapter.admin(); require(not self.catalog.exists(), 'Already provisioned; use authenticated rotation')
        require(sha(canonical(trust)) == fingerprint and confirm_source == self.adapter.source_binding()['source_sha256'], 'Independent public trust/source confirmation does not match')
        c.validate_trust(trust, self.adapter.fixture)
        require(not (self.root/'trust.json').exists() or load(self.root/'trust.json')==trust, 'Existing trust differs; bootstrap cannot replace it')
        self.adapter.check_configuration()
        self.adapter.private(self.root)
        value = {'schema':'philcst.update-control.v1','installation':identity(self.base),'trust':trust,'state':installed_state(self.adapter),'clock_floor':stamp(now()),
            'preferences':{'automatic_check':False,'automatic_download':False,'channel':'stable'},'floors':{},'candidate':None,'last_check':{'outcome':'never checked'},'result':None,'next_check':0}
        with self.owned(): self.write(value)
        return {'provisioned':True,'production_signing_credentials_shipped':False}

    def rotate(self, envelope, confirmation):
        with self.owned():
            value=self.read(); require(sha(canonical(envelope)) == confirmation, 'Exact rotation confirmation required')
            proof=c.validate_rotation(envelope,value['trust'],self.adapter.php_verifier(),fixture=self.adapter.fixture,now=now())
            revoked=set(load(self.base/'recovery-security.json')['revoked_update_keys']) | {k for k,v in value['trust']['keys'].items() if v['revoked']}
            require(all(k not in proof['trust']['keys'] or proof['trust']['keys'][k]['revoked'] for k in revoked), 'Rotation attempts to revive revoked authority')
            value['trust']=proof['trust']; value['candidate']=None; value['result']={'action':'trust rotation','generation':proof['trust']['generation']};self.write(value)
            ledger=load(self.base/'recovery-security.json');ledger['update_generation']=proof['trust']['generation'];ledger['revoked_update_keys']=sorted(revoked | {k for k,v in proof['trust']['keys'].items() if v['revoked']})
            atomic(self.base/'recovery-security.json',ledger);self.adapter.permissions_for('security',self.base/'recovery-security.json')
        return {'rotated':True,'generation':proof['trust']['generation']}
