#!/usr/bin/env python3
"""Build-only, hash-pinned native go2rtc viewer-lease derivative. No target toolchain."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import tarfile
import tempfile
import urllib.request
import zipfile


def sha(path):
    with Path(path).open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()


def fetch(entry,destination):
    if not destination.exists():
        temporary=destination.with_suffix('.partial');urllib.request.urlretrieve(entry['url'],temporary);temporary.replace(destination)
    if sha(destination)!=entry['sha256']:raise ValueError('Pinned go2rtc/toolchain input hash mismatch: '+destination.name)
    return destination


def extract(archive,out):
    out.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(archive) if archive.suffix=='.zip' else tarfile.open(archive) as source:
        members=source.infolist() if isinstance(source,zipfile.ZipFile) else source.getmembers()
        for member in members:
            name=member.filename if isinstance(source,zipfile.ZipFile) else member.name
            if Path(name).is_absolute() or '..' in Path(name).parts or '\\' in name:raise ValueError('Unsafe source/toolchain archive')
            if isinstance(source,tarfile.TarFile) and not (member.isfile() or member.isdir()):raise ValueError('Unexpected source/toolchain link')
        source.extractall(out)


def execute(args,env,cwd):
    subprocess.run(list(map(str,args)),env=env,cwd=cwd,check=True)


def build(source,cache,out,os_name,arch,test=False):
    source=Path(source).resolve();cache=Path(cache).resolve();out=Path(out).resolve();cache.mkdir(parents=True,exist_ok=True);out.mkdir(parents=True,exist_ok=True)
    inputs=json.loads((source/'inputs.json').read_text());patch=source/'viewer-lease.patch'
    if sha(patch)!=inputs['patch_sha256']:raise ValueError('Viewer-lease patch hash mismatch')
    host=('windows' if platform.system()=='Windows' else 'darwin')+'-'+('arm64' if platform.machine().lower() in ('arm64','aarch64') else 'amd64')
    compiler=inputs['toolchains'][host];tool_archive=fetch(compiler,cache/Path(compiler['url']).name);tool_root=cache/inputs['go_version']/host
    if not (tool_root/'go/bin'/('go.exe' if host.startswith('windows') else 'go')).is_file():extract(tool_archive,tool_root)
    go=tool_root/'go/bin'/('go.exe' if host.startswith('windows') else 'go')
    upstream=fetch(inputs['source'],cache/('go2rtc-'+inputs['source']['commit']+'.tar.gz'))
    with tempfile.TemporaryDirectory(prefix='go2rtc-lease-',dir=cache) as work:
        work=Path(work);extract(upstream,work);checkout=work/('go2rtc-'+inputs['source']['commit'])
        env=os.environ|{'GOROOT':str(tool_root/'go'),'GOTOOLCHAIN':'local','GOMODCACHE':str(cache/'gomod'),'GOCACHE':str(cache/'gobuild'),'CGO_ENABLED':'0','GOOS':os_name,'GOARCH':arch,'GOAMD64':'v1'}
        execute(['git','apply','--check',patch],env,checkout);execute(['git','apply',patch],env,checkout)
        execute([go,'mod','download'],env,checkout);execute([go,'mod','verify'],env,checkout)
        if test:
            if os_name!=('windows' if platform.system()=='Windows' else 'darwin') or arch!=('arm64' if platform.machine().lower() in ('arm64','aarch64') else 'amd64'):raise ValueError('Native tests require matching host')
            shutil.copyfile(source/'viewer_lease_test.go',checkout/'internal/webrtc/philcst_lease_test.go')
            execute([go,'test','./internal/webrtc','-run','TestPhilcstViewerLease','-count=1','-v'],env,checkout)
        binary=out/('go2rtc.exe' if os_name=='windows' else 'go2rtc')
        execute([go,'build','-trimpath','-buildvcs=false','-ldflags','-s -w -X github.com/AlexxIT/go2rtc/internal/app.Version='+inputs['version'],'-o',binary,'.'],env,checkout)
        execute([go,'mod','vendor'],env,checkout)
        notices=out/'GO2RTC-SOURCE-NOTICES';notices.mkdir(exist_ok=True)
        for f in (checkout/'vendor').rglob('*'):
            if f.is_file() and f.name.lower().startswith(('license','copying','notice','copyright')):
                target=notices/f.relative_to(checkout/'vendor');target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(f,target)
        shutil.copyfile(checkout/'LICENSE',notices/'go2rtc-LICENSE');shutil.copyfile(patch,notices/patch.name)
        for name in ('go.mod','go.sum'):shutil.copyfile(checkout/name,notices/name)
        info={'schema':1,**inputs,'target_os':os_name,'architecture':arch,'binary_sha256':sha(binary),'go_sum_sha256':sha(checkout/'go.sum'),'go_mod_sha256':sha(checkout/'go.mod'),'production_lease_seconds':30,'native_timer_test':'passed' if test else 'NOT RUN'}
        (out/'GO2RTC-SOURCE-INFO.json').write_text(json.dumps(info,indent=2)+'\n')
        binary.chmod(0o755)
    return binary


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--cache',type=Path,required=True);p.add_argument('--out',type=Path,required=True);p.add_argument('--os',choices=['windows','darwin'],required=True);p.add_argument('--arch',choices=['amd64','arm64'],required=True);p.add_argument('--test',action='store_true');a=p.parse_args()
    build(Path(__file__).parent,a.cache,a.out,a.os,a.arch,a.test)
