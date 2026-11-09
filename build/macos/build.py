#!/usr/bin/env python3
"""Mac offline candidate builder. Native macOS build tools only; no target dependencies."""
import argparse
import concurrent.futures
import datetime
import importlib.util
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.parse
from runtime import fetch,extract,php_runtime,materialize_links,audit,command,digest


def shared(source):
    spec=importlib.util.spec_from_file_location('vms_package',source/'build/windows/package.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m


def execute(args,cwd=None,env=None):
    result=subprocess.run([str(x) for x in args],cwd=cwd,env=env)
    if result.returncode:raise RuntimeError('Build step failed: '+str(args[0]))


def assemble(source,args):
    package=shared(source);info=json.loads((source/'SOURCE-INFO.json').read_text());lock=json.loads((source/f'build/macos/runtimes-{args.arch}.json').read_text());wheel_lock=json.loads((source/f'build/macos/wheels-{args.arch}.json').read_text())
    out=args.out.resolve();cache=args.cache.resolve();out.mkdir(parents=True,exist_ok=True);stage=out/'PHILCST-VMS'
    if stage.exists():raise ValueError('Output stage already exists; choose a new output. Existing files were not deleted.')
    version=args.version+('-dev.'+info['source_sha256'][:12] if info['channel']=='development' else '')
    env=os.environ|{'npm_config_cache':str(cache/'npm'),'COMPOSER_CACHE_DIR':str(cache/'composer'),'PYTHONDONTWRITEBYTECODE':'1'}
    execute(['npm','ci','--ignore-scripts','--no-audit','--no-fund'],source,env);execute(['npm','run','build'],source,env)
    stage.mkdir();package.stage_source(source,stage)
    # Target has no state in the artifact; mutable links are created only during install.
    for path in [stage/'app/storage',stage/'app/bootstrap/cache']:
        if path.exists():shutil.rmtree(path)
    for path in [stage/'app/tools/start',stage/'app/deploy/windows']:
        if path.exists():shutil.rmtree(path)
    shutil.copytree(source/'public/build',stage/'app/public/build',dirs_exist_ok=True)
    execute(['composer','install','--working-dir',stage/'app','--no-dev','--optimize-autoloader','--no-interaction','--no-progress','--no-scripts'],env=env)
    php_runtime(lock,cache,stage,args.arch)
    pytemp=cache/('python-'+args.arch)
    if not (pytemp/'python').is_dir():extract(fetch(lock['python'],cache),pytemp,links=True)
    shutil.copytree(pytemp/'python',stage/'runtime/python',symlinks=True)
    materialize_links(stage/'runtime/python')
    for name in ['venv','ensurepip']:
        path=stage/'runtime/python/lib/python3.12'/name
        if path.exists():shutil.rmtree(path)
    # Remove development-only console launchers, pip and compiled caches (absolute build shebangs).
    for f in (stage/'runtime/python/bin').iterdir():
        if f.name not in ('python','python3','python3.12'):f.unlink()
    wheels=cache/('wheelhouse-'+digest(source/f'build/macos/wheels-{args.arch}.json')[:12]);wheels.mkdir(exist_ok=True)
    def wheel(entry):
        f=fetch(entry,cache);target=wheels/urllib.parse.unquote(entry['url'].rsplit('/',1)[-1]);shutil.copyfile(f,target);return target
    with concurrent.futures.ThreadPoolExecutor(6) as pool:list(pool.map(wheel,wheel_lock['packages']))
    package.closure(wheels,stage/'PYTHON-DEPENDENCIES.json','macos-'+args.arch,'3.12.16')
    site=stage/'runtime/python/lib/python3.12/site-packages'
    execute([sys.executable,'-m','pip','install','--disable-pip-version-check','--no-compile','--no-deps','--only-binary=:all:','--platform','macosx_14_0_'+args.arch,'--python-version','3.12','--implementation','cp','--no-index','--find-links',wheels,'--require-hashes','--target',site,'-r',source/f'build/macos/requirements-{args.arch}.lock'],env=env)
    execute([sys.executable,source/'build/windows/opencv-provider.py',wheels,site],env=env)
    if (site/'bin').exists():shutil.rmtree(site/'bin')
    for f in list(site.glob('pip*')):
        if f.is_dir():shutil.rmtree(f)
    for f in list(site.rglob('__pycache__')):
        if f.exists():shutil.rmtree(f)
    for key,exe in [('caddy','caddy')]:
        work=cache/(key+'-'+args.arch)
        if not work.exists():extract(fetch(lock[key],cache),work)
        binary=next(f for f in work.rglob(exe) if f.is_file());dest=stage/'runtime'/key;dest.mkdir(parents=True);shutil.copyfile(binary,dest/exe);(dest/exe).chmod(0o755)
        for f in work.rglob('*'):
            if f.is_file() and f.name.upper().startswith(('LICENSE','NOTICE','COPYING')):
                target=stage/'licenses'/key/f.name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(f,target)
    # D4 derivative enforces a server-side viewer lease; stock go2rtc does not.
    execute([sys.executable,source/'build/go2rtc/build.py','--cache',cache/'go2rtc-source','--out',stage/'runtime/go2rtc','--os','darwin','--arch','arm64' if args.arch=='arm64' else 'amd64',*(['--test'] if platform.machine()==args.arch else [])],env=env)
    shutil.copyfile(stage/'runtime/go2rtc/GO2RTC-SOURCE-INFO.json',stage/'GO2RTC-SOURCE-INFO.json')
    shutil.move(str(stage/'runtime/go2rtc/GO2RTC-SOURCE-NOTICES'),str(stage/'licenses/go2rtc-source'))
    lock['go2rtc']['packaged_derivative']=json.loads((stage/'GO2RTC-SOURCE-INFO.json').read_text())
    windows=json.loads((source/'build/windows/runtimes.json').read_text())
    for group in ['models','fixtures','notices']:
        for name,entry in windows[group].items():
            target=stage/('app/'+entry['target'] if group=='models' else entry['target']);file=fetch(entry,cache)
            if entry['type']=='zip':extract(file,target)
            else:target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(file,target)
    target=stage/lock['video_fixture']['target'];target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(fetch(lock['video_fixture'],cache),target)
    shutil.copytree(source/'build/macos/checks',stage/'checks',dirs_exist_ok=True)
    # Frozen target bootstrap/control and templates; no dynamic fetch at installation.
    shutil.copytree(source/'build/macos/templates',stage/'config')
    shutil.copyfile(source/'deploy/macos/control.py',stage/'checks/control.py')
    (stage/'Install.command').write_text('#!/bin/sh\nset -eu\nD=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)\nexec sudo "$D/runtime/python/bin/python3" -B "$D/app/deploy/macos/control.py" install --payload "$D" "$@"\n');(stage/'Install.command').chmod(0o755)
    shutil.copyfile(source/'build/macos/OFFLINE-INSTALL.md',stage/'OFFLINE-INSTALL.md')
    shutil.copyfile(source/'deploy/network/NETWORK-SETUP.md',stage/'NETWORK-SETUP.md')
    shutil.copyfile(source/'deploy/lifecycle/MAINTENANCE.md',stage/'MAINTENANCE.md')
    shutil.copytree(source/'deploy/clients',stage/'Connect-to-Server')
    (stage/'Connect-to-Server/Connect-to-PHILCST.command').chmod(0o755)
    for n in ['runtimes','wheels']:
        shutil.copyfile(source/f'build/macos/{n}-{args.arch}.json',stage/(n.upper()+'-INPUTS.json'))
    # Reuse the notices inventory, replacing its input metadata with all Mac supplier pins.
    (stage/'RUNTIME-INPUTS.json').write_text(json.dumps({'mac':lock,'models':windows['models'],'fixture':windows['fixtures'],'notices':windows['notices']},indent=2)+'\n')
    execute([sys.executable,source/'build/windows/license-inventory.py',stage,source],env=env)
    native=audit(stage,args.arch,args.sign_identity or '-');(stage/'NATIVE-DEPENDENCIES.json').write_text(json.dumps(native,indent=2)+'\n')
    minimum=max(tuple(map(int,native['minimum_from_macho'].split('.'))),(14,0,0))
    # Built-in shell gate runs before the bundled interpreter (no Rosetta prompt or old-OS dyld failure).
    bootstrap = """#!/bin/sh
set -eu
D=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
[ "$(uname -s)" = Darwin ] || { echo 'Native macOS is required.'; exit 1; }
NATIVE=x86_64
if [ "$(/usr/sbin/sysctl -n hw.optional.arm64 2>/dev/null || true)" = 1 ]; then NATIVE=arm64; fi
[ "$NATIVE" = EXPECTED_ARCH ] || { echo 'Wrong architecture: transfer the native '$NATIVE' package. Rosetta is unsupported.'; exit 1; }
OS=$(/usr/bin/sw_vers -productVersion)
/usr/bin/awk -v got="$OS" -v needed="MINIMUM_OS" 'BEGIN { split(got,g,"."); split(needed,n,"."); for(i=1;i<=3;i++){if(g[i]+0>n[i]+0)exit 0;if(g[i]+0<n[i]+0)exit 1;} exit 0; }' || { echo 'This candidate requires macOS MINIMUM_OS or newer.'; exit 1; }
for FILE in runtime/python/bin/python3 runtime/php/bin/php app/deploy/macos/control.py; do
  [ -f "$D/$FILE" ] || { echo 'Missing required component: '$FILE'. Re-extract the complete verified offline package.'; exit 1; }
done
exec sudo "$D/runtime/python/bin/python3" -B "$D/app/deploy/macos/control.py" install --payload "$D" "$@"
"""
    (stage/'Install.command').write_text(bootstrap.replace('EXPECTED_ARCH',args.arch).replace('MINIMUM_OS','.'.join(map(str,minimum))));(stage/'Install.command').chmod(0o755)
    metadata={'product':'PHILCST Vehicle Monitoring','version':version,'commit':info['commit'],'source':info,'build_identity':args.build_identity or version,'architecture':args.arch,'mode':'local-only','channel':info['channel'],'minimum_macos':'.'.join(map(str,minimum)),'minimum_tested_os':None,'built_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'runtimes':{'php':lock['php']['version'],'python':lock['python']['version'],'caddy':lock['caddy']['version'],'go2rtc':lock['go2rtc']['packaged_derivative']['version']},'dependency_locks':{n:digest(source/n) for n in ['composer.lock','package-lock.json',f'build/macos/wheels-{args.arch}.json',f'build/macos/requirements-{args.arch}.lock',f'build/macos/runtimes-{args.arch}.json','build/go2rtc/inputs.json','build/go2rtc/viewer-lease.patch']},'tools':{'python':sys.version.split()[0],'macos':platform.mac_ver()[0],'host_arch':platform.machine(),'node':command(['node','--version']).strip(),'composer':command(['composer','--version','--no-ansi']).strip()},'validation':{'macho_arch_library_closure':'passed','wheel_closure':'passed','native_runtime':'NOT RUN','cpu_inference':'NOT RUN','offline_install_acceptance':'NOT RUN'},'publisher_authenticated':False,'code_signing':'ad-hoc' if not args.sign_identity else 'Developer ID signature; notarization pending','notarization':'NOT RUN','release_gates':['Native clean offline min/current OS acceptance','Intel Torch 2.2 maintenance/security and PHP refresh approval','Redistribution/signing/notarization','D4 HTTPS/clients','D5 update/recovery/outbox']}
    (stage/'BUILD-INFO.json').write_text(json.dumps(metadata,indent=2)+'\n');package.manifest(stage)
    if platform.machine()==args.arch:
        execute([stage/'runtime/python/bin/python3','-B',stage/'app/deploy/macos/control.py','readiness','--root',stage,'--package-only','--inference'],env=env)
        with tempfile.TemporaryDirectory(prefix='philcst-native-service-check-') as work:
            work=Path(work);(work/'logs').mkdir()
            for name in ['php.ini','php-fpm.conf']:
                text=(stage/('config/'+name+'.template')).read_text().replace('{{SHARED}}',str(work)).replace('{{RELEASE}}',str(stage))
                (work/name).write_text(text)
            sys.path.insert(0,str(source/'deploy/network'))
            from policy import caddy_config,DEFAULT
            (work/'Caddyfile').write_text(caddy_config((stage/'config/Caddyfile.template').read_text(),stage/'app/public',work/'tls',work/'logs/caddy-access.log',dict(DEFAULT)))
            native_env=env|{'HOME':str(work),'PHP_INI_SCAN_DIR':''}
            execute([stage/'runtime/php/bin/php-fpm','-c',work/'php.ini','--fpm-config',work/'php-fpm.conf','-t'],env=native_env)
            execute([stage/'runtime/caddy/caddy','validate','--config',work/'Caddyfile','--adapter','caddyfile'],env=native_env)
            execute([stage/'runtime/go2rtc/go2rtc','-version'],env=native_env)
        metadata['validation']['native_services']='PHP-FPM and Caddy configuration, go2rtc version passed; no installed services'
        metadata['validation']['native_runtime']='passed on '+platform.mac_ver()[0];metadata['validation']['cpu_inference']='recorded prediction/tracking/OCR passed';metadata['minimum_tested_os']=platform.mac_ver()[0]
        (stage/'BUILD-INFO.json').write_text(json.dumps(metadata,indent=2)+'\n');package.manifest(stage)
    elif info['channel']=='release':raise ValueError('Release requires native target-architecture runtime/inference execution')
    package.verify(stage)
    archive=out/f'PHILCST-VMS-{version}-macos-{args.arch}.tar.gz'
    with tarfile.open(archive,'w:gz',compresslevel=6) as tar:tar.add(stage,arcname='PHILCST-VMS')
    archive.with_suffix('.gz.sha256').write_text(digest(archive)+'  '+archive.name+'\n')
    with tempfile.TemporaryDirectory(prefix='philcst-extracted-') as work:
        extract(archive,work);extracted=Path(work)/'PHILCST-VMS';package.verify(extracted)
        if platform.machine()==args.arch:
            # Prove the native executables relocate to the extracted path, too.
            execute([extracted/'runtime/php/bin/php','-n','-r','echo PHP_VERSION, PHP_EOL;'],env=env|{'PHP_INI_SCAN_DIR':''})
            execute([extracted/'runtime/python/bin/python3','-B','-c','import sys,sqlite3,cv2,torch,onnxruntime; print(sys.version.split()[0], sqlite3.sqlite_version, cv2.__version__, torch.__version__, onnxruntime.__version__)'],env=env|{'PYTHONDONTWRITEBYTECODE':'1','PYTHONNOUSERSITE':'1'})
    execute([sys.executable,source/'build/updates/generate.py','--stage',stage,'--package',archive,'--output',str(archive)+'.update.json'],env=env)
    print('Built and extracted-byte verified:',archive,'SHA256',digest(archive),flush=True)


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--arch',choices=['arm64','x86_64'],required=True);parser.add_argument('--version',required=True);parser.add_argument('--commit');parser.add_argument('--development',action='store_true');parser.add_argument('--out',type=Path,required=True);parser.add_argument('--cache',type=Path,required=True);parser.add_argument('--build-identity');parser.add_argument('--sign-identity');parser.add_argument('--redistribution-approved',action='store_true');parser.add_argument('--frozen-source',type=Path,help=argparse.SUPPRESS);args=parser.parse_args()
    if platform.system()!='Darwin':raise ValueError('Mach-O relocation and signing require a macOS builder')
    if not re_version(args.version):raise ValueError('Use a semantic version')
    repo=Path(__file__).resolve().parents[2];package=shared(repo)
    if not args.development:raise ValueError('Release blocked pending Developer ID/notarization pipeline, maintenance/redistribution approval and native acceptance. Current D3 automation creates NON-RELEASE acceptance candidates only; pass --development.')
    if args.frozen_source:assemble(args.frozen_source,args);return
    with tempfile.TemporaryDirectory(prefix='philcst-mac-source-') as temp:
        source=Path(temp)/'snapshot';package.source_snapshot(repo,source,args.commit,args.development)
        # Execute the frozen build script, not a mix of current build inputs and HEAD.
        arguments=[sys.executable,str(source/'build/macos/build.py'),*sys.argv[1:],'--frozen-source',str(source)]
        execute(arguments,env=os.environ|{'PYTHONDONTWRITEBYTECODE':'1'})


def re_version(value):
    import re
    return re.fullmatch(r'\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?',value)

if __name__=='__main__':
    try:main()
    except Exception as error:print('Mac package refused: '+str(error),file=sys.stderr);sys.exit(1)
