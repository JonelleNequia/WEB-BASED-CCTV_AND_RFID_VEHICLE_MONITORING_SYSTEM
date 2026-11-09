"""NSSM startup gate. Uses only the installed bundled Python and platform runtimes."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import time
import uuid


def allowed(base,role):
    try:
        identity=json.loads((base/'identity.json').read_text())
        if identity.get('schema')!=1:return False
        for name in ('installation','generation'):uuid.UUID(identity[name])
        # Caddy's dedicated Windows SID can read protected phase/identity only;
        # it deliberately cannot inspect the application coordination/spool island.
        if role!='web' and not all((base/name).is_file() for name in ('coordination/barrier.sqlite','delivery/outbox.sqlite')):return False
        state=json.loads((base/'maintenance.json').read_text())
        return state['schema']==1 and (state['phase']=='running' or state['phase']=='resuming' and role in ['web','live','php'])
    except Exception:return False


def main():
    p=argparse.ArgumentParser();p.add_argument('--root',type=Path,required=True);p.add_argument('--service');p.add_argument('--check-only',action='store_true');a=p.parse_args()
    root=a.root;base=root/'state/lifecycle'
    role='php' if (a.service or '').startswith('PHILCST-PHP') else {'PHILCST-Web':'web','PHILCST-LiveView':'live','PHILCST-Devices':'devices','PHILCST-Detector':'detector'}.get(a.service,'scheduler')
    if a.check_only:return 0 if allowed(base,role) else 75
    while not allowed(base,role):time.sleep(1)
    app=root/'app';runtime=root/'runtime';env=os.environ|{'PHILCST_LIFECYCLE_ROOT':str(base)}
    if role=='php':cmd=[runtime/'php/php-cgi.exe','-b','127.0.0.1:'+str(9000+int(a.service[-1])),'-c',root/'config/php.ini'];cwd=app
    elif role=='web':cmd=[runtime/'caddy/caddy.exe','run','--config',root/'state/web/Caddyfile','--adapter','caddyfile'];cwd=root
    elif role=='live':cmd=[runtime/'go2rtc/go2rtc.exe','-config',app/'storage/app/go2rtc/go2rtc.yaml'];cwd=app/'storage/app/go2rtc'
    elif role in ['devices','detector']:
        cwd=app/'school-vehicle-monitoring-detector';cmd=[runtime/'python/python.exe','-B',cwd/('device_service.py' if role=='devices' else 'camera_service.py')]
    else:raise ValueError('Unknown owned service')
    child=subprocess.Popen(list(map(str,cmd)),cwd=cwd,env=env)
    return child.wait()


if __name__=='__main__':raise SystemExit(main())
