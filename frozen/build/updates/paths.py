"""Freeze exact target identity after BUILD-INFO; avoids recursive source/commit hashes."""
import argparse
import json
from pathlib import Path
from contract import load,require,compatibility_ids


def create(info,policy):
    platform='windows-x64' if info['architecture']=='win_amd64' else 'macos-'+info['architecture']
    schema,runtime=compatibility_ids(info,platform)
    target={'platform':platform,'version':info['version'],'source_sha256':info['source']['source_sha256'],'commit':info['commit'],
            'build_identity':info.get('build_identity',info['version']),'schema_id':schema,'runtime_id':runtime}
    require(policy['schema']=='philcst.upgrade-paths.v1' and policy['product']=='philcst-vms','Wrong exact-path build policy')
    paths=[]
    for entry in policy['paths']:
        require(set(entry)=={'from','database_schema_sha256','service_contract'},'Build policy must approve an origin/schema, not recursively embed a target source identity')
        if entry['from']['platform']==platform:paths.append(dict(entry,to=target))
    return {'schema':'philcst.upgrade-paths.v1','product':'philcst-vms','paths':paths}

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--stage',type=Path,required=True);p.add_argument('--policy',type=Path,required=True);a=p.parse_args()
    (a.stage/'UPDATE-PATHS.json').write_text(json.dumps(create(load(a.stage/'BUILD-INFO.json'),load(a.policy)),indent=2)+'\n')
