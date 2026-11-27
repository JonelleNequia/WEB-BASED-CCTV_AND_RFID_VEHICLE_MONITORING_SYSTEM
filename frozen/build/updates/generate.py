#!/usr/bin/env python3
"""Build-only update sidecars for immutable complete archives; never writes inside payloads."""
import argparse
import base64
import datetime as dt
import json
from pathlib import Path
import subprocess
import sys
from contract import (PRODUCT,SCHEMA,PLATFORMS,Version,Rejected,canonical,sha_bytes,sha_file,
    load,parse,fields,digest,require,validate_manifest,inspect_archive,fingerprint,compatibility_ids)


def create(stage,archive,policy=None,channel=None,now=None):
    stage,archive=Path(stage),Path(archive);info=load(stage/'BUILD-INFO.json')
    platform='windows-x64' if info['architecture']=='win_amd64' else 'macos-'+info['architecture'];require(platform in PLATFORMS,'Unsupported package platform')
    # Product code's payload identity, not the later sidecar generator's source revision.
    require(info['product']=='PHILCST Vehicle Monitoring','Wrong package product')
    source={'commit':info['commit'],'sha256':info['source']['source_sha256'],'build_identity':info.get('build_identity',info['version'])}
    channel=channel or ('development' if info['channel']=='development' else ('prerelease' if Version(info['version']).pre else 'stable'))
    policy=policy or load(Path(__file__).with_name('compatibility.json'));require(policy['schema']=='philcst.update-build-policy.v1' and policy['product']==PRODUCT,'Wrong build compatibility policy')
    approved=policy['platforms'][platform]
    schema_id,runtime_id=compatibility_ids(info,platform)
    now=(now or dt.datetime.now(dt.timezone.utc)).replace(microsecond=0);stamp=lambda t:t.strftime('%Y-%m-%dT%H:%M:%SZ')
    body={'schema':SCHEMA,'product':PRODUCT,'version':info['version'],'channel':channel,'source':source,
        'platform':{'id':platform,'os':PLATFORMS[platform][0],'architecture':PLATFORMS[platform][1],'minimum_os':info.get('minimum_macos','10.0.0')},
        'compatibility':{'supported_upgrade_origins':approved['supported_upgrade_origins'],
            'schema':{'accepted':approved['schema_accepted'],'target':schema_id},
            'runtime':{'accepted':approved['runtime_accepted'],'target':runtime_id},'installation_phase':'D5'},
        'package':{'filename':archive.name,'format':'zip' if platform=='windows-x64' else 'tar.gz','size':archive.stat().st_size,'sha256':sha_file(archive),'content_manifest_sha256':sha_file(stage/'CONTENT-MANIFEST.json')},
        'issued_at':stamp(now),'expires_at':stamp(now+dt.timedelta(days=30))}
    validate_manifest(body)
    # Validate actual archive without extracting, not just the stage or supplied hash.
    inspect_archive(archive,body,32*1024**3)
    return {'signed':body,'signatures':[]}


def sign(envelope,keyfile,publicfile):
    keyfile=Path(keyfile).resolve();repo=Path(__file__).resolve().parents[2]
    require(not keyfile.is_relative_to(repo),'Private signing keys must remain outside the source checkout')
    public=Path(publicfile).read_text();keyid=fingerprint(public)
    result=subprocess.run(['openssl','dgst','-sha256','-sign',str(keyfile)],input=canonical(envelope['signed']),capture_output=True)
    require(result.returncode==0,'Signing failed; no private key data is logged')
    envelope['signatures']=[{'algorithm':'RSA-PKCS1-SHA256','key_id':keyid,'value':base64.b64encode(result.stdout).decode()}]
    # Verify the configured public key matches the private signer before emitting metadata.
    from contract import authenticate
    body=envelope['signed'];trust={'schema':'philcst.update-trust.v1','product':PRODUCT,'environment':'production','generation':1,'keys':{keyid:{'public_key':public,'channels':[body['channel']],'platforms':[body['platform']['id']],'roles':['update'],'not_before':body['issued_at'],'not_after':body['expires_at'],'revoked':False}}}
    authenticate(envelope,trust,['php','-n'],channel=body['channel'],platform=body['platform']['id'])
    return envelope


def matrix(envelopes):
    require(len(envelopes)==3,'Release matrix requires exactly three platform metadata files')
    bodies=[validate_manifest(x['signed']) for x in envelopes];platforms=[b['platform']['id'] for b in bodies];require(set(platforms)==set(PLATFORMS),'Missing/duplicate release platform')
    common={'product':bodies[0]['product'],'version':bodies[0]['version'],'channel':bodies[0]['channel'],'source':bodies[0]['source']}
    require(all(all(b[k]==v for k,v in common.items()) for b in bodies),'Mixed version/source/build/channel release matrix rejected')
    return {'schema':'philcst.update-matrix.v1',**common,'platforms':{b['platform']['id']:{'manifest_sha256':sha_bytes(canonical(e)),'package':b['package']} for b,e in zip(bodies,envelopes)},'publication':'not performed','trusted_discovery_index':False}


def main():
    p=argparse.ArgumentParser();p.add_argument('--stage',type=Path);p.add_argument('--package',type=Path);p.add_argument('--output',type=Path,required=True);p.add_argument('--policy',type=Path);p.add_argument('--channel',choices=['stable','prerelease','development']);p.add_argument('--sign-key',type=Path);p.add_argument('--public-key',type=Path);p.add_argument('--matrix',type=Path,nargs='+');a=p.parse_args()
    if a.matrix:value=matrix([load(x) for x in a.matrix])
    else:
        require(a.stage is not None and a.package is not None,'Provide stage and complete archive')
        require(not a.output.resolve().is_relative_to(a.stage.resolve()),'Update metadata is a sidecar, not part of the existing payload/content manifest')
        value=create(a.stage,a.package,load(a.policy) if a.policy else None,a.channel)
        if a.sign_key:
            require(a.public_key is not None,'Explicit public signing material required');value=sign(value,a.sign_key,a.public_key)
    require(not a.output.exists(),'Refusing to overwrite metadata; choose a new output')
    a.output.parent.mkdir(parents=True,exist_ok=True);a.output.write_text(json.dumps(value,indent=2,ensure_ascii=True)+'\n');print('Prepared update metadata:',a.output,'(no publication/installation)')

if __name__=='__main__':
    try:main()
    except (Rejected,OSError,KeyError,TypeError,ValueError) as error:print('Update metadata refused: '+str(error),file=sys.stderr);sys.exit(1)
