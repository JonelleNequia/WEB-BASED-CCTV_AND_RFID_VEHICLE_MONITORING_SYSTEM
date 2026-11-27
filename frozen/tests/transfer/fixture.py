"""Two distinct synthetic hosts. No native services, trust stores or working data."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'deploy/transfer'),str(ROOT/'deploy/lifecycle'),str(ROOT/'deploy/network')]
from control import Transfer
from contract import c,sha,canonical
from backup_contract import load,database,sqlite_info,env_values
from coordination import atomic,identity
spec=importlib.util.spec_from_file_location('d5d_update_fixture',ROOT/'tests/updater/fixture.py');up=importlib.util.module_from_spec(spec);spec.loader.exec_module(up)
spec=importlib.util.spec_from_file_location('d5d_native_fixture',ROOT/'deploy/transfer/native.py');nt=importlib.util.module_from_spec(spec);spec.loader.exec_module(nt)

class Adapter(up.Adapter):
    def machine(self):return sha(str(self.p['root']).encode())
    def transfer_policy(self):return self.policy
    def crypto_check(self,payload):
        assert str(self.p['root']) in str(self.p['base']), 'Source validation redirected target verifier state'
        return super().crypto_check(payload)
    relocate_transfer=nt.NativeTransfer.relocate_transfer
    def validate_transfer_activation(self,body):
        if self.failure=='network':raise ValueError('Synthetic target exposure/address conflict')
        self.verify_installed_program()

class Pair:
    def __init__(self,source_windows=False,target_windows=False):
        self.source_fixture=up.Fixture(source_windows);self.target_fixture=up.Fixture(target_windows)
        self.source=Adapter(self.source_fixture.p);self.target=Adapter(self.target_fixture.p)
        self.s=Transfer(self.source);self.t=Transfer(self.target)
        for fixture,adapter in ((self.source_fixture,self.source),(self.target_fixture,self.target)):
            adapter.p=fixture.p
            host=load(fixture.p['recovery']/'host.json');host['machine']=adapter.machine();atomic(fixture.p['recovery']/'host.json',host)
            config=fixture.p['release']/'config';config.mkdir(exist_ok=True)
            shutil.copy2(ROOT/('build/windows/templates/Caddyfile.template' if fixture.p['windows'] else 'build/macos/templates/Caddyfile.template'),config/'Caddyfile.template')
            atomic(fixture.p['targets']['network'],{'schema':1,'config':{'schema':1,'mode':'local','address':None,'interface':None,'remote_scope':None},'fixture_native_receipts':True})
        # Fresh target has only non-account InstallSeeder configuration. It is not
        # another operational DB merged into the source.
        path=self.target.p['targets']['database']
        import sqlite3
        db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=OFF')
        for table in ('users','vehicles','vehicle_rfid_tags','vehicle_events','visitor_records','vehicle_crossings','rfid_scan_logs','guest_vehicle_observations','security_events','sessions','delivery_receipts'):db.execute('DELETE FROM '+table)
        db.commit();db.close()
        # Source and target use same reviewed source/migration graph, explicit
        # architecture-specific runtime pair. No production policy is populated.
        rule={'source':self.source.source_binding(),'target':self.target.source_binding(),'database_schema_sha256':sqlite_info(self.source.p['targets']['database'])['schema_sha256']}
        for adapter in (self.source,self.target):adapter.policy={'schema':'philcst.transfer-paths.v1','product':'philcst-vms','paths':[rule]}
        self.peers=[]
        for fixture,ctl in ((self.source_fixture,self.s),(self.target_fixture,self.t)):
            key=fixture.directory/'transfer-private.pem';subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(key)],capture_output=True,check=True);key.chmod(0o600)
            public=subprocess.check_output(['openssl','pkey','-in',str(key),'-pubout']).decode()
            peer=ctl.bootstrap(key,c.fingerprint(public),ctl.adapter.source_binding()['source_sha256']);self.peers.append(peer)
        self.s.enroll(self.peers[1],self.peers[1]['key_id']);self.t.enroll(self.peers[0],self.peers[0]['key_id'])
        self.decisions={'tls':'preserve','target_network':{'schema':1,'mode':'local','address':None,'interface':None,'remote_scope':None},'isolation_required':True,'custody':'approved-encrypted-volume'}

    def request(self,target,packet,owner=None):
        fixture=self.target_fixture if target else self.source_fixture
        if owner is None:owner=load((self.target if target else self.source).p['base']/'ownership.json')['owner']
        result=subprocess.run(['php',str(ROOT/'tests/fixtures/d5d-runtime.php')],env=fixture.f.env|env_values(fixture.p['targets']['environment'])|{'PHILCST_D5D_OWNER':owner},input=json.dumps(packet),cwd=ROOT,capture_output=True,text=True,timeout=40)
        if result.returncode:raise AssertionError(result.stdout+result.stderr)
        return json.loads(result.stdout)
    def packet(self,id):
        with self.source_fixture.f.box.db() as db:row=dict(db.execute('SELECT * FROM operations WHERE id=?',(id,)).fetchone())
        return {'envelope':row['envelope'],'sha256':row['sha'],'media':json.loads(row['media'])}
    def prepared(self):
        self.challenge=self.t.challenge(self.peers[0]['key_id'],'Synthetic administrator')
        plan=self.s.plan(self.challenge,self.decisions,'Synthetic administrator')
        result=self.s.prepare(self.challenge,self.decisions,'Synthetic administrator',plan['confirmation']);self.archive=Path(result['archive']);return result
    def staged(self):
        result=self.prepared();confirmation=sha(canonical(result['manifest']));self.t.import_set(self.archive,confirmation);self.t.promote();return confirmation
    def activate(self):
        confirmation=self.staged();self.t.attest_isolation(self.challenge['signed']['id'],'powered-off-and-disconnected','Synthetic technician disconnected source power and network','Synthetic administrator',True);return self.t.activate(confirmation)
    def close(self):self.source_fixture.close();self.target_fixture.close()
