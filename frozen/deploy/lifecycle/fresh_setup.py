"""Explicit fresh-install finalization. Never adopts an existing operational DB.

The native installer has provisioned D4/D5A and started its owned services. This
controller quiesces them, registers recovery/ownership and an independent capsule,
then resumes through the existing readiness gate. Missing publisher trust remains
an explicit feature block, not a fabricated installation-trusted key.
"""
import argparse
from contextlib import contextmanager
import json
import os
from pathlib import Path
import sys
import uuid

sys.path[:0] = [str(Path(__file__).resolve().parent), str(Path(__file__).resolve().parents[1] / 'updates')]
from backup_contract import require, load, canonical, sha, database
from coordination import atomic, identity, phase, barrier
from maintenance import Coordinator, layout, secret_fingerprints
from recovery import Recovery
from native import NativeUpdate
from contracts import installed_state


class FreshSetup:
    def __init__(self, adapter, checkpoint=None):
        self.adapter=adapter;self.p=adapter.p;self.base=self.p['base']
        self.root=self.base.parent/'provision';self.path=self.root/'fresh.json'
        self.ctl=Coordinator(self.base,adapter);self.rec=Recovery(adapter)
        self.checkpoint=checkpoint or (lambda _:None)

    def binding(self):
        return {'installation':identity(self.base),'source':self.adapter.source_binding(),
                'host':self.adapter.machine(),'root':sha(str(self.p['root']).encode()),
                'secrets':secret_fingerprints(self.p['targets']['environment'])}

    def save(self, value, step):
        value=dict(value,step=step);atomic(self.path,value)
        self.adapter.permissions_for('private',self.root);self.checkpoint(step)
        return value

    def verify(self):
        value=load(self.path)
        require(value['schema']=='philcst.fresh-setup.v1' and value['binding']==self.binding(), 'Fresh setup belongs to another source/host/installation; legacy adoption is refused')
        self.adapter.verify_installed_program();self.rec.host();self.rec.ledger()
        capsule=self.adapter.capsule()
        require((capsule/'app/deploy/lifecycle/ownership_guard.py').is_file() and (capsule/'app/deploy/transfer/control.py').is_file(), 'D5D-capable retained controller missing')
        from ownership_guard import check
        check(self.base)
        require(value['step']=='complete', 'Fresh setup is incomplete; resume its recorded intent explicitly')
        return {'fresh_setup':'complete','same_source':'verified without copying/reseeding','publisher_trust':'separate explicit enrollment','ownership':'active'}

    def run(self, confirm_source):
        self.adapter.admin();self.adapter.private(self.root)
        lock=self.root/'owner.lock'
        try:fd=os.open(lock,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        except FileExistsError:raise ValueError('Fresh setup has an active/interrupted owner; review its PID before explicit recovery') from None
        try:
            with os.fdopen(fd,'w') as f:f.write(str(os.getpid()));f.flush();os.fsync(f.fileno())
            return self._run(confirm_source)
        finally:lock.unlink()

    def _run(self, confirm_source):
        self.adapter.admin();self.adapter.verify_installed_program()
        require(confirm_source==self.adapter.source_binding()['source_sha256'], 'Exact authenticated package/source confirmation required')
        self.adapter.private(self.root)
        if self.path.exists():
            value=load(self.path)
            require(value['schema']=='philcst.fresh-setup.v1' and value['binding']==self.binding(), 'Recorded fresh intent differs; no automatic adoption/reinitialization')
            if value['step']=='complete':return self.verify()
        else:
            require(phase(self.base)['phase']=='running', 'Fresh finalization requires a known initial running D5A installation')
            require(not (self.base/'recovery-security.json').exists() and not (self.p['recovery']/'host.json').exists() and not (self.base.parent/'update-controller.json').exists(), 'Existing or partial protected state has no fresh intent; recover it explicitly')
            with database(self.p['targets']['database']) as db:
                for table in ('users','vehicles','vehicle_events','visitor_records','vehicle_crossings','rfid_scan_logs','delivery_receipts'):
                    require(db.execute('SELECT COUNT(*) FROM '+table).fetchone()[0]==0, 'Fresh finalization refuses operational data; no legacy adoption')
                row=db.execute('SELECT setup_completed_at FROM account_security_state LIMIT 1').fetchone()
                require(row is not None and row[0] is None, 'Completed personal setup cannot be reopened/adopted')
            value=self.save({'schema':'philcst.fresh-setup.v1','binding':self.binding(),
                             'operation':str(uuid.uuid4()),'owner':str(uuid.uuid4())},'intent')
        operation=value['operation']
        if phase(self.base)['phase']=='running' and value['step']=='prepared':
            self.save(value,'complete');return self.verify()
        # Only the recorded fresh intent can finish a crash between requiring
        # ownership and publishing that intent's initial receipt. Completed or
        # foreign installations never take this path.
        if phase(self.base).get('ownership_required') and not (self.base/'ownership.json').exists():
            require(value['step']=='recovery' and phase(self.base)['operation']==operation, 'Missing owner is not a known fresh transition')
            with barrier(self.base,'control'):
                atomic(self.base/'ownership.json',{'schema':1,'state':'active','deployment':identity(self.base)['installation'],'owner':value['owner'],'epoch':0})
                self.adapter.permissions_for('security',self.base/'ownership.json')
        if phase(self.base).get('operation')==operation and phase(self.base)['phase'] in ('quiescent','quiescent_pending'):
            self.rec.quiescent(operation)
        else:self.ctl.enter(operation,drain_seconds=0)
        if not (self.base.parent/'update-controller.json').exists():
            self.adapter.bootstrap_controller(confirm_source)
        self.adapter.capsule();value=self.save(value,'controller')
        journal=self.rec.operation_dir(operation)/'journal.json'
        if not journal.exists():self.rec.initialize(operation,resume=False)
        elif not ((self.p['recovery']/'host.json').exists() and (self.base/'recovery-security.json').exists()):
            self.rec.recover(operation,'current')
        else:
            self.rec.host();self.rec.ledger()
            from recovery_guard import check
            check(self.base,verify_journal=True)
        value=self.save(value,'recovery')
        with self.ctl.owned(operation),barrier(self.base,'control'):
            owner={'schema':1,'state':'active','deployment':identity(self.base)['installation'],'owner':value['owner'],'epoch':0}
            path=self.base/'ownership.json'
            require(not path.exists() or load(path)==owner, 'Ownership receipt differs from recorded fresh intent')
            atomic(self.base/'maintenance.json',dict(phase(self.base),ownership_required=True))
            atomic(path,owner);self.adapter.permissions_for('security',path)
            # An installed compatibility identity is useful without trusting any
            # publisher. This derived status explicitly keeps preparation disabled.
            status=self.base.parent/'update-status.json'
            if not status.exists():
                atomic(status,{'schema':1,'provisioned':False,'installed':installed_state(self.adapter),
                               'preferences':{'automatic_check':False,'automatic_download':False,'channel':'stable'},
                               'last_check':{'outcome':'Blocked: independently verified publisher trust has not been enrolled'},'candidate':None})
                self.adapter.permissions_for('network',status)
        value=self.save(value,'prepared')
        self.ctl.resume(operation)
        value=self.save(value,'complete')
        return self.verify()


def main():
    p=argparse.ArgumentParser(description='Explicit current-package fresh setup; no legacy adoption')
    p.add_argument('action',choices=['finish','verify','recover-owner']);p.add_argument('--root',type=Path,required=True);p.add_argument('--confirm-source')
    a=p.parse_args();adapter=NativeUpdate(layout(a.root));adapter.admin();setup=FreshSetup(adapter)
    if a.action=='recover-owner':
        from maintenance import process_alive
        lock=setup.root/'owner.lock';pid=int(lock.read_text());require(not process_alive(pid),'Setup owner is still alive')
        lock.unlink();print(json.dumps({'dead_owner_reviewed':pid,'writers_reopened':False}));return
    print(json.dumps(setup.run(a.confirm_source) if a.action=='finish' else setup.verify(),indent=2))


if __name__=='__main__':
    try:main()
    except Exception:
        print('Fresh setup incomplete or refused. Preserve all data/keys/receipts; review the recorded operation and native logs before explicit recovery.',file=sys.stderr);sys.exit(1)
