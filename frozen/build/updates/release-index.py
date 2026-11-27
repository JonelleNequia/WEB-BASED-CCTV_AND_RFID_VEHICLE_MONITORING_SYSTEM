#!/usr/bin/env python3
"""Manual build-only signed discovery index. Publication remains a separate action."""
import argparse
import json
from pathlib import Path
import datetime as dt
from contract import load,canonical,sha_bytes,validate_manifest,require
from contract import fingerprint


def create(envelopes,sequence,trust_generation,notes='',clock=None):
    clock=clock or dt.datetime.now(dt.timezone.utc);bodies=[validate_manifest(x['signed']) for x in envelopes]
    require(bodies and type(sequence) is int and sequence>0 and type(trust_generation) is int and trust_generation>0,'Explicit positive index/trust generations required')
    require(len({b['channel'] for b in bodies})==1,'Index channels cannot be mixed')
    for version in {b['version'] for b in bodies}:
        group=[b for b in bodies if b['version']==version]
        require(len({b['platform']['id'] for b in group})==len(group),'Duplicate platform in release index')
        require(len({canonical(b['source']) for b in group})==1,'A release version must share one source/build identity across platforms')
    return {'signed':{'schema':'philcst.release-index.v1','product':'philcst-vms','repository':'JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM',
        'channel':bodies[0]['channel'],'sequence':sequence,'trust_generation':trust_generation,'issued_at':clock.strftime('%Y-%m-%dT%H:%M:%SZ'),
        'expires_at':(clock+dt.timedelta(days=30)).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'releases':[{'tag':'v'+b['version'],'manifest':b['package']['filename']+'.update.json','manifest_sha256':sha_bytes(canonical(e)),'notes':notes} for e,b in zip(envelopes,bodies)]},'signatures':[]}

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--manifest',type=Path,nargs='+',required=True);p.add_argument('--sequence',type=int,required=True);p.add_argument('--trust-generation',type=int,required=True);p.add_argument('--notes',type=Path);p.add_argument('--sign-key',type=Path,required=True);p.add_argument('--public-key',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
    require(not a.output.exists(),'Choose a new metadata output');value=create([load(x) for x in a.manifest],a.sequence,a.trust_generation,a.notes.read_text() if a.notes else '')
    # Generic signed envelope uses the same canonical JSON/RSA contract. Verify manually
    # through the installed index verifier; it is not an update-manifest body itself.
    import subprocess,base64
    from contract import fingerprint
    key=a.sign_key.resolve();require(not key.is_relative_to(Path(__file__).resolve().parents[2]),'Publisher private key must remain outside repository')
    signature=subprocess.check_output(['openssl','dgst','-sha256','-sign',str(key)],input=canonical(value['signed']))
    value['signatures']=[{'algorithm':'RSA-PKCS1-SHA256','key_id':fingerprint(a.public_key.read_text()),'value':base64.b64encode(signature).decode()}]
    public_der=subprocess.check_output(['openssl','pkey','-in',str(key),'-pubout','-outform','DER'])
    require(sha_bytes(public_der)==fingerprint(a.public_key.read_text()),'Signing key does not match selected public verifier')
    a.output.write_text(json.dumps(value,indent=2)+'\n');print('Prepared signed discovery metadata; no publication performed')
