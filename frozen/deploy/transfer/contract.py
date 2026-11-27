"""D5D distinct source/target authority. No private keys or OS mutation on import."""
import base64
import datetime as dt
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import uuid
sys.path[:0]=[str(Path(__file__).resolve().parents[1]/'lifecycle'),str(Path(__file__).resolve().parents[1]/'updates'),str(Path(__file__).resolve().parents[1]/'network')]
from contracts import c,now,stamp
from backup_contract import require,canonical,sha,load,no_links,file_sha

PRODUCT='philcst-vms'

def digest(value):c.digest(value);return value

FIELDS={
 'challenge':['id','nonce','source_key','target_key','target_host','target_root','target_installation','target_binding','target_sql','actor'],
 'manifest':['transfer','challenge_sha256','challenge','source_host','source_root','source_key','source_identity','source_paths','source_base','target_key','target_host','target_root','source_binding','target_binding','source_sql','recovery_point','backup_manifest_sha256','archive_sha256','archive_bytes','receipt_sha256','retirement','delivery','decisions','approval'],
 'isolation':['transfer','manifest_sha256','method','evidence','actor','address_conflict_checked'],
 'activation':['transfer','manifest_sha256','owner','target_host','target_identity'],
 'rejection':['transfer','challenge_sha256','target_host','cancelled'],
}

def fresh(body):
    require(c.timestamp(body['issued_at'])<=now()<c.timestamp(body['expires_at']),'Transfer authority/challenge expired or clock incorrect')
    require(c.timestamp(body['expires_at'])-c.timestamp(body['issued_at'])<=dt.timedelta(days=8),'Excessive handoff lifetime')


def public(adapter,private):
    return helper(adapter,{'action':'public','private_path':str(private)})['public_key']


def certificate_public(adapter,certificate):
    # The issuer can be EC or RSA. Only transfer authority requires RSA.
    return helper(adapter,{'action':'certificate-public','certificate_path':str(certificate)})['public_key']


def helper(adapter,value):
    result=subprocess.run(adapter.php_verifier()+[str(Path(__file__).with_name('crypto.php'))],input=json.dumps(value),capture_output=True,text=True,timeout=20)
    require(result.returncode==0,'Installed transfer signing helper failed');return json.loads(result.stdout)


def sign(adapter,authority,body):
    require(body['product']==PRODUCT and body['schema'].startswith('philcst.transfer-'),'Wrong transfer signature purpose')
    return {'signed':body,'key_id':authority['key_id'],'algorithm':'RSA-PKCS1-SHA256',
            'signature':helper(adapter,{'action':'sign','private_path':str(authority['private']),'data':base64.b64encode(canonical(body)).decode()})['signature']}


def verify(adapter,envelope,peer,kind):
    c.fields(envelope,['signed','key_id','algorithm','signature']);body=envelope['signed']
    c.fields(body,['schema','product','issued_at','expires_at']+FIELDS[kind])
    require(body.get('schema')=='philcst.transfer-'+kind+'.v1' and body.get('product')==PRODUCT,'Wrong handoff signature purpose')
    for name in ('source_key','target_key','target_root','source_root','target_host','source_host','challenge_sha256','manifest_sha256','archive_sha256','receipt_sha256','backup_manifest_sha256'):
        if name in body:c.digest(body[name])
    uuid.UUID(body['id'] if kind=='challenge' else body['transfer'])
    if kind=='challenge':c.digest(body['nonce'])
    require(envelope['key_id']==peer['key_id'] and envelope['algorithm']=='RSA-PKCS1-SHA256','Unknown transfer authority; never trust incoming keys')
    payload={'public_key':peer['public_key'],'data':base64.b64encode(canonical(body)).decode(),'signature':envelope['signature']}
    script=Path(__file__).resolve().parents[1]/'lifecycle/signature.php'
    if not script.exists():script=Path(__file__).resolve().parents[2]/'build/updates/signature.php'
    result=subprocess.run(adapter.php_verifier()+[str(script)],input=json.dumps(payload),capture_output=True,text=True,timeout=20)
    require(result.returncode==0,'Transfer signature rejected');fresh(body);return body


def timestamped(kind,**values):
    return {'schema':'philcst.transfer-'+kind+'.v1','product':PRODUCT,'issued_at':stamp(now()),'expires_at':stamp(now()+dt.timedelta(days=7)),**values}


def compatible(source,target,actual_sql,policy):
    require(policy['schema']=='philcst.transfer-paths.v1' and policy['product']==PRODUCT,'Wrong transfer policy')
    require(all(source[k]==target[k] for k in ('version','source_sha256','commit','build_identity','schema_id')),'Transfer requires one reviewed application/schema; use separately certified D5C upgrade first')
    rule={'source':source,'target':target,'database_schema_sha256':actual_sql}
    require(rule in policy['paths'],'Exact source/target runtime/SQL pair has not been approved for transfer')
    return rule
