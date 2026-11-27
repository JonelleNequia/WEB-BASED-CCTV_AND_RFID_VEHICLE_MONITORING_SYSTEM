"""launchd child supervisor with bounded logs; never discovers/kills other processes."""
import argparse
import logging
from logging.handlers import RotatingFileHandler
import os
from pathlib import Path
import signal
import subprocess
import sys
from control import paths,runtime_env


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--root',type=Path,required=True);parser.add_argument('role',choices=['php','web','live','detector','devices','scheduler']);args=parser.parse_args()
    root,release,shared=paths(args.root);env=runtime_env(release,shared,args.role)
    # Persisted maintenance survives launchd/reboot. Park before opening logs or children.
    sys.path.insert(0,str(release/'app/deploy/lifecycle'))
    from service_host import allowed
    import time
    while not allowed(shared/'lifecycle',args.role):time.sleep(1)
    commands={
        'php':[release/'runtime/php/bin/php-fpm','-c',shared/'config/php.ini','--nodaemonize','--fpm-config',shared/'config/php-fpm.conf'],
        'web':[release/'runtime/caddy/caddy','run','--config',shared/'web/Caddyfile','--adapter','caddyfile'],
        'live':[release/'runtime/go2rtc/go2rtc','-config',shared/'storage/app/go2rtc/go2rtc.yaml'],
        'detector':[release/'runtime/python/bin/python3','-B',release/'app/school-vehicle-monitoring-detector/camera_service.py'],
        'devices':[release/'runtime/python/bin/python3','-B',release/'app/school-vehicle-monitoring-detector/device_service.py'],
        'scheduler':[release/'runtime/php/bin/php','-c',shared/'config/php.ini',release/'app/artisan','schedule:run']}
    logger=logging.getLogger('service');logger.setLevel(logging.INFO)
    log=shared/'web/logs/supervisor.log' if args.role=='web' else shared/'logs'/(args.role+'.log')
    handler=RotatingFileHandler(log,maxBytes=10*1024*1024,backupCount=5);handler.setFormatter(logging.Formatter('%(asctime)s %(message)s'));logger.addHandler(handler)
    child=subprocess.Popen([str(x) for x in commands[args.role]],env=env,cwd=release/'app',stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,errors='replace',start_new_session=True)
    def stop(*_):
        if child.poll() is None:
            os.killpg(child.pid,signal.SIGTERM)
            try:child.wait(timeout=15)
            except subprocess.TimeoutExpired:os.killpg(child.pid,signal.SIGKILL)
    signal.signal(signal.SIGTERM,stop);signal.signal(signal.SIGINT,stop)
    try:
        from control import redact
        for line in child.stdout:logger.info(redact(line.rstrip()))
        return child.wait()
    finally:stop()

if __name__=='__main__':sys.exit(main())
