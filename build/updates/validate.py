#!/usr/bin/env python3
"""One read-only validator for future staged downloads and offline USB/local imports."""
import argparse
import json
from pathlib import Path
import sys
from contract import load,validate_import,validate_rotation,Rejected


def main():
    p=argparse.ArgumentParser();p.add_argument('--manifest',type=Path,required=True);p.add_argument('--package',type=Path);p.add_argument('--state',type=Path);p.add_argument('--trust-store',type=Path,required=True);p.add_argument('--php',required=True,help='Installation-trusted PHP, never the candidate PHP');p.add_argument('--php-ini',type=Path,help='Installation-trusted Windows PHP ini when OpenSSL is dynamic');p.add_argument('--channel',choices=['stable','prerelease','development'],default='stable');p.add_argument('--delivery',choices=['local','downloaded'],default='local');p.add_argument('--fixture-trust',action='store_true');p.add_argument('--rotation-proposal',action='store_true');a=p.parse_args()
    php=[a.php]+(['-c',str(a.php_ini)] if a.php_ini else ['-n'])
    if a.rotation_proposal:result=validate_rotation(load(a.manifest),load(a.trust_store),php,fixture=a.fixture_trust)
    else:
        if not a.state or not a.package:p.error('--state and --package are required for package validation')
        result=validate_import(load(a.manifest),a.package,load(a.state),load(a.trust_store),php,a.channel,a.delivery,a.fixture_trust)
    print(json.dumps(result,sort_keys=True))

if __name__=='__main__':
    try:main()
    except (Rejected,OSError,KeyError,TypeError,ValueError) as error:print(json.dumps({'status':'rejected','reason':str(error),'active_data_mutated':False}),file=sys.stderr);sys.exit(1)
