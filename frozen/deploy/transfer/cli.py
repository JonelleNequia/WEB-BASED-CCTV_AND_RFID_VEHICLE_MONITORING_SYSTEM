#!/usr/bin/env python3
"""Explicit elevated installed-only D5D interface. No network discovery/failover."""
import argparse
import json
from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parent))
from native import installed
from control import Transfer
from contract import load,require,public,c
from maintenance import process_alive


def main():
    p=argparse.ArgumentParser(description='Authenticated server transfer; physically isolate old owner before activation')
    p.add_argument('action',choices=['bootstrap','enroll','status','challenge','plan','prepare','recover-export','recover-import','export','inspect','import','promote','verify','isolation','activate','acknowledge','reject','cancel-source','disaster-inspect','recover-owner','receipt'])
    p.add_argument('--root',type=Path,required=True);p.add_argument('--private-key',type=Path);p.add_argument('--fingerprint');p.add_argument('--confirm-source');p.add_argument('--peer',type=Path)
    p.add_argument('--source-key');p.add_argument('--challenge',type=Path);p.add_argument('--decisions',type=Path);p.add_argument('--actor');p.add_argument('--confirmation');p.add_argument('--archive',type=Path);p.add_argument('--output',type=Path)
    p.add_argument('--custody');p.add_argument('--receipt',type=Path);p.add_argument('--transfer');p.add_argument('--method');p.add_argument('--evidence');p.add_argument('--address-conflict-checked',action='store_true')
    a=p.parse_args();adapter=installed(a.root);transfer=Transfer(adapter)
    if a.action in ('challenge','plan','prepare','isolation'):require(a.actor and 3<=len(a.actor)<=160,'Named local administrator authorization required')
    if a.action=='bootstrap':result=transfer.bootstrap(a.private_key,a.fingerprint,a.confirm_source)
    elif a.action=='enroll':result=transfer.enroll(load(a.peer),a.fingerprint)
    elif a.action=='receipt':
        value=transfer.state();result=value.get('activation') or value.get('rejection');require(result,'No completed signed receipt; inspect interrupted activation and retain all fences')
    elif a.action=='status':
        value=transfer.state();result={k:v for k,v in value.items() if k not in ('authority','peers','manifest','source_manifest')}
    elif a.action=='challenge':result=transfer.challenge(a.source_key,a.actor)
    elif a.action=='plan':result=transfer.plan(load(a.challenge),load(a.decisions),a.actor)
    elif a.action=='prepare':result=transfer.prepare(load(a.challenge),load(a.decisions),a.actor,a.confirmation)
    elif a.action=='recover-export':
        with transfer.owned():result=transfer.finish_export()
    elif a.action=='recover-import':result=transfer.recover_import()
    elif a.action=='export':result=transfer.export(a.output,a.custody)
    elif a.action=='inspect':result=transfer.inspect(a.archive)[0]
    elif a.action=='import':result=transfer.import_set(a.archive,a.confirmation)
    elif a.action=='promote':
        with transfer.owned():result=transfer.promote()
    elif a.action=='verify':
        with transfer.owned():result=transfer.verify_target()
    elif a.action=='isolation':result=transfer.attest_isolation(a.transfer,a.method,a.evidence,a.actor,a.address_conflict_checked)
    elif a.action=='activate':result=transfer.activate(a.confirmation)
    elif a.action=='acknowledge':result=transfer.acknowledge(load(a.receipt))
    elif a.action=='reject':result=transfer.reject()
    elif a.action=='cancel-source':result=transfer.cancel_source(load(a.receipt))
    elif a.action=='disaster-inspect':result=transfer.disaster_inspect(a.archive)
    else:
        path=transfer.root/'owner.lock';pid=int(path.read_text());require(not process_alive(pid),'Transfer process is still present; refusing lock theft');path.unlink();result={'dead_owner_reviewed':pid,'writers_reopened':False}
    if a.output and a.action!='export':
        require(not a.output.exists(),'Use a new receipt output');a.output.write_text(json.dumps(result,indent=2)+'\n');a.output.chmod(0o600)
    else:print(json.dumps(result,indent=2))

if __name__=='__main__':
    try:main()
    except Exception as e:
        print('Transfer refused. Preserve all retired/fenced state and recovery material; use the installed D5D guide. '+(str(e) if isinstance(e,ValueError) else ''),file=sys.stderr);sys.exit(1)
