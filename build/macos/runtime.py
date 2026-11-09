"""Build-only pinned inputs, Mach-O relocation and dependency/minimum-OS audit."""
import concurrent.futures
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import struct
import subprocess
import tarfile
import urllib.request
import zipfile


def digest(path):
    h=hashlib.sha256()
    with open(path,'rb') as f:
        for block in iter(lambda:f.read(1024*1024),b''):h.update(block)
    return h.hexdigest()


def fetch(entry,cache):
    cache=Path(cache);cache.mkdir(parents=True,exist_ok=True)
    url=entry.get('url') or entry['urls'][0];path=cache/entry['sha256']
    if path.is_file() and digest(path)==entry['sha256']:return path
    headers={'User-Agent':'PHILCST-offline-builder'}
    if url.startswith('https://ghcr.io/v2/'):
        repo=url.split('/v2/',1)[1].split('/blobs/',1)[0]
        token=json.load(urllib.request.urlopen('https://ghcr.io/token?service=ghcr.io&scope=repository:'+repo+':pull',timeout=40))['token']
        headers['Authorization']='Bearer '+token
    temp=path.with_suffix('.partial')
    try:
        with urllib.request.urlopen(urllib.request.Request(url,headers=headers),timeout=120) as response,temp.open('wb') as f:shutil.copyfileobj(response,f)
        if digest(temp)!=entry['sha256']:raise ValueError('Checksum mismatch: '+str(entry.get('name',url)))
        temp.replace(path)
    finally:temp.unlink(missing_ok=True)
    return path


def extract(archive,target,links=False):
    target=Path(target);target.mkdir(parents=True,exist_ok=True)
    def safe(name):
        p=Path(name)
        if p.is_absolute() or '..' in p.parts:raise ValueError('Unsafe archive member: '+name)
        return target/p
    if zipfile.is_zipfile(archive):
        with zipfile.ZipFile(archive) as z:
            for m in z.infolist():
                out=safe(m.filename)
                if m.is_dir():out.mkdir(parents=True,exist_ok=True);continue
                if (m.external_attr>>16)&0o170000==0o120000:raise ValueError('Unexpected ZIP link')
                out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(z.read(m));out.chmod((m.external_attr>>16)&0o777 or 0o644)
        return
    with tarfile.open(archive) as t:
        members=t.getmembers()
        for m in members:
            out=safe(m.name)
            if m.isdir():out.mkdir(parents=True,exist_ok=True)
            elif m.isfile():
                out.parent.mkdir(parents=True,exist_ok=True)
                with t.extractfile(m) as src,out.open('wb') as dst:shutil.copyfileobj(src,dst)
                out.chmod(m.mode&0o777)
            elif m.issym() and links:
                resolved=(out.parent/m.linkname).resolve()
                if not resolved.is_relative_to(target.resolve()):raise ValueError('External archive link: '+m.name)
                out.parent.mkdir(parents=True,exist_ok=True);out.symlink_to(m.linkname)
            # Bottle aliases are resolved by their recorded target below, not materialized.
            elif m.islnk() and links:
                src=safe(m.linkname)
                if src.is_file():shutil.copyfile(src,out)
                else:raise ValueError('Unresolved archive hardlink')


def mach(path):
    """Read thin Mach-O load commands without executing the target architecture."""
    with open(path,'rb') as f:
        head=f.read(32)
        if len(head)<28:return None
        magic=head[:4]
        if magic in [b'\xca\xfe\xba\xbe',b'\xca\xfe\xba\xbf']:return {'fat':True}
        if magic not in [b'\xcf\xfa\xed\xfe',b'\xce\xfa\xed\xfe']:return None
        cpu=struct.unpack_from('<I',head,4)[0];ncmd=struct.unpack_from('<I',head,16)[0]
        f.seek(32 if magic==b'\xcf\xfa\xed\xfe' else 28);deps=[];rpaths=[];minimum=(0,0,0);identity=None
        for _ in range(ncmd):
            h=f.read(8)
            if len(h)!=8:raise ValueError('Truncated Mach-O')
            cmd,size=struct.unpack('<II',h)
            if size<8:raise ValueError('Invalid Mach-O load command')
            data=h+f.read(size-8)
            if cmd in [0xc,0x18|0x80000000,0x1f|0x80000000,0x20,0x23|0x80000000,0xd]:
                offset=struct.unpack_from('<I',data,8)[0];value=data[offset:].split(b'\0',1)[0].decode()
                if cmd==0xd:identity=value
                else:deps.append(value)
            elif cmd==0x8000001c:
                offset=struct.unpack_from('<I',data,8)[0];rpaths.append(data[offset:].split(b'\0',1)[0].decode())
            elif cmd in [0x24,0x32]:
                v=struct.unpack_from('<I',data,12 if cmd==0x32 else 8)[0];minimum=max(minimum,(v>>16,(v>>8)&255,v&255))
    return {'arch':{0x1000007:'x86_64',0x100000c:'arm64'}.get(cpu,hex(cpu)),'deps':deps,'rpaths':rpaths,'minimum':minimum,'id':identity}


def command(args):
    run=subprocess.run([str(x) for x in args],capture_output=True,text=True)
    if run.returncode:raise RuntimeError('Build command failed: '+str(args[0])+' '+run.stderr[-1800:])
    return run.stdout


def thin(path,arch):
    m=mach(path)
    if m and m.get('fat'):
        temp=path.with_name(path.name+'.thin');command(['/usr/bin/lipo',path,'-thin',arch,'-output',temp]);temp.replace(path)
    return mach(path)


def sign(path,identity='-'):
    command(['/usr/bin/codesign','--force','--sign',identity,'--timestamp=none' if identity=='-' else '--timestamp',path])


def php_runtime(lock,cache,stage,arch):
    work=Path(cache)/('php-extract-'+arch);work.mkdir(parents=True,exist_ok=True)
    entries=lock['php']['bottles']
    with concurrent.futures.ThreadPoolExecutor(6) as pool:archives=list(pool.map(lambda e:fetch(e,cache),entries))
    for entry,archive in zip(entries,archives):
        dest=work/entry['name'];
        if not (dest/'.complete').exists():extract(archive,dest);(dest/'.complete').touch()
    regular={};aliases={}
    for entry,archive in zip(entries,archives):
        dest=work/entry['name']
        for f in dest.rglob('*'):
            if f.is_file():regular.setdefault(f.name,[]).append(f)
        with tarfile.open(archive) as t:
            for m in t.getmembers():
                if m.issym():aliases[Path(m.name).name]=Path(m.linkname).name
        notices=[f for f in dest.rglob('*') if f.is_file() and f.name.upper().startswith(('LICENSE','COPYING','NOTICE','README.REDIST'))]
        for f in notices:
            target=stage/'licenses/php-bottles'/entry['name']/f.relative_to(dest);target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(f,target)
    def find(name):
        seen=set()
        while name not in regular and name in aliases and name not in seen:seen.add(name);name=aliases[name]
        found=regular.get(name,[])
        if len(found)!=1:raise ValueError('Missing/ambiguous PHP native library: '+name)
        return found[0]
    dest=stage/'runtime/php';(dest/'lib').mkdir(parents=True,exist_ok=True);(dest/'bin').mkdir(exist_ok=True)
    todo=[(find('php'),dest/'bin/php'),(find('php-fpm'),dest/'bin/php-fpm'),(find('opcache.so'),dest/'lib/opcache.so')];done=set()
    # Only required CLI/FPM native closure, not Apache/PEAR/build tools.
    while todo:
        src,out=todo.pop()
        if out in done:continue
        done.add(out);shutil.copyfile(src,out);out.chmod(0o755);m=thin(out,arch)
        if not m or m['arch']!=arch:raise ValueError('Wrong PHP Mach-O architecture')
        args=['/usr/bin/install_name_tool'];changes=False
        for dep in m['deps']:
            if dep.startswith(('/usr/lib/','/System/Library/')):continue
            name=Path(dep).name;libdest=dest/'lib'/name
            todo.append((find(name),libdest));relative=os.path.relpath(libdest,out.parent)
            args+=['-change',dep,'@loader_path/'+relative];changes=True
        for rpath in m['rpaths']:
            if rpath.startswith(('/opt/','/usr/local/','@@')):args+=['-delete_rpath',rpath];changes=True
        if m['id']:args+=['-id','@loader_path/'+out.name];changes=True
        if changes:command(args+[out])
        sign(out)
    print('Relocated PHP closure:',len(done),'native files',flush=True)


def materialize_links(root):
    # Distribute ordinary files only, keeping content manifests and extraction simple.
    for f in sorted(Path(root).rglob('*')):
        if f.is_symlink():
            resolved=f.resolve()
            if not resolved.is_relative_to(Path(root).resolve()) or not resolved.is_file():raise ValueError('Unresolved/external runtime link: '+str(f))
            data=resolved.read_bytes();mode=resolved.stat().st_mode&0o777;f.unlink();f.write_bytes(data);f.chmod(mode)


def audit(root,arch,signing_identity='-'):
    root=Path(root).resolve();records=[];maximum=(0,0,0)
    by_name={}
    for item in root.rglob('*'):
        if item.is_file():by_name.setdefault(item.name,[]).append(item)
    for f in sorted(root.rglob('*')):
        if not f.is_file():continue
        m=thin(f,arch)
        if not m:continue
        if m['arch']!=arch:raise ValueError('Wrong native architecture: '+str(f.relative_to(root)))
        maximum=max(maximum,tuple(m['minimum']))
        changes=[]
        for rp in dict.fromkeys(m['rpaths']):
            if rp.startswith(('/opt/','/usr/local/','/Users/','/private/','@@')):changes+=['-delete_rpath',rp]
        if m['id'] and m['id'].startswith('/'):changes+=['-id','@loader_path/'+f.name]
        # Some wheel dylibs rely on an importing extension's inherited RPATH.
        # Make same-directory wheel dependencies explicit rather than relying on it.
        for dep in m['deps']:
            if dep.startswith('@rpath/'):
                local=f.parent/dep[len('@rpath/'):]
                if local.is_file():changes+=['-change',dep,'@loader_path/'+dep[len('@rpath/'):]]
                elif len(by_name.get(Path(dep).name,[]))==1:
                    target=by_name[Path(dep).name][0]
                    changes+=['-change',dep,'@loader_path/'+os.path.relpath(target,f.parent)]
        if changes:command(['/usr/bin/install_name_tool',*changes,f]);m=mach(f)
        while any(rp.startswith(('/opt/','/usr/local/','/Users/','/private/','@@')) for rp in m['rpaths']):
            rp=next(rp for rp in m['rpaths'] if rp.startswith(('/opt/','/usr/local/','/Users/','/private/','@@')))
            command(['/usr/bin/install_name_tool','-delete_rpath',rp,f]);m=mach(f)
        sign(f,signing_identity)
        command(['/usr/bin/codesign','--verify','--strict',f])
        # Loader paths must close inside package, or use OS-provided system libraries.
        for dep in m['deps']:
            if dep.startswith(('/usr/lib/','/System/Library/')):continue
            candidates=[]
            if dep.startswith('@loader_path/'):candidates=[f.parent/dep[len('@loader_path/'):]]
            elif dep.startswith('@executable_path/'):
                rel=dep[len('@executable_path/'):];candidates=[root/'runtime/python/bin'/rel,root/'runtime/php/bin'/rel]
            elif dep.startswith('@rpath/'):
                rel=dep[len('@rpath/'):]
                for rp in dict.fromkeys(m['rpaths']):
                    if rp.startswith('@loader_path/'):candidates.append(f.parent/rp[len('@loader_path/'):]/rel)
                    elif rp.startswith('@executable_path/'):candidates.append(root/'runtime/python/bin'/rp[len('@executable_path/'):]/rel)
            if not any(c.resolve().is_relative_to(root) and c.is_file() for c in candidates):raise ValueError('Unresolved/external native dependency: '+str(f.relative_to(root))+' -> '+dep)
        if any(rp.startswith(('/opt/homebrew','/usr/local','/Users/','@@')) for rp in m['rpaths']):raise ValueError('External native RPATH: '+str(f))
        records.append({'file':f.relative_to(root).as_posix(),**m,'signature':'ad-hoc' if signing_identity=='-' else 'Developer ID'})
    return {'architecture':arch,'minimum_from_macho':'.'.join(map(str,maximum)),'native_files':records,'count':len(records),'closure':'passed','publisher_authenticated':signing_identity!='-'}
