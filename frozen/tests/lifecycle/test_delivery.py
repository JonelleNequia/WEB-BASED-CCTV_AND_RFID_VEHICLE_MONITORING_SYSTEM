import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import signal
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import uuid
from datetime import datetime,timezone
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'deploy/lifecycle'),str(ROOT/'school-vehicle-monitoring-detector')]
import coordination as c
from durable_delivery import Outbox,CapacityError
from maintenance import Coordinator
from service_host import allowed


PAYLOAD={'external_event_key':'original-pass','camera_role':'gate-1','camera_id':7,'event_time':'2026-11-09T10:00:00+08:00','direction':'IN'}


class Response:
    def __init__(self,status=200,body=None,headers=None):self.status_code=status;self.body=body or {};self.headers=headers or {}
    def json(self):return self.body


def ack(url,**kwargs):
    operation=json.loads(kwargs['json']['envelope'])
    return Response(body={'result':{'retained':True},'delivery_ack':{'schema':1,'operation':operation['operation'],'sha256':kwargs['json']['sha256'],
        'installation':operation['installation'],'generation':operation['generation'],'committed':True}})


class Adapter:
    def __init__(self):self.calls=[];self.fail=None
    def step(self,name):
        self.calls.append(name)
        if self.fail==name:raise ValueError('Isolated '+name+' failure')
    def snapshot(self):self.step('snapshot');return {'owned':'synthetic'}
    def quiesce(self,r):self.step('quiesce')
    def drain(self,seconds):self.step('drain')
    def stop(self,r):self.step('stop')
    def verify(self,r):self.step('verify')
    def check(self):self.step('check')
    def start_access(self,r):self.step('access')
    def ready(self):self.step('ready')
    def reopen(self,r):self.step('reopen')


class Fixture(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='d5a-fixture-');self.base=Path(self.temp.name)/'lifecycle';c.initialize(self.base)
        self.box=Outbox(self.base,initialize=True,min_free=0)
    def tearDown(self):self.temp.cleanup()
    def enqueue(self,**changes):return self.box.enqueue('crossing',PAYLOAD|changes,{'snapshot':b'original immutable scene'})


class Delivery(Fixture):
    def test_allocated_page_limit_applies_to_reopened_connections_and_large_acks_are_bounded(self):
        with self.box.db() as db:self.assertLessEqual(db.execute('PRAGMA max_page_count').fetchone()[0]*4096,self.box.max_bytes+32*1024*1024)
        operation=self.enqueue()
        def large_ack(*args,**kwargs):
            response=ack(*args,**kwargs);response.body['result']={'details':'x'*65536};return response
        self.assertTrue(self.box.deliver_one('fixture','key',large_ack)['accepted'])
        with self.box.db() as db:self.assertLess(len(db.execute('SELECT result FROM operations WHERE id=?',(operation,)).fetchone()[0]),32768)
    def test_same_original_scan_retries_keep_identity_but_new_captures_remain_distinct(self):
        payload={'scan_time':'2026-11-09T01:00:00.123456+00:00','scan_location':'gate-1','tag_uid':'00001234'}
        first=self.box.enqueue('rfid',payload)
        self.assertEqual(first,self.box.enqueue('rfid',payload))
        self.assertNotEqual(first,self.box.enqueue('rfid',payload|{'scan_time':'2026-11-09T01:00:00.123457+00:00'}))
    def test_paused_device_result_retains_tuple_return_contract(self):
        from durable_delivery import capture_work
        @capture_work(tuple_result=True)
        def post_scan():return True,None
        with patch.dict(os.environ,{'PHILCST_LIFECYCLE_ROOT':str(self.base)}):
            state=c.phase(self.base);state['phase']='fenced';c.atomic(self.base/'maintenance.json',state)
            ok,error=post_scan()
        self.assertFalse(ok);self.assertIn('paused',error)
    def test_isolated_python_entrypoints_import_only_their_trusted_script_paths(self):
        for name in ('deploy/lifecycle/maintenance.py','deploy/network/server.py','deploy/lifecycle/service_host.py'):
            result=subprocess.run([sys.executable,'-I','-B',str(ROOT/name),'--help'],cwd=self.temp.name,text=True,capture_output=True)
            self.assertEqual(result.returncode,0,result.stderr);self.assertIn('usage:',result.stdout)
    def test_review_export_preserves_original_bytes_and_queue_without_secret_diagnostics(self):
        png=b'\x89PNG\r\n\x1a\nfixture-original'
        operation=self.box.enqueue('crossing',PAYLOAD|{'private_debug':'not-in-diagnostics'},{'snapshot':png})
        before=self.box.path.read_bytes();destination=Path(self.temp.name)/'private-export'
        self.box.export_media(operation,destination,self.base)
        self.assertEqual((destination/'snapshot.png').read_bytes(),png)
        self.assertEqual(before,self.box.path.read_bytes());self.assertNotIn('private_debug',(destination/'REVIEW.json').read_text())
        with self.assertRaises(ValueError):self.box.export_media(operation,self.base/'unsafe-export',self.base)
    def test_unavailable_then_recovery_retains_original_identity_time_and_bytes(self):
        operation=self.enqueue();before=self.box.path.read_bytes()
        def unavailable(*args,**kwargs):raise TimeoutError('fixture secret must not be logged')
        result=self.box.deliver_one('http://fixture','secret',unavailable,now=time.time()+1)
        self.assertFalse(result['accepted']);self.assertTrue(result['queued']);self.assertNotIn('secret',json.dumps(self.box.status()))
        sent=[]
        def inspect(url,**kwargs):sent.append(kwargs['json']);return ack(url,**kwargs)
        self.assertTrue(self.box.deliver_one('http://fixture','secret',inspect,now=time.time()+100)['accepted'])
        content=json.loads(sent[0]['envelope']);self.assertEqual(content['operation'],operation);self.assertEqual(content['payload'],PAYLOAD)
        self.assertEqual(base64.b64decode(sent[0]['media']['snapshot']),b'original immutable scene')
    def test_arbitrary_200_wrong_ack_and_retry_after_are_not_acceptance(self):
        self.enqueue();now=time.time()+1
        self.box.deliver_one('fixture','key',lambda *a,**k:Response(),now)
        self.assertEqual(self.box.status()['counts'],{'pending':1})
        self.box.deliver_one('fixture','key',lambda *a,**k:Response(429,headers={'Retry-After':'120'}),now+100)
        with self.box.db() as db:row=db.execute('SELECT next,attempts FROM operations').fetchone()
        self.assertGreaterEqual(row['next'],now+220);self.assertEqual(row['attempts'],2)
    def test_permanent_auth_payload_and_context_failures_remain_reviewable(self):
        for code in (401,409,422):
            with self.subTest(code=code):
                operation=self.enqueue(external_event_key='failure-'+str(code),camera_role='gate-'+str(code))
                self.box.deliver_one('fixture','never-output',lambda *a,**k:Response(code),time.time()+100)
                self.assertFalse(self.box.result(operation)['accepted'])
        self.assertEqual(self.box.status()['counts'],{'dead':3})
        self.assertIsNone(self.box.claim(time.time()+1000))
    def test_distinct_revisions_and_plate_evidence_are_causally_ordered(self):
        first=self.enqueue();second=self.box.enqueue('crossing',PAYLOAD|{'confidence':.95},{'snapshot':b'better ORIGINAL frame'})
        plate=self.box.enqueue('plate',PAYLOAD|{'plate_status':'read','plate_number':'395ISX'},{'plate_image':b'original crop'})
        self.assertEqual(len({first,second,plate}),3)
        self.box.deliver_one('fixture','key',lambda *a,**k:Response(409),time.time()+1)
        self.assertIsNone(self.box.claim(time.time()+100));self.assertEqual(self.box.status()['blocked_dependencies'],1)
        self.box.requeue(first)
        for operation in (first,second,plate):
            result=self.box.deliver_one('fixture','key',ack,time.time()+1000);self.assertEqual(result['operation'],operation);self.assertTrue(result['accepted'])
    def test_concurrent_workers_claim_one_operation_only(self):
        operation=self.enqueue();sent=[]
        def slow(url,**kwargs):sent.append(kwargs['json']);time.sleep(.1);return ack(url,**kwargs)
        with ThreadPoolExecutor(max_workers=6) as pool:list(pool.map(lambda _:self.box.deliver_one('fixture','key',slow),range(6)))
        self.assertEqual(len(sent),1);self.assertTrue(self.box.result(operation)['accepted'])
    def test_stale_worker_cannot_overwrite_a_newer_ack(self):
        operation=self.enqueue();ready=threading.Event();finish=threading.Event()
        def stale(url,**kwargs):ready.set();finish.wait(3);raise TimeoutError()
        with ThreadPoolExecutor(max_workers=1) as pool:
            future=pool.submit(self.box.deliver_one,'fixture','key',stale,time.time())
            self.assertTrue(ready.wait(2));self.box.deliver_one('fixture','key',ack,time.time()+31);finish.set();future.result()
        self.assertTrue(self.box.result(operation)['accepted'])
    def test_capacity_refuses_new_capture_without_evicting_pending_media(self):
        limited=Outbox(self.base,max_operations=1,min_free=0);operation=limited.enqueue('crossing',PAYLOAD,{'snapshot':b'keep'})
        with self.assertRaises(CapacityError):limited.enqueue('crossing',PAYLOAD|{'external_event_key':'another'})
        with limited.db() as db:media=db.execute('SELECT media FROM operations WHERE id=?',(operation,)).fetchone()[0]
        self.assertEqual(base64.b64decode(json.loads(media)['snapshot']),b'keep');self.assertFalse(limited.status()['ready'])
    def test_corrupted_evidence_is_retained_as_dead_not_sent(self):
        self.enqueue()
        with self.box.db() as db:db.execute("UPDATE operations SET media='{}'")
        sent=[]
        self.box.deliver_one('fixture','key',lambda *a,**k:sent.append(1))
        self.assertEqual(sent,[]);self.assertEqual(self.box.status()['counts'],{'dead':1})
    def test_wrong_generation_or_missing_spool_never_creates_a_replacement(self):
        self.enqueue();original=c.identity(self.base);c.atomic(self.base/'identity.json',original|{'generation':str(uuid.uuid4())})
        with self.assertRaises(c.Paused):Outbox(self.base)
        with self.assertRaises(c.Paused):self.box.claim()
        self.box.path.unlink()
        with self.assertRaises(c.Paused):Outbox(self.base)
        self.assertFalse(self.box.path.exists())
    def test_committed_queue_survives_abrupt_process_termination(self):
        code='import sys,time;sys.path[:0]=sys.argv[1:3];from durable_delivery import Outbox;import json; b=Outbox(sys.argv[3],min_free=0);print(b.enqueue("crossing",json.loads(sys.argv[4]),{"snapshot":b"captured-before-kill"}),flush=True);time.sleep(60)'
        process=subprocess.Popen([sys.executable,'-B','-c',code,str(ROOT/'deploy/lifecycle'),str(ROOT/'school-vehicle-monitoring-detector'),str(self.base),json.dumps(PAYLOAD)],stdout=subprocess.PIPE,text=True)
        try:operation=process.stdout.readline().strip();self.assertEqual(len(operation),64)
        finally:process.kill();process.wait();process.stdout.close()
        restarted=Outbox(self.base,min_free=0);self.assertTrue(restarted.result(operation)['queued'])
        self.assertTrue(restarted.deliver_one('fixture','key',ack)['accepted'])
    def test_interrupted_media_update_rolls_back_without_partial_valid_operation(self):
        operation=self.enqueue()
        code='import sqlite3,sys,time;d=sqlite3.connect(sys.argv[1]);d.execute("BEGIN IMMEDIATE");d.execute("UPDATE operations SET media=\'incomplete\'");print("uncommitted",flush=True);time.sleep(60)'
        process=subprocess.Popen([sys.executable,'-B','-c',code,str(self.box.path)],stdout=subprocess.PIPE,text=True)
        try:self.assertEqual(process.stdout.readline().strip(),'uncommitted')
        finally:process.kill();process.wait();process.stdout.close()
        self.assertTrue(Outbox(self.base).deliver_one('fixture','key',ack)['accepted']);self.assertTrue(self.box.result(operation)['accepted'])
    def test_status_is_read_only_for_identity_state_secrets_and_database(self):
        self.enqueue();secret=self.base/'fixture-secret';secret.write_bytes(b'unchanged')
        files=[self.base/'identity.json',self.base/'maintenance.json',secret,self.box.path]
        before={f:f.read_bytes() for f in files};Coordinator(self.base,Adapter()).status()
        self.assertEqual(before,{f:f.read_bytes() for f in files})


class Maintenance(Fixture):
    def test_mac_stop_and_fenced_start_refuse_before_any_launchctl_call(self):
        import importlib.util
        spec=importlib.util.spec_from_file_location('d5a_mac_control',ROOT/'deploy/macos/control.py');mac=importlib.util.module_from_spec(spec);spec.loader.exec_module(mac)
        with patch.object(mac,'paths',return_value=(self.base.parent,self.base.parent,self.base.parent)),patch.object(mac,'run',side_effect=AssertionError('No native operation allowed')):
            with self.assertRaisesRegex(ValueError,'owned maintenance'):mac.launch(self.base.parent,'stop')
            c.atomic(self.base/'maintenance.json',c.phase(self.base)|{'phase':'failed'})
            with self.assertRaisesRegex(ValueError,'Maintenance persists'):mac.launch(self.base.parent,'start')
    def test_dead_producer_review_retains_live_work_and_all_pending_evidence(self):
        self.enqueue();owner=str(uuid.uuid4());ctl=Coordinator(self.base,Adapter())
        c.atomic(self.base/'maintenance.json',c.phase(self.base)|{'phase':'quiescing','operation':owner,'adapter':{}})
        with c.barrier(self.base,'control') as db:
            for token,pid in [('dead',99),('live',100)]:db.execute('INSERT INTO producer_jobs VALUES(?,?,?,?)',(token,pid,'fixture',time.time()))
        before=self.box.path.read_bytes()
        with patch('maintenance.process_alive',side_effect=lambda pid:pid==100):reviewed=ctl.review_dead_producers(owner)
        self.assertEqual([x['token'] for x in reviewed],['dead']);self.assertEqual([x['token'] for x in c.jobs(self.base)],['live'])
        self.assertEqual(before,self.box.path.read_bytes());self.assertEqual(c.phase(self.base)['phase'],'quiescing')
    def test_d4_mode_change_uses_owned_quiescence_and_keeps_pending_work(self):
        import importlib.util,maintenance
        sys.path.insert(0,str(ROOT/'deploy/network'))
        spec=importlib.util.spec_from_file_location('d5a_mode_fixture',ROOT/'deploy/network/server.py');network=importlib.util.module_from_spec(spec);spec.loader.exec_module(network)
        self.enqueue();before=self.box.path.read_bytes();adapter=Adapter();adapter.admin=lambda:None
        def prepared(*args,**kwargs):
            self.assertEqual(c.phase(self.base)['phase'],'resuming');self.assertFalse(allowed(self.base,'detector'))
            kwargs['verification']();return {'mode':'lan'}
        with patch.object(network,'preflight_configuration'),patch.object(network,'_configure',side_effect=prepared),patch.object(maintenance,'layout',return_value={}),patch.object(maintenance,'Native',return_value=adapter):
            network.configure({'shared':self.base.parent,'root':self.base.parent},{'mode':'lan'})
        self.assertEqual(c.phase(self.base)['phase'],'running');self.assertEqual(before,self.box.path.read_bytes())
        self.assertLess(adapter.calls.index('stop'),adapter.calls.index('access'))
    def test_native_source_or_secret_mismatch_is_rejected_before_runtime_execution(self):
        import maintenance
        release=self.base.parent/'release';app=release/'app';app.mkdir(parents=True)
        (app/'.env').write_text('APP_KEY=fixture-key\nDETECTOR_API_KEY=fixture-api\n')
        build={'version':'1.0.0','architecture':'arm64','source':{'source_sha256':'a'*64}}
        (release/'BUILD-INFO.json').write_text(json.dumps(build))
        c.atomic(self.base/'maintenance.json',c.phase(self.base)|{'installed_build':{},'secret_fingerprints':maintenance.secret_fingerprints(app/'.env')})
        native=maintenance.Native({'base':self.base,'release':release,'app':app})
        with patch.object(maintenance,'command',side_effect=AssertionError('Must not execute unvalidated runtime')):
            with self.assertRaisesRegex(ValueError,'binding changed'):native.check()
            c.atomic(self.base/'maintenance.json',c.phase(self.base)|{'installed_build':{'version':'1.0.0','architecture':'arm64','source_sha256':'a'*64}})
            (app/'.env').write_text('APP_KEY=changed\nDETECTOR_API_KEY=fixture-api\n')
            with self.assertRaisesRegex(ValueError,'secret identity changed'):native.check()
    def test_interrupted_owner_requires_explicit_recovery_without_automatic_reopen(self):
        owner=str(uuid.uuid4())
        code='''import sys,time;sys.path[:0]=sys.argv[1:3]
from maintenance import Coordinator
from coordination import phase
ctl=Coordinator(sys.argv[3],None)
with ctl.owned(sys.argv[4]):
 ctl.set(phase(sys.argv[3]),'quiescing',operation=sys.argv[4],adapter={'owned':'synthetic'})
 print('owned',flush=True);time.sleep(60)
'''
        process=subprocess.Popen([sys.executable,'-B','-c',code,str(ROOT/'deploy/lifecycle'),str(ROOT/'school-vehicle-monitoring-detector'),str(self.base),owner],stdout=subprocess.PIPE,text=True)
        try:self.assertEqual(process.stdout.readline().strip(),'owned')
        finally:process.kill();process.wait();process.stdout.close()
        ctl=Coordinator(self.base,Adapter())
        with self.assertRaises(ValueError):ctl.enter(str(uuid.uuid4()),0)
        self.assertEqual(ctl.status()['lock_owner']['operation'],owner)
        ctl.recover(owner);self.assertEqual(c.phase(self.base)['phase'],'quiescing');self.assertFalse(allowed(self.base,'devices'))
        ctl.resume(owner);self.assertEqual(c.phase(self.base)['phase'],'running')
    def test_resume_rejects_generation_changes_without_rewriting_retained_work(self):
        self.enqueue();owner=str(uuid.uuid4());ctl=Coordinator(self.base,Adapter());ctl.enter(owner,0)
        before=self.box.path.read_bytes();c.atomic(self.base/'identity.json',c.identity(self.base)|{'generation':str(uuid.uuid4())})
        with self.assertRaises(c.Paused):ctl.resume(owner)
        self.assertEqual(self.box.path.read_bytes(),before);self.assertEqual(c.phase(self.base)['phase'],'failed')
        self.assertIn('delivery_error',ctl.status())
    def test_missing_barrier_does_not_create_a_replacement_during_resume(self):
        owner=str(uuid.uuid4());ctl=Coordinator(self.base,Adapter());ctl.enter(owner,0);path=self.base/'coordination/barrier.sqlite';path.unlink()
        with self.assertRaises(sqlite3.OperationalError):ctl.resume(owner)
        self.assertFalse(path.exists());self.assertEqual(c.phase(self.base)['phase'],'failed')
    def test_service_startup_gate_parks_capture_and_only_allows_access_during_resume(self):
        state=c.phase(self.base)
        for name in ('quiescing','fenced','quiescent_pending','failed'):
            c.atomic(self.base/'maintenance.json',state|{'phase':name})
            for role in ('php','web','live','devices','detector','scheduler'):self.assertFalse(allowed(self.base,role))
        c.atomic(self.base/'maintenance.json',state|{'phase':'resuming'})
        for role in ('php','web','live'):self.assertTrue(allowed(self.base,role))
        for role in ('devices','detector','scheduler'):self.assertFalse(allowed(self.base,role))
    def test_d4_configuration_requires_exact_owned_resuming_phase(self):
        import importlib.util
        sys.path.insert(0,str(ROOT/'deploy/network'))
        spec=importlib.util.spec_from_file_location('d4fixture',ROOT/'deploy/network/server.py');network=importlib.util.module_from_spec(spec);spec.loader.exec_module(network)
        env=self.base.parent/'.env';env.write_text('APP_KEY=fixture-unchanged')
        paths={'shared':self.base.parent,'env':env};owner=str(uuid.uuid4())
        with patch.object(network,'_configure',return_value='prepared') as prepare:
            with self.assertRaises(ValueError):network.configure(paths,{},prepare_only=True)
            self.assertFalse(prepare.called)
            c.atomic(self.base/'maintenance.json',c.phase(self.base)|{'phase':'resuming','operation':owner})
            with patch.dict(os.environ,{'PHILCST_LIFECYCLE_CONTROL':owner}):self.assertEqual(network.configure(paths,{},prepare_only=True),'prepared')
            self.assertEqual(env.read_text(),'APP_KEY=fixture-unchanged')
            c.atomic(self.base/'maintenance.json',c.phase(self.base)|{'phase':'quiescent'})
            with patch.object(network,'_enroll_public_root',side_effect=AssertionError('No trust mutation allowed')):
                with self.assertRaises(c.Paused):network.enroll_public_root(paths,{})
    def test_quiescent_pending_survives_restart_and_resume_preserves_work(self):
        operation=self.enqueue();owner=str(uuid.uuid4());adapter=Adapter();ctl=Coordinator(self.base,adapter)
        self.assertEqual(ctl.enter(owner,0)['phase'],'quiescent_pending');self.assertEqual(ctl.verify(owner)['outbox']['counts'],{'pending':1})
        self.assertFalse(allowed(self.base,'detector'));self.assertFalse(allowed(self.base,'php'))
        def prepare():
            self.assertEqual(c.phase(self.base)['phase'],'resuming');self.assertFalse(allowed(self.base,'devices'));self.assertTrue(allowed(self.base,'php'))
        ctl.resume(owner,prepare);self.assertTrue(allowed(self.base,'detector'));self.assertTrue(self.box.result(operation)['queued'])
        self.assertEqual(c.phase(self.base)['phase'],'running');self.assertIn('ready',adapter.calls)
    def test_active_submission_finishes_persistence_before_owned_producer_stop(self):
        from durable_delivery import capture_work
        started=threading.Event();finish=threading.Event();owner=str(uuid.uuid4());adapter=Adapter();ctl=Coordinator(self.base,adapter)
        captured_at=datetime.now(timezone.utc).isoformat()
        @capture_work
        def capture():
            started.set();finish.wait(3);return self.enqueue(event_time=captured_at)
        with patch.dict(os.environ,{'PHILCST_LIFECYCLE_ROOT':str(self.base)}),ThreadPoolExecutor(max_workers=1) as pool:
            future=pool.submit(capture);self.assertTrue(started.wait(2))
            with self.assertRaises(c.Paused):ctl.enter(owner,0,producer_wait_seconds=0)
            self.assertEqual(c.phase(self.base)['phase'],'quiescing');self.assertNotIn('quiesce',adapter.calls)
            finish.set();operation=future.result();self.assertEqual(len(operation),64)
            state=ctl.enter(owner,0);self.assertEqual(state['phase'],'quiescent_pending')
        self.assertTrue(self.box.result(operation)['queued'])
    def test_overlapping_and_wrong_owner_operations_are_refused(self):
        ctl=Coordinator(self.base,Adapter());owner=str(uuid.uuid4())
        with ctl.owned(owner):
            with self.assertRaises(ValueError):ctl.enter(str(uuid.uuid4()),0)
        ctl.enter(owner,0)
        with self.assertRaises(ValueError):ctl.enter(str(uuid.uuid4()),0)
        with self.assertRaises(ValueError):ctl.resume(str(uuid.uuid4()))
    def test_failed_quiescence_and_resume_do_not_silently_reopen(self):
        ctl=Coordinator(self.base,Adapter());owner=str(uuid.uuid4());ctl.adapter.fail='stop'
        with self.assertRaises(ValueError):ctl.enter(owner,0)
        self.assertEqual(c.phase(self.base)['phase'],'failed');self.assertFalse(allowed(self.base,'web'))
        ctl.adapter.fail='check'
        with self.assertRaises(ValueError):ctl.resume(owner)
        self.assertEqual(c.phase(self.base)['phase'],'failed')
        ctl.adapter.fail=None;ctl.resume(owner);self.assertEqual(c.phase(self.base)['phase'],'running')
    def test_php_inflight_writer_is_waited_for_before_quiescence(self):
        if not __import__('shutil').which('php'):self.skipTest('PHP unavailable')
        destination=self.base/'synthetic-app.sqlite'
        script='$b=new PDO("sqlite:".$argv[1]);$b->exec("BEGIN IMMEDIATE");$d=new PDO("sqlite:".$argv[2]);$d->exec("CREATE TABLE effects(id INTEGER)");$d->beginTransaction();$d->exec("INSERT INTO effects VALUES(1)");echo "writing\n";flush();usleep(850000);$d->commit();$b->exec("ROLLBACK");'
        process=subprocess.Popen(['php','-r',script,str(self.base/'coordination/barrier.sqlite'),str(destination)],stdout=subprocess.PIPE,text=True)
        try:
            self.assertEqual(process.stdout.readline().strip(),'writing');start=time.monotonic()
            owner=str(uuid.uuid4());Coordinator(self.base,Adapter()).enter(owner,0)
            self.assertGreater(time.monotonic()-start,.65)
            db=sqlite3.connect(destination)
            try:self.assertEqual(db.execute('SELECT COUNT(*) FROM effects').fetchone()[0],1)
            finally:db.close()
        finally:process.wait(timeout=5);process.stdout.close()


if __name__=='__main__':unittest.main()
