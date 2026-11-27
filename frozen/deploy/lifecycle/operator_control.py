"""Open/Status remain read-only; Start/Stop use the owned maintenance engine."""
import argparse
import json
from pathlib import Path
import sys
import uuid
sys.path.insert(0,str(Path(__file__).resolve().parent))
from maintenance import Native,Coordinator,layout
from coordination import phase
from ownership_guard import check
from recovery_guard import check as recovery_check


def control(adapter,action):
    adapter.admin();base=adapter.p['base'];check(base);recovery_check(base)
    ctl=Coordinator(base,adapter);state=phase(base)
    if action=='stop':
        if state['phase']=='running':return ctl.enter(str(uuid.uuid4()))
        return ctl.status() # Never steal a backup/update/transfer operation.
    if state['phase']!='running':
        ctl.resume(state['operation']);return ctl.status()
    receipt=state.get('adapter')
    if receipt:
        adapter.check();adapter.start_access(receipt);adapter.ready();adapter.reopen(receipt)
    return ctl.status()


def main():
    p=argparse.ArgumentParser();p.add_argument('action',choices=['start','stop']);p.add_argument('--root',type=Path,required=True);a=p.parse_args()
    print(json.dumps(control(Native(layout(a.root)),a.action),indent=2))


if __name__=='__main__':
    try:main()
    except Exception:
        print('Start/Stop refused. Preserve maintenance/ownership state and use the exact recovery controller recorded for that operation.',file=sys.stderr);sys.exit(1)
