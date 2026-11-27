import copy
import json
import multiprocessing
import os
from pathlib import Path
import signal
import unittest
from unittest.mock import patch
from fixture import Pair
from contract import sha,canonical,sign,load
from backup_contract import database,tree_hash
from coordination import phase,atomic,admission,owner_headers
from service_host import allowed
from recovery import Recovery
from install import UpdateRecovery
from manager import Manager


def killed(pair,side,action,args,point):
    ctl=pair.s if side=='source' else pair.t
    ctl.checkpoint=lambda name:os.kill(os.getpid(),signal.SIGKILL) if name==point else None
    getattr(ctl,action)(*args)

class Planned(unittest.TestCase):
    def pair(self,**kwargs):
        f=Pair(**kwargs);self.addCleanup(f.close);return f
    def isolate(self,f):return f.t.attest_isolation(f.challenge['signed']['id'],'powered-off-and-disconnected','Synthetic technician verified source power and network disconnected','Synthetic administrator',True)
    def kill(self,f,side,action,args,point):
        process=multiprocessing.get_context('fork').Process(target=killed,args=(f,side,action,args,point));process.start();process.join(120)
        if process.is_alive():process.kill();process.join();self.fail('Synthetic handoff deadline')
        self.assertEqual(-signal.SIGKILL,process.exitcode)
        ctl=f.s if side=='source' else f.t
        (ctl.root/'owner.lock').unlink(missing_ok=True)
        if (ctl.base/'operation.lock').exists():ctl.ctl.recover(ctl.state()['operation'])
        ctl.checkpoint=lambda _:None

    def test_complete_two_hosts_and_retired_source_cannot_resume_restore_or_update(self):
        f=self.pair();before=f.source_fixture.f.php();tls=tree_hash(f.source.p['targets']['tls']/'tls')
        receipt=f.activate();f.s.acknowledge(receipt)
        self.assertEqual('retired',f.s.state()['phase']);self.assertEqual('active',f.t.state()['phase'])
        self.assertFalse(allowed(f.source.p['base'],'detector'));self.assertTrue(allowed(f.target.p['base'],'detector'))
        self.assertEqual(tls,tree_hash(f.target.p['targets']['tls']/'tls'));self.assertEqual(before['events'],f.target_fixture.f.php()['events'])
        with self.assertRaises(ValueError):f.s.ctl.resume(f.s.state()['operation'])
        with self.assertRaises(ValueError):Recovery(f.source).host()
        with self.assertRaises(ValueError):UpdateRecovery(f.source,Manager(f.source)).host()
        with self.assertRaises(ValueError):f.t.activate(sha(canonical(f.t.state()['import_manifest'])))
        self.assertNotEqual(owner_headers(f.source.p['base']) if admission(f.source.p['base']) else None,owner_headers(f.target.p['base']))

    def test_ec_tls_issuer_does_not_relax_rsa_transfer_authority(self):
        from fixture import up,Adapter
        from control import Transfer
        from contract import public,c
        import subprocess
        fixture=up.Fixture();self.addCleanup(fixture.close);adapter=Adapter(fixture.p)
        host=load(fixture.p['recovery']/'host.json');host['machine']=adapter.machine();atomic(fixture.p['recovery']/'host.json',host)
        issuer=fixture.p['targets']['tls']/'tls/pki/authorities/local'
        key=issuer/'root.key';certificate=issuer/'root.crt'
        subprocess.run(['openssl','req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-keyout',str(key),'-out',str(certificate),'-days','2','-subj','/CN=Synthetic EC issuer'],check=True,capture_output=True)
        with self.assertRaises(ValueError):public(adapter,key)
        authority=fixture.directory/'separate-rsa.pem'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(authority)],check=True,capture_output=True)
        before=key.read_bytes();peer=Transfer(adapter).bootstrap(authority,c.fingerprint(public(adapter,authority)),adapter.source_binding()['source_sha256'])
        self.assertEqual(before,key.read_bytes());self.assertIn('BEGIN PUBLIC KEY',peer['public_key'])

    def test_future_update_cannot_drop_required_ownership_control(self):
        f=self.pair()
        # The test package intentionally lacks deployment helpers; an initialized
        # D5D source must refuse it before any maintenance or program mutation.
        manifest,archive=f.source_fixture.package();manager=Manager(f.source);candidate=manager.import_local(manifest,archive)['candidate']
        with self.assertRaisesRegex(ValueError,'ownership fence'):UpdateRecovery(f.source,manager).plan(candidate)
        self.assertTrue(admission(f.source.p['base']))

    def test_retained_pending_and_lost_ack_real_php_kernel_no_duplicate_movement(self):
        f=self.pair();id=f.source_fixture.f.box.enqueue('crossing',f.source_fixture.f.php()['payload']);packet=f.packet(id)
        ack=f.request(False,packet);self.assertEqual(200,ack['status']);before=f.source_fixture.f.php();old_owner=load(f.source.p['base']/'ownership.json')['owner']
        pending=f.source_fixture.f.box.enqueue('crossing',dict(f.source_fixture.f.php()['payload'],external_event_key='d5d-distinct-pending'))
        pending_packet=f.packet(pending);receipt=f.activate()
        self.assertEqual(409,f.request(True,packet,old_owner)['status']);self.assertEqual(409,f.request(True,packet,'')['status'])
        retry=f.request(True,packet);self.assertEqual(200,retry['status']);self.assertTrue(retry['body']['duplicate']);self.assertEqual(ack['body']['delivery_ack'],retry['body']['delivery_ack'])
        after=f.target_fixture.f.php();self.assertEqual((before['events'],before['entries'],before['context']),(after['events'],after['entries'],after['context']))
        self.assertEqual(200,f.request(True,pending_packet)['status']);self.assertTrue(f.request(True,pending_packet)['body']['duplicate'])
        from durable_delivery import Outbox
        self.assertEqual('pending',Outbox(f.target.p['base'],min_free=0).inspect(id)['state']);self.assertEqual(packet,f.packet(id))
        self.assertEqual(503,f.request(False,packet,old_owner)['status'])

    def test_actual_http_retained_outbox_uses_current_owner_header_and_exact_ack(self):
        f=self.pair();id=f.source_fixture.f.box.enqueue('crossing',f.source_fixture.f.php()['payload']);f.activate()
        import socket,subprocess,time,urllib.request
        from durable_delivery import Outbox
        from fixture import ROOT
        from backup_contract import env_values
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        env=f.target_fixture.f.env|env_values(f.target.p['targets']['environment'])|{'APP_URL':'http://127.0.0.1:'+str(port),'PHILCST_NAMED_HTTPS':'false'}
        # Laravel's real route/controller/kernel and Python requests transport;
        # only database/storage/issuer and OS service adapters are fixtures.
        process=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),str(ROOT/'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],cwd=ROOT/'public',env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for attempt in range(60):
                try:urllib.request.urlopen('http://127.0.0.1:'+str(port)+'/up',timeout=1);break
                except Exception:time.sleep(.05)
            box=Outbox(f.target.p['base'],min_free=0);box.deliver_one('http://127.0.0.1:'+str(port)+'/api/v1/integration/deliveries','synthetic-d5b-key')
            self.assertEqual('acked',box.inspect(id)['state']);self.assertEqual(1,f.target_fixture.f.php()['receipts'])
        finally:process.terminate();process.wait(timeout=10)

    def test_exact_media_secrets_accounts_lost_uid_update_floor_and_calibration_survive(self):
        f=self.pair()
        with database(f.source.p['targets']['database'],write=True) as db:
            db.execute("INSERT INTO unavailable_rfid_uids(uid,marked_at,previous_status) VALUES('0000CCDD',CURRENT_TIMESTAMP,'available')")
            db.execute("UPDATE users SET session_version=19,remember_token='old',activation_hash='old-code',account_status='disabled'")
            db.execute("UPDATE cameras SET calibration_mask_json='[{\"x\":0.1,\"y\":0.2},{\"x\":0.9,\"y\":0.2},{\"x\":0.9,\"y\":0.8}]'")
            original={t:[dict(r) for r in db.execute('SELECT * FROM '+t)] for t in ('gates','cameras','vehicle_events','vehicle_crossings','visitor_records')}
        value=f.source_fixture.store.read();value['floors']={'stable':{'sequence':7,'sha256':'c'*64}};value['release_floor']={'stable':'1.2.0'};f.source_fixture.store.write(value)
        env=load(f.source.p['targets']['tls_identity']);before_media=tree_hash(f.source.p['targets']['storage']/'app/private');f.activate()
        with database(f.target.p['targets']['database']) as db:
            user=db.execute('SELECT * FROM users').fetchone();self.assertEqual('disabled',user['account_status']);self.assertGreater(user['session_version'],19);self.assertIsNone(user['remember_token']);self.assertIsNone(user['activation_hash'])
            self.assertIsNotNone(db.execute('SELECT setup_completed_at FROM account_security_state').fetchone()[0]);self.assertIsNone(db.execute('SELECT setup_hash FROM account_security_state').fetchone()[0]);self.assertEqual('0000CCDD',db.execute('SELECT uid FROM unavailable_rfid_uids').fetchone()[0])
            for table,rows in original.items():self.assertEqual(rows,[dict(r) for r in db.execute('SELECT * FROM '+table)])
        self.assertEqual(env,load(f.target.p['targets']['tls_identity']));self.assertEqual(before_media,tree_hash(f.target.p['targets']['storage']/'app/private'))
        target=load(f.target.p['targets']['update_trust']/'control.json');self.assertEqual(value['floors'],target['floors']);self.assertEqual(value['clock_floor'],target['clock_floor']);self.assertEqual(value['trust'],target['trust'])
        self.assertTrue(f.t.state()['target_proof']['crypto']['decrypted_camera_secrets']>=1)

    def test_wrong_target_unknown_signature_tamper_replay_and_conflicting_target_are_rejected(self):
        f=self.pair();result=f.prepared();envelope=result['manifest'];confirmation=sha(canonical(envelope))
        with self.assertRaises(ValueError):f.s.plan(f.challenge,f.decisions,'Synthetic administrator')
        with self.assertRaises(ValueError):f.t.import_set(f.archive,'0'*64)
        body=copy.deepcopy(envelope);body['signed']['target_host']='wrong';atomic(f.archive/'TRANSFER.json',body)
        with self.assertRaises(ValueError):f.t.inspect(f.archive)
        atomic(f.archive/'TRANSFER.json',envelope);body=copy.deepcopy(envelope);body['key_id']='f'*64;atomic(f.archive/'TRANSFER.json',body)
        with self.assertRaises(ValueError):f.t.inspect(f.archive)
        atomic(f.archive/'TRANSFER.json',envelope);f.t.import_set(f.archive,confirmation)
        with self.assertRaises(ValueError):f.t.import_set(f.archive,confirmation)
        with self.assertRaises(ValueError):f.t.challenge(f.peers[0]['key_id'],'Synthetic administrator')

    def test_corrupt_truncated_archive_wrong_key_missing_private_state_fail_closed(self):
        f=self.pair();result=f.prepared();archive=f.archive/'backup.zip';raw=archive.read_bytes();archive.write_bytes(raw[:123])
        with self.assertRaises(ValueError):f.t.inspect(f.archive)
        archive.write_bytes(raw);peer=f.t.state();key=next(iter(peer['peers']));original=peer['peers'][key]['public_key'];peer['peers'][key]['public_key']=f.peers[1]['public_key'];f.t.save(peer)
        with self.assertRaises(ValueError):f.t.inspect(f.archive)
        peer['peers'][key]['public_key']=original;f.t.save(peer)
        (f.target.p['base']/'ownership.json').unlink()
        self.assertFalse(admission(f.target.p['base']));self.assertFalse(admission(f.source.p['base']))
        with self.assertRaises(ValueError):f.t.ctl.resume(f.t.state()['operation'])

    def test_source_cancel_requires_target_signed_consumption_and_never_after_activation(self):
        f=self.pair();f.prepared();receipt=f.t.reject();f.s.cancel_source(receipt)
        self.assertTrue(admission(f.source.p['base']));self.assertFalse(admission(f.target.p['base']))
        with self.assertRaises(ValueError):f.t.import_set(f.archive,sha(canonical(f.s.state()['export'])))
        with self.assertRaises(ValueError):f.s.cancel_source(receipt)

    def test_cancelled_source_requires_new_challenge_and_does_not_reuse_signed_export(self):
        f=self.pair();f.prepared();old=f.s.state()['export'];f.s.cancel_source(f.t.reject())
        # A new fresh target is independently enrolled. Its distinct nonce and
        # key must produce a different signed handoff; the cancelled export stays historical.
        other=self.pair();new_target=other.t;new_peer=other.peers[1]
        f.s.enroll(new_peer,new_peer['key_id']);new_target.enroll(f.peers[0],f.peers[0]['key_id'])
        challenge=new_target.challenge(f.peers[0]['key_id'],'Synthetic administrator');plan=f.s.plan(challenge,f.decisions,'Synthetic administrator')
        result=f.s.prepare(challenge,f.decisions,'Synthetic administrator',plan['confirmation'])
        self.assertNotEqual(old['signed']['transfer'],result['manifest']['signed']['transfer']);self.assertEqual(challenge,result['manifest']['signed']['challenge'])
        self.assertTrue(list((f.s.root/'history').glob('*.json')))

    def test_activation_requires_isolation_and_target_network_readiness(self):
        f=self.pair();confirmation=f.staged()
        with self.assertRaises((ValueError,KeyError)):f.t.activate(confirmation)
        with self.assertRaises(ValueError):f.t.attest_isolation(f.challenge['signed']['id'],'timeout','Server did not answer','Synthetic administrator',True)
        self.isolate(f);f.target.failure='network'
        with self.assertRaises(ValueError):f.t.activate(confirmation)
        self.assertFalse(admission(f.source.p['base']));self.assertFalse(admission(f.target.p['base']));f.target.failure=None
        f.t.activate(confirmation)
        with self.assertRaises(ValueError):f.t.reject()
        with self.assertRaises(ValueError):f.s.ctl.resume(f.s.state()['operation'])

    def test_windows_to_mac_layout_uses_target_runtime_and_preserves_source_generation(self):
        f=self.pair(source_windows=True);before=f.source.source_binding();receipt=f.activate()
        self.assertEqual('windows-x64',before['platform']);self.assertEqual('macos-arm64',f.target.source_binding()['platform'])
        from backup_contract import env_values
        env=env_values(f.target.p['targets']['environment']);self.assertEqual(str(f.target.p['targets']['database']),env['DB_DATABASE']);self.assertNotIn(str(f.source.p['root']),f.target.p['targets']['environment'].read_text())
        self.assertEqual(load(f.source.p['base']/'identity.json'),load(f.target.p['base']/'identity.json'))
        catalog=load(f.target.p['targets']['update_trust']/'control.json');self.assertEqual('macos-arm64',catalog['state']['platform'])
        self.assertEqual('macos-arm64',f.target.source_binding()['platform'])

    def test_mac_to_windows_layout_regenerates_file_configuration_and_native_root_paths(self):
        f=self.pair(target_windows=True);f.activate()
        self.assertTrue(f.target.p['targets']['configuration'].is_file());self.assertEqual('windows-x64',f.target.source_binding()['platform'])
        from backup_contract import env_values
        env=env_values(f.target.p['targets']['environment']);self.assertEqual(str(f.target.p['targets']['database']),env['DB_DATABASE'])
        self.assertNotIn(str(f.source.p['root']),f.target.p['targets']['environment'].read_text())

    def test_unapproved_runtime_and_disaster_paths_stay_blocked(self):
        f=self.pair();f.target.policy['paths']=[];f.source.policy['paths']=[]
        challenge=f.t.challenge(f.peers[0]['key_id'],'Synthetic administrator')
        with self.assertRaises(ValueError):f.s.plan(challenge,f.decisions,'Synthetic administrator')
        # A same-installation safety set is not authenticated transfer authority.
        backup=Path(f.s.rec.backup('11111111-2222-4333-8444-555555555555',resume=True)['backup']);before=tree_hash(f.target.p['targets']['environment']);report=f.t.disaster_inspect(backup)
        self.assertIn('NOT ACTIVATABLE',report['mode']);self.assertEqual(False,report['active_state_mutated']);self.assertEqual(before,tree_hash(f.target.p['targets']['environment']))
        with self.assertRaises((ValueError,OSError)):f.t.import_set(backup,'0'*64)

    def test_concurrent_transfer_owner_and_missing_ownership_remain_fenced(self):
        f=self.pair()
        with f.s.owned():
            with self.assertRaises(ValueError):
                with f.s.owned():pass
        (f.source.p['base']/'ownership.json').unlink();self.assertFalse(admission(f.source.p['base']));self.assertFalse(allowed(f.source.p['base'],'devices'))
        with self.assertRaises(ValueError):f.s.ctl.enter('11111111-1111-4111-8111-111111111111')

    def test_process_kill_source_retirement_export_and_target_extraction_promotion_activation(self):
        for point in ('transfer:captured','ownership:retired','target:extracted','target:new:database','transfer:activating','target:running'):
            with self.subTest(point=point):
                f=self.pair();f.challenge=f.t.challenge(f.peers[0]['key_id'],'Synthetic administrator');plan=f.s.plan(f.challenge,f.decisions,'Synthetic administrator')
                if point in ('transfer:captured','ownership:retired'):
                    self.kill(f,'source','prepare',(f.challenge,f.decisions,'Synthetic administrator',plan['confirmation']),point)
                    result=f.s.finish_export();f.archive=Path(result['archive'])
                else:
                    result=f.s.prepare(f.challenge,f.decisions,'Synthetic administrator',plan['confirmation']);f.archive=Path(result['archive'])
                confirmation=sha(canonical(result['manifest']))
                if point=='target:extracted':
                    self.kill(f,'target','import_set',(f.archive,confirmation),point);f.t.recover_import()
                else:f.t.import_set(f.archive,confirmation)
                if point=='target:new:database':self.kill(f,'target','promote',(),point)
                f.t.promote();self.isolate(f)
                if point in ('transfer:activating','target:running'):self.kill(f,'target','activate',(confirmation,),point)
                receipt=f.t.activate(confirmation);f.s.acknowledge(receipt)
                self.assertTrue(admission(f.target.p['base']));self.assertFalse(admission(f.source.p['base']))

if __name__=='__main__':unittest.main()
