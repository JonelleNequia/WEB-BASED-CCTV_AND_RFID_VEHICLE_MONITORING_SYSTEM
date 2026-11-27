#!/usr/bin/env python3
"""Explicit installed administrator interface. Incoming packages never supply this verifier."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import sys
import time
import uuid
sys.path.insert(0,str(Path(__file__).resolve().parent))
from contracts import c,load,require,now,canonical,sha
from native import installed
from manager import Manager
from install import UpdateRecovery
from coordination import phase,atomic
from backup_contract import database,no_links


def actor(adapter,request):
    require(request.get('schema')==1 and isinstance(request.get('inputs'),dict),'Malformed update request')
    uuid.UUID(request['id']);require(dt.datetime.fromisoformat(request['created_at']).astimezone(dt.timezone.utc) <= now() < dt.datetime.fromisoformat(request['created_at']).astimezone(dt.timezone.utc)+dt.timedelta(hours=24),'Expired/future administrator request')
    with database(adapter.p['targets']['database']) as db:
        user=db.execute('SELECT role,account_status,session_version FROM users WHERE id=?',(request['actor'],)).fetchone()
        require(user and user['role']=='admin' and user['account_status']=='active' and user['session_version']==request['session_version'],'Administrator account/session no longer authorizes the request')


def requests(manager):
    adapter=manager.adapter;inbox=adapter.p['targets']['storage']/'app/update-inbox'
    if not inbox.exists():return []
    no_links(inbox);results=[]
    for directory in [p for p in sorted(inbox.iterdir()) if not (p/'processed.json').exists()][:20]:
        no_links(directory)
        if not directory.is_dir() or (directory/'processed.json').exists():continue
        try:
            uuid.UUID(directory.name);no_links(directory/'request.json');request=load(directory/'request.json')
            require(request['id']==directory.name,'Request path mismatch');actor(adapter,request);data=request['inputs'];action=request['action']
            require(action in ('check','download','preferences','import','upload','plan','install'),'Unknown privileged intent')
            if action=='upload':continue
            if action=='check':result=manager.check()
            elif action=='download':result=manager.download(data['candidate'])
            elif action=='preferences':
                require(set(data)<= {'channel','automatic_check','automatic_download','maintenance_confirmed','candidate'},'Unexpected preferences')
                require(all(data.get(k,False) in (True,False,0,1,'0','1') for k in ('automatic_check','automatic_download')),'Invalid preparation preferences')
                manager.preferences(data.get('automatic_check',False) in (True,1,'1'),data.get('automatic_download',False) in (True,1,'1'),data.get('channel','stable'));result={'outcome':'preferences saved'}
            elif action=='import':
                require((directory/'package.bin').stat().st_size==data['size'],'Upload is incomplete')
                result=manager.import_local(directory/'manifest.json',directory/'package.bin')
            else:
                if action=='install': require(data.get('maintenance_confirmed') in (True,1,'1','on','yes'),'Explicit maintenance confirmation required')
                plan=UpdateRecovery(adapter,manager).plan(data['candidate'])
                require(action=='plan' or data.get('plan_confirmation')==plan['confirmation'],'Installation decision changed; request and approve a new plan')
                with manager.store.owned():
                    value=manager.store.read()
                    require(value['candidate'] and value['candidate']['id']==data['candidate'],'Candidate changed')
                    value['candidate'].update(install_ready=True,plan_confirmation=plan['confirmation'],requirements={k:plan[k] for k in ('required_disk_bytes','downtime','recovery')})
                    value.setdefault('approvals',{})[request['id']]={'request':request,'request_sha256':sha(canonical(request)),'plan':plan}
                    value['installation_request']={'id':request['id'],'candidate':data['candidate'],'confirmation':plan['confirmation']};manager.store.write(value)
                result={'outcome':'awaiting exact local OS administrator confirmation','request':request['id']}
            atomic(directory/'processed.json',{'schema':1,'outcome':'prepared','request':request['id']});results.append(result)
        except Exception:
            # Retain all incoming bytes; never execute or report arbitrary request text.
            result={'request':directory.name,'outcome':'request invalid or preparation unavailable'}
            try:
                uuid.UUID(directory.name)
                atomic(directory/'processed.json',{'schema':1,'outcome':result['outcome']})
                with manager.store.owned():
                    value=manager.store.read();value['result']=result
                    if value.get('candidate'):value['candidate']['install_ready']=False
                    manager.store.write(value)
            except Exception:pass
            results.append(result)
    return results


def main():
    p=argparse.ArgumentParser(description='PHILCST authenticated online/offline update control; installed administrator only')
    p.add_argument('action',choices=['bootstrap-controller','bootstrap-trust','status','check','download','import','preferences','tick','plan','install','recover','accept','resume','rotate','cleanup','recover-owner','enable-worker','repair-mirrors'])
    p.add_argument('--root',type=Path,required=True);p.add_argument('--candidate');p.add_argument('--manifest',type=Path);p.add_argument('--package',type=Path)
    p.add_argument('--trust',type=Path);p.add_argument('--trust-sha256');p.add_argument('--confirm-source');p.add_argument('--confirmation');p.add_argument('--maintenance-confirmed',action='store_true')
    p.add_argument('--request');p.add_argument('--operation');p.add_argument('--decision',choices=['current','forward','rollback']);p.add_argument('--channel',choices=['stable','prerelease','development'],default='stable')
    p.add_argument('--automatic-check',action='store_true');p.add_argument('--automatic-download',action='store_true')
    args=p.parse_args();adapter=installed(args.root);manager=Manager(adapter);update=UpdateRecovery(adapter,manager)
    if args.action=='enable-worker':result=adapter.install_worker()
    elif args.action=='repair-mirrors':
        with manager.store.owned():manager.store.write(manager.store.read());result={'authoritative_mirrors_repaired':True}
    elif args.action=='bootstrap-controller':result=adapter.bootstrap_controller(args.confirm_source)
    elif args.action=='bootstrap-trust':result=manager.store.bootstrap(c.load(args.trust),args.trust_sha256,args.confirm_source)
    elif args.action=='status':result=load(manager.store.public) if manager.store.public.exists() else {'provisioned':False}
    elif args.action=='check':result=manager.check()
    elif args.action=='download':result=manager.download(args.candidate)
    elif args.action=='import':result=manager.import_local(args.manifest,args.package)
    elif args.action=='preferences':manager.preferences(args.automatic_check,args.automatic_download,args.channel);result={'preferences_saved':True}
    elif args.action=='tick':
        if phase(adapter.p['base'])['phase']!='running':result={'outcome':'maintenance; preparation paused'}
        else:
            with __import__('coordination').producer_work('update worker',base=adapter.p['base']):
                result={'requests':requests(manager),'check':manager.check(scheduled=True)}
    elif args.action=='plan':result=update.plan(args.candidate)
    elif args.action=='install':
        candidate=args.candidate
        if args.request:
            uuid.UUID(args.request);approval=manager.store.read().get('approvals',{}).get(args.request);require(approval,'Unknown native-confirmation request');actor(adapter,approval['request'])
            require(approval['request']['action']=='install','A preparation plan alone is not browser installation authorization')
            require(args.confirmation==approval['plan']['confirmation'],'Candidate/maintenance confirmation differs from approved request');candidate=approval['request']['inputs']['candidate']
        with manager.store.owned(maintenance=True):result=update.install(candidate,args.confirmation,args.maintenance_confirmed)
    elif args.action=='recover':
        adapter.require_capsule()
        with manager.store.owned(maintenance=True):result=update.recover(args.operation,args.decision)
    elif args.action=='accept':
        adapter.require_capsule()
        with manager.store.owned(maintenance=True):result=update.accept(args.operation)
    elif args.action=='resume':
        adapter.require_capsule()
        with manager.store.owned(maintenance=True):result=update.resume(args.operation)
    elif args.action=='rotate':result=manager.store.rotate(c.load(args.manifest),args.confirmation)
    elif args.action=='cleanup':result=manager.cleanup(args.candidate)
    else:
        adapter.require_capsule();path=adapter.p['base']/'update-operation.lock';no_links(path)
        pid=int(path.read_text());require(not __import__('maintenance').process_alive(pid),'Updater owner may still be running; lock not stolen');path.unlink();result={'dead_update_owner_reviewed':pid,'writers_reopened':False}
    print(json.dumps(result,indent=2))

if __name__=='__main__':
    try:main()
    except Exception:
        print('Update refused/unavailable. Retain current data and maintenance state; inspect protected status and the update recovery guide.',file=sys.stderr);sys.exit(1)
