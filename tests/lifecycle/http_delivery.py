#!/usr/bin/env python3
"""Real helpers -> real isolated Laravel endpoints. No cameras, devices or OS mutations.
Run using the available packaged Python (requests) and a private source/DB/storage copy.
"""
import base64
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime,timezone
import hashlib
import json
import os
from pathlib import Path
import signal
import socket
import sqlite3
import subprocess
import sys
import time
import uuid
from unittest.mock import patch
import requests


def main():
    work=Path(sys.argv[1]).resolve();app=work/'test-app'
    import tempfile
    fixture=Path(tempfile.mkdtemp(prefix='http-d5a-',dir=work))
    repo=Path(__file__).resolve().parents[2]
    sys.path[:0]=[str(app/'deploy/lifecycle'),str(app/'school-vehicle-monitoring-detector')]
    import coordination as c
    import durable_delivery as d
    from maintenance import Coordinator
    from laravel_client import LaravelEventClient
    from devices.reader_link import TagPoster
    base=fixture/'lifecycle';c.initialize(base);d.Outbox(base,initialize=True)
    env=os.environ|json.loads((work/'test-env.json').read_text())|{
        'PHILCST_D5A_FIXTURE':'1','DB_DATABASE':str(fixture/'app.sqlite'),'CACHE_STORE':'database','SESSION_DRIVER':'array',
        'DETECTOR_API_KEY':'synthetic-d5a-key','MONITORING_SERVICES':'managed','PHILCST_LIFECYCLE_ROOT':str(base),
        'PHP_CLI_SERVER_WORKERS':'4','PHILCST_D5A_FIXTURE_PATH':str(fixture),
        'APP_CONFIG_CACHE':str(fixture/'config.php'),'APP_ROUTES_CACHE':str(fixture/'routes.php'),
        'DEVICE_FILES_PATH':str(fixture/'devices'),'CAMERA_FILES_PATH':str(fixture/'camera')}
    (fixture/'app.sqlite').touch();os.environ['PHILCST_LIFECYCLE_ROOT']=str(base)
    def inspect(mode='inspect',setup=False):
        chosen=dict(env)
        if setup:chosen.pop('PHILCST_LIFECYCLE_ROOT',None)
        result=subprocess.run(['php',str(app/'tests/fixtures/d5a-runtime.php'),mode],cwd=app,env=chosen,text=True,capture_output=True)
        (fixture/(mode+'.log')).write_text(result.stdout+result.stderr)
        if result.returncode:raise AssertionError(result.stdout+result.stderr)
        try:return json.loads(result.stdout.strip().splitlines()[-1])
        except Exception:raise AssertionError(result.stdout+result.stderr)
    inspect('seed',True);initial=inspect('marker')
    cache=subprocess.run(['php',str(app/'artisan'),'config:cache'],cwd=app,env=env,text=True,capture_output=True)
    assert cache.returncode==0,cache.stdout+cache.stderr
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    url='http://127.0.0.1:'+str(port)+'/api/v1/integration/'
    settings={'system_settings':{'event_ingest_url':url+'events','python_api_key':'synthetic-d5a-key'}}
    now=datetime.now(timezone.utc).isoformat()
    payload={'external_event_key':'d5a-real-helper-pass','camera_role':'gate-1','camera_id':initial['camera_id'],'event_time':now,
        'direction':'IN','detected_vehicle_type':'Car','detection_metadata':{'rfid_status':'matched'}}
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII=')
    # Disable only the worker thread in this explicit fixture; actual persistence/helpers run.
    # Transport below uses requests against the real application, including the lost-ACK case.
    with patch.object(d,'start_worker',lambda *a,**k:None):
        client=LaravelEventClient(settings);poster=TagPoster(lambda message:None)
        poster.configure({'rfid_ingest_url':url+'rfid-scans','api_key':'synthetic-d5a-key'})
        rfid=poster.post({'tag_uid':'000012345678ABCD','scan_location':'gate-1','scan_time':now,'reader_name':'Original fixture reader',
            'payload_json':{'reader_mac':'AA:BB:CC:DD:EE:01','reader_ip':'192.168.44.9','rssi':-40}})
        crossing=client.submit_crossing(payload,png,'original-scene.png')
        plate=client.submit_visitor_plate(payload|{'plate_status':'read','plate_number':'395ISX','plate_confidence':.99},png,'original-plate.png')
    box=d.configured();assert rfid['durably_queued'] and crossing['durably_queued'] and plate['durably_queued']
    with box.db() as db:
        packets={row['id']:{'envelope':row['envelope'],'sha256':row['sha'],'media':json.loads(row['media'])} for row in db.execute('SELECT * FROM operations')}
    endpoint=url+'deliveries';headers={'X-Api-Key':'synthetic-d5a-key','Accept':'application/json'}
    assert not box.deliver_one(endpoint,'synthetic-d5a-key')['accepted']
    router=fixture/'router.php'
    router.write_text('''<?php
require '''+repr(str(app/'vendor/autoload.php'))+''';
$app=require '''+repr(str(app/'bootstrap/app.php'))+''';
$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);$kernel->bootstrap();
Illuminate\\Support\\Facades\\DB::connection()->getPdo()->sqliteCreateFunction('d5_pause',function(){
 $p=getenv('PHILCST_D5A_FIXTURE_PATH');if(is_file($p.'/pause-enabled')){file_put_contents($p.'/writer-entered','in-flight');usleep(900000);}return 1;
});
$request=Illuminate\\Http\\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
''')
    log=(fixture/'server.log').open('w')
    server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),str(router)],cwd=app,env=env,stdout=log,stderr=subprocess.STDOUT,start_new_session=True)
    results={}
    try:
        for _ in range(60):
            try:
                if requests.get('http://127.0.0.1:'+str(port)+'/up',timeout=1).status_code==200:break
            except requests.RequestException:pass
            time.sleep(.1)
        else:raise AssertionError('Private fixture server unavailable; inspect server.log')
        def lose_ack(address,**kwargs):
            response=requests.post(address,**kwargs);assert response.status_code==200,response.text
            assert response.json()['delivery_ack']['committed'] is True
            raise requests.Timeout('Synthetic response lost after verified server commit')
        assert not box.deliver_one(endpoint,'synthetic-d5a-key',lose_ack,time.time()+100)['accepted']
        assert inspect()['receipts']==1
        assert box.deliver_one(endpoint,'synthetic-d5a-key',now=time.time()+200)['accepted']
        assert inspect()['receipts']==1
        for operation in [crossing['operation'],plate['operation']]:
            response=box.deliver_one(endpoint,'synthetic-d5a-key',now=time.time()+200)
            assert response['accepted'],response;assert response['operation']==operation
        after=inspect();assert after['crossings']==1 and after['receipts']==3 and after['events']==1 and after['scans']==1,after
        assert after['first_snapshot_sha256']==hashlib.sha256(png).hexdigest()
        assert after['state']=='INSIDE' and after['entries']==1,after
        packet=packets[crossing['operation']]
        with ThreadPoolExecutor(max_workers=6) as pool:
            responses=list(pool.map(lambda _:requests.post(endpoint,json=packet,headers=headers,timeout=15),range(6)))
        assert all(r.status_code==200 and r.json()['delivery_ack']['committed'] for r in responses)
        assert inspect()==after
        media=app/'storage/app/private/occurrences'/Path(after['first_crossing']['snapshot_path']).name
        # The configured private occurrence disk path is inspected through stored references.
        results['real_detector_and_tagposter_committed_lost_ack_concurrent_retry']={'receipts':after['receipts'],'crossings':after['crossings'],'events':after['events'],'scans':after['scans']}
        results['original_context_and_time_retained']=after['first_crossing']['camera_id']==payload['camera_id'] and after['first_crossing']['external_event_key']==payload['external_event_key']
        # Pause the real request inside the canonical app transaction before receipt insert.
        inspect('trigger');(fixture/'pause-enabled').touch()
        with patch.object(d,'start_worker',lambda *a,**k:None):racing=client.submit_crossing(payload|{'external_event_key':'second-physical-pass','detection_metadata':{'rfid_status':'no_pass'}},png)
        class Adapter:
            def snapshot(self):return {'fixture':True}
            def quiesce(self,r):pass
            def drain(self,s):pass
            def stop(self,r):pass
            def verify(self,r):pass
            def check(self):inspect('bind')
            def start_access(self,r):pass
            def ready(self):pass
            def reopen(self,r):pass
        with ThreadPoolExecutor(max_workers=1) as pool:
            future=pool.submit(box.deliver_one,endpoint,'synthetic-d5a-key',None,time.time()+200)
            for _ in range(60):
                if (fixture/'writer-entered').exists():break
                time.sleep(.02)
            else:raise AssertionError('Real in-flight request was not observed')
            owner=str(uuid.uuid4());ctl=Coordinator(base,Adapter());start=time.monotonic();state=ctl.enter(owner,0)
            assert time.monotonic()-start>.5;assert state['phase'] in ('quiescent','quiescent_pending')
            try:future.result()
            except c.Paused:pass  # Ack bookkeeping is retained when the fence wins this race.
        assert requests.post(endpoint,json=packet,headers=headers,timeout=5).status_code==503
        (fixture/'pause-enabled').unlink();ctl.resume(owner)
        if not box.result(racing['operation'])['accepted']:assert box.deliver_one(endpoint,'synthetic-d5a-key',now=time.time()+400)['accepted']
        assert inspect()['crossings']==2
        results['real_http_transaction_maintenance_race_and_original_retry']=True
        # The archived/reassigned fixture must reject new evidence instead of changing camera.
        with patch.object(d,'start_worker',lambda *a,**k:None):pending=client.submit_crossing(payload|{'external_event_key':'pending-old-camera'},png)
        inspect('reassign');failure=box.deliver_one(endpoint,'synthetic-d5a-key',now=time.time()+500)
        assert failure['operation']==pending['operation'] and not failure['accepted']
        with box.db() as db:assert db.execute('SELECT state FROM operations WHERE id=?',(pending['operation'],)).fetchone()[0]=='dead'
        assert requests.post(endpoint,json=packet,headers=headers,timeout=5).status_code==200
        assert inspect()['crossings']==2
        results['pending_original_camera_conflict_retained_and_accepted_history_not_replayed']=True
        before={p:p.read_bytes() for p in [base/'identity.json',base/'maintenance.json']}
        assert requests.post(endpoint,json=packet,headers={'X-Api-Key':'wrong'},timeout=5).status_code==401
        changed=json.loads(packet['envelope']);changed['generation']=str(uuid.uuid4());raw=d.canonical(changed)
        bad={'envelope':raw,'sha256':hashlib.sha256(raw.encode()).hexdigest(),'media':packet['media']}
        assert requests.post(endpoint,json=bad,headers=headers,timeout=5).status_code==409
        assert before=={p:p.read_bytes() for p in before};assert inspect()['crossings']==2
        results['auth_and_other_generation_refused_without_active_state_mutation']=True
        results['limits']='Four private PHP workers/high loopback port, synthetic records/media and service adapters. No native services, trust stores, devices or real reboot/power-loss testing.'
        (fixture/'results.json').write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))
    finally:
        os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);log.close()


if __name__=='__main__':main()
