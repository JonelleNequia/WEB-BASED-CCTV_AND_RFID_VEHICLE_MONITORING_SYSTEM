#!/usr/bin/env python3
"""Build/review machine only. Append concrete evidence to an artifact-bound ledger."""
import argparse,json,os,uuid
from pathlib import Path
from review import record,c
p=argparse.ArgumentParser();p.add_argument('--ledger',type=Path,required=True);p.add_argument('--ledger-sha256',required=True);p.add_argument('--platform',required=True);p.add_argument('--test',required=True);p.add_argument('--status',required=True);p.add_argument('--evidence',type=Path,required=True);p.add_argument('--command',required=True);p.add_argument('--os',required=True);p.add_argument('--scope',required=True);p.add_argument('--physical-hardware',action='store_true');a=p.parse_args()
c.require(c.sha_file(a.ledger)==a.ledger_sha256,'Ledger changed; review current evidence before updating')
value=record(c.load(a.ledger),a.platform,a.test,a.status,a.evidence,a.command,a.os,a.scope,a.physical_hardware)
temp=a.ledger.with_name('.'+a.ledger.name+'.'+uuid.uuid4().hex)
try:
 temp.write_text(json.dumps(value,indent=2)+'\n');os.replace(temp,a.ledger)
finally:temp.unlink(missing_ok=True)
