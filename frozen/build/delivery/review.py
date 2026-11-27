#!/usr/bin/env python3
"""Local-only delivery inventory and hash-bound acceptance ledger. Never publishes."""
import argparse,hashlib,importlib.util,json,sys,zipfile
from pathlib import Path
_spec=importlib.util.spec_from_file_location('philcst_build_update_contract',Path(__file__).resolve().parents[1]/'updates/contract.py')
c=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(c)
SERVERS=('windows-x64','macos-x86_64','macos-arm64')
CLIENTS=('client-Windows','client-Mac')
NATIVE=('clean_offline_install','personal_setup_login','browser_closed_monitoring','reboot_logged_out','canonical_browser_trust','two_lan_clients_and_isolation','physical_camera_ocr_rfid','registration_review_reports_images','durable_delivery_retries','backup_restore_fault_recovery','same_source_reentry','data_preserving_uninstall','exact_cross_version_update_rollback','two_host_transfer_retirement','disk_permissions_locked_files','loaded_video_revocation')
STATUSES={'PASS','FAIL','NOT RUN','BLOCKED'}

def dump(path,value):Path(path).write_text(json.dumps(value,indent=2)+'\n')

def inventory(files):
    artifacts=[];seen=set();common=None
    for path in sorted(map(Path,files)):
        if path.name.endswith('.update.json'):
            envelope=c.load(path);b=c.validate_manifest(envelope['signed']);p=path.parent/b['package']['filename'];c.safe_path(p.name)
            c.require(p.is_file() and p.stat().st_size==b['package']['size'] and c.sha_file(p)==b['package']['sha256'],'Missing/truncated/mismatched complete archive')
            proof=c.inspect_archive(p,b,32*1024**3)
            platform=b['platform']['id'];identity={k:b[k] for k in ('version','channel','source')}
            extra={'content_files_verified':proof['files_verified'],'minimum_os':b['platform']['minimum_os'],'runtime_id':b['compatibility']['runtime']['target'],'schema_id':b['compatibility']['schema']['target'],'signatures':len(envelope['signatures']),'metadata':str(path)}
        elif path.suffix=='.zip':
            with zipfile.ZipFile(path) as z:
                names=z.namelist();prefix='Connect-to-PHILCST/';c.require(prefix+'HELPER-INFO.json' in names,'Client helper identity missing')
                info=c.parse(z.read(prefix+'HELPER-INFO.json'));manifest=c.parse(z.read(prefix+'CONTENT-MANIFEST.json'))
                expected={prefix+n for n in manifest}|{prefix+'HELPER-INFO.json',prefix+'CONTENT-MANIFEST.json'}
                c.require(set(names)==expected and len(names)==len(expected),'Unexpected/duplicate client payload')
                for n,e in manifest.items():
                    c.safe_path(n);c.require(Path(n).suffix.lower() not in ('.key','.pem','.sqlite','.exe','.db') and not Path(n).name.startswith('.env'),'Server/private material in client helper')
                    raw=z.read(prefix+n);c.require(len(raw)==e['bytes'] and hashlib.sha256(raw).hexdigest()==e['sha256'],'Corrupt client helper')
            p=path;platform='client-'+info['platform'];identity={k:info[k] for k in ('version','channel','source')};extra={'publisher_authenticated':False,'server_runtime_included':False}
        else:raise ValueError('Review expects update sidecars and client-only ZIPs; source archives are not complete installers')
        c.require(platform in SERVERS+CLIENTS and platform not in seen,'Unknown/duplicate delivery platform');seen.add(platform)
        c.require(common is None or common==identity,'Mixed source/version/channel/build identity');common=identity
        artifacts.append({'platform':platform,'file':str(p),'filename':p.name,'bytes':p.stat().st_size,'sha256':c.sha_file(p),'identity':identity,**extra})
    c.require(artifacts,'No actual deliverable artifacts supplied')
    return {'schema':'philcst.delivery-review.v1','common':common,'artifacts':artifacts,'missing':sorted(set(SERVERS+CLIENTS)-seen),'complete_matrix':set(SERVERS+CLIENTS)==seen,'publication':'not performed','production_release':'BLOCKED: reviewed clean source, signing, compatibility and native acceptance required'}

def acceptance(value):
    by={a['platform']:a for a in value['artifacts']};rows=[]
    for platform in SERVERS:
        artifact=by.get(platform)
        for test in NATIVE:
            rows.append({'platform':platform,'architecture':{'windows-x64':'x64','macos-x86_64':'x86_64','macos-arm64':'arm64'}[platform],
                         'test':test,'status':'NOT RUN' if artifact else 'BLOCKED','artifact_sha256':artifact['sha256'] if artifact else None,
                         'source':value['common'],'os':None,'scope':'native installed acceptance','physical_hardware':None,
                         'command':None,'evidence':None,'reason':'Requires native disposable target and explicit acceptance' if artifact else 'Complete current artifact unavailable'})
    for platform in CLIENTS:
        artifact=by.get(platform)
        for test in ('native_client_enrollment','native_reconnection','owned_client_removal','two_browser_operations'):
            rows.append({'platform':platform,'architecture':'browser client','test':test,'status':'NOT RUN' if artifact else 'BLOCKED','artifact_sha256':artifact['sha256'] if artifact else None,'source':value['common'],'os':None,'scope':'native client acceptance','physical_hardware':None,'command':'deploy/clients/CLIENT-CONNECTION.md','evidence':None,'reason':'Native OS trust/mapping/client acceptance required'})
    return {'schema':'philcst.acceptance.v1','artifacts':value['artifacts'],'results':rows}

def record(ledger,platform,test,status,evidence,command,os_version,scope,physical=False):
    c.require(test not in NATIVE or scope=='native installed acceptance','A fixture/runtime result cannot replace installed native acceptance')
    c.require(test not in ('physical_camera_ocr_rfid','loaded_video_revocation') or physical is True,'Physical hardware evidence required for this native case')
    c.require(status in STATUSES and evidence and command and os_version and scope,'Status/evidence/command/OS/scope required')
    artifact=next((a for a in ledger['artifacts'] if a['platform']==platform),None);c.require(artifact is not None,'No concrete artifact to bind acceptance')
    c.require(c.sha_file(artifact['file'])==artifact['sha256'],'Artifact changed after ledger creation; invalidate affected acceptance')
    c.require(Path(evidence).is_file(),'Evidence file does not exist')
    row={'platform':platform,'architecture':platform.removeprefix('macos-').removeprefix('windows-'),'test':test,'status':status,'artifact_sha256':artifact['sha256'],'source':artifact['identity'],'os':os_version,'scope':scope,'physical_hardware':physical,'command':command,'evidence':str(evidence),'evidence_sha256':c.sha_file(evidence)}
    ledger['results']=[r for r in ledger['results'] if (r['platform'],r['test'])!=(platform,test)]+[row];return ledger

def main():
    p=argparse.ArgumentParser();p.add_argument('--inputs',type=Path,nargs='+',required=True);p.add_argument('--out',type=Path,required=True);p.add_argument('--require-complete',action='store_true');a=p.parse_args()
    a.out.mkdir(parents=True,exist_ok=True);value=inventory(a.inputs);dump(a.out/'LOCAL-UPLOAD-INVENTORY.json',value);dump(a.out/'ACCEPTANCE.json',acceptance(value))
    text='# Draft release notes — NON-RELEASE\n\nVersion: '+value['common']['version']+'\n\nNo publication or production approval. Packages include local HTTPS, durable delivery, coherent recovery, authenticated update/transfer controllers and passive browser clients. Missing publisher trust keeps updates disabled; disaster activation remains blocked.\n\n'
    text+='Actual local artifacts:\n\n'+''.join('- '+x['filename']+' — SHA-256 `'+x['sha256']+'`\n' for x in value['artifacts'])
    text+='\nMissing platforms: '+(', '.join(value['missing']) or 'none')+'. Native installation, signing, exact compatibility and hardware gates remain.\n'
    (a.out/'DRAFT-RELEASE-NOTES.md').write_text(text)
    print(json.dumps({'complete_matrix':value['complete_matrix'],'missing':value['missing'],'publication':'not performed'}))
    if a.require_complete and not value['complete_matrix']:raise SystemExit(2)

if __name__=='__main__':main()
