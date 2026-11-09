"""Build-machine package operations. Never boot Laravel or read installation data."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import zipfile


def sha(path):
    h = hashlib.sha256()
    with open(path, 'rb') as stream:
        for data in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(data)
    return h.hexdigest()


def write_json(path, value):
    Path(path).write_text(json.dumps(value, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def source_allowed(name):
    p = PurePosixPath(name)
    return not (p.is_absolute() or '..' in p.parts or any(x in p.parts for x in ['.git', '.venv', 'node_modules', '__pycache__'])
                or name.startswith('vendor/')
                or (p.name.startswith('.env') and p.name != '.env.example')
                or (name.startswith(('storage/', 'bootstrap/cache/', 'public/camera/')) and p.name != '.gitignore')
                or name.startswith(('public/storage', 'build/windows/cache/', 'build/windows/dist/', 'build/macos/cache/', 'build/macos/dist/', 'build/updates/dist/', 'build/clients/dist/', 'tools/go2rtc/bin/'))
                or name.startswith(('state/', 'shared/', 'releases/'))
                or ('lifecycle' in p.parts and any(x in p.parts for x in ('identity.json','maintenance.json','operation.lock','coordination','delivery')))
                or p.suffix.lower() in ['.sqlite', '.db', '.sql', '.log', '.pyc', '.pt', '.onnx', '.pth', '.zip', '.exe']
                or p.name in ['first-admin.txt', 'install-result.txt', 'install.json', '.DS_Store', 'Thumbs.db'])


def source_snapshot(repo, destination, commit, development):
    repo, dest = Path(repo).resolve(), Path(destination).resolve()
    git = lambda *args: subprocess.check_output(['git', '-C', str(repo), *args])
    head = git('rev-parse', 'HEAD').decode().strip()
    if development and commit and git('rev-parse', commit + '^{commit}').decode().strip() != head:
        raise ValueError('Explicit development commit differs from HEAD; select the intended source before capture.')
    if not development:
        if not commit:
            raise ValueError('Release requires an explicit --commit and a clean checkout. Use --development only for a NON-RELEASE candidate.')
        resolved = git('rev-parse', commit + '^{commit}').decode().strip()
        if head != resolved or git('status', '--porcelain', '--untracked-files=all').strip():
            raise ValueError('Dirty or different source checkout: release would omit working-tree fixes. Do not stage/discard automatically; review and commit explicitly, or use development mode.')
    if dest.exists():
        raise ValueError('Snapshot destination must not already exist.')
    if dest.is_relative_to(repo):
        raise ValueError('Snapshot must be outside the source repository.')
    dest.mkdir(parents=True)
    if development:
        names = set(git('ls-files', '-c', '-o', '--exclude-standard', '-z').decode().split('\0')) - {''}
    else:
        names = set(git('ls-tree', '-r', '--name-only', '-z', head).decode().split('\0')) - {''}
    hashes = {}
    for name in sorted(names):
        if not source_allowed(name):
            continue
        f = repo / name
        if development and (not f.is_file() or f.is_symlink()):
            continue
        target = dest / name
        target.parent.mkdir(parents=True, exist_ok=True)
        if development:
            shutil.copyfile(f, target)
        else:
            target.write_bytes(git('show', head + ':' + name))
        if target.suffix in ['.ps1', '.bat', '.cmd', '.iss']:
            data = target.read_bytes().replace(b'\r\n', b'\n').replace(b'\n', b'\r\n')
            target.write_bytes(data)
        hashes[name] = sha(target)
    if development:
        current_names = set(git('ls-files', '-c', '-o', '--exclude-standard', '-z').decode().split('\0')) - {''}
        if {n for n in current_names if source_allowed(n) and (repo/n).is_file() and not (repo/n).is_symlink()} != set(hashes):
            raise ValueError('Source file list changed during snapshot; retry after edits stop')
        for name,digest in hashes.items():
            raw=(repo/name).read_bytes()
            if Path(name).suffix in ['.ps1','.bat','.cmd','.iss']:raw=raw.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')
            if hashlib.sha256(raw).hexdigest()!=digest:raise ValueError('Source changed during snapshot; retry after edits stop')
    content_hash = hashlib.sha256(json.dumps(hashes, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    info = {'commit': head, 'channel': 'development' if development else 'release', 'source_sha256': content_hash,
            'files': hashes, 'snapshot_format': 1, 'line_endings': 'Windows scripts normalized to CRLF'}
    write_json(dest / 'SOURCE-INFO.json', info)
    return info


def stage_source(source, stage):
    source, stage = Path(source), Path(stage)
    info = json.loads((source / 'SOURCE-INFO.json').read_text())
    for name, digest in info['files'].items():
        if sha(source / name) != digest:
            raise ValueError('Snapshot changed after capture: ' + name)
        if name.startswith(('build/', 'tests/', '.github/', 'database/factories/')) or name in ['phpunit.xml', 'package-lock.json', 'package.json', '.gitattributes', '.gitignore']:
            continue
        if name.endswith('.md') and name not in ['README.md', 'DEPLOYMENT.md', 'ACCOUNT-SECURITY.md', 'school-vehicle-monitoring-detector/models/README.md']:
            continue
        if name.startswith('database/seeders/') and Path(name).stem not in ['InstallSeeder', 'CameraSeeder', 'RoiSeeder', 'SystemSettingSeeder', 'DatabaseSeeder']:
            continue
        target = stage / 'app' / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source / name, target)
    (stage / 'checks').mkdir(parents=True, exist_ok=True)
    for f in (source / 'build/windows/checks').iterdir():
        if f.is_file():
            shutil.copyfile(f, stage / 'checks' / f.name)
    shutil.copyfile(source / 'SOURCE-INFO.json', stage / 'SOURCE-INFO.json')


def closure(wheels, output, target='cp312-win_amd64', python_version='3.12.10'):
    try:
        from packaging.requirements import Requirement
        from packaging.markers import default_environment
        from packaging.utils import canonicalize_name
    except ImportError:
        from pip._vendor.packaging.requirements import Requirement
        from pip._vendor.packaging.markers import default_environment
        from pip._vendor.packaging.utils import canonicalize_name
    from email.parser import BytesParser
    env = default_environment() | {'sys_platform': 'win32', 'platform_system': 'Windows', 'platform_machine': 'AMD64',
        'python_version': '3.12', 'python_full_version': '3.12.10', 'implementation_name': 'cpython', 'implementation_version': '3.12.10', 'os_name': 'nt', 'platform_release': '10', 'extra': ''}
    if target.startswith('macos-'):
        env.update(sys_platform='darwin', platform_system='Darwin', platform_machine=target.removeprefix('macos-'), os_name='posix', python_full_version=python_version, implementation_version=python_version, platform_release='23.0.0')
    packages = {}
    for f in Path(wheels).glob('*.whl'):
        with zipfile.ZipFile(f) as z:
            meta = BytesParser().parsebytes(z.read(next(n for n in z.namelist() if n.endswith('.dist-info/METADATA'))))
            name = canonicalize_name(meta['Name'])
            if name in packages:
                raise ValueError('Multiple versions/providers of distribution: ' + name)
            packages[name] = {'name': str(meta['Name']), 'version': str(meta['Version']), 'sha256': sha(f), 'wheel': f.name,
                'license': str(meta.get('License-Expression') or meta.get('License') or 'See wheel LICENSE/NOTICE'),
                'requires': [str(x) for x in meta.get_all('Requires-Dist', [])]}
    for p in packages.values():
        for text in p['requires']:
            r = Requirement(text)
            if r.marker and not r.marker.evaluate(env):
                continue
            installed = packages.get(canonicalize_name(r.name))
            if not installed or not r.specifier.contains(installed['version'], prereleases=True):
                raise ValueError(p['name'] + ' dependency closure failed: ' + text)
    if not packages:
        raise ValueError('Empty wheelhouse')
    write_json(output, {'target': target, 'packages': list(packages.values()), 'closure': 'passed',
        'cv2_provider': 'opencv-python-headless payload installed last; overlapping provider files audited after assembly'})


def safe_member(name):
    p = PurePosixPath(name.replace('\\', '/'))
    return not (p.is_absolute() or '..' in p.parts or ':' in name or '\x00' in name)


def extract(archive, target):
    with zipfile.ZipFile(archive) as z:
        names = set()
        for x in z.infolist():
            key = x.filename.replace('\\', '/').rstrip('/').casefold()
            if key in names:
                raise ValueError('Duplicate Windows archive member')
            names.add(key)
            if not safe_member(x.filename) or (x.external_attr >> 16) & 0o170000 == 0o120000:
                raise ValueError('Unsafe archive member')
        z.extractall(target)


def scan(root):
    root = Path(root)
    for f in root.rglob('*'):
        if f.is_symlink():
            raise ValueError('Package may not contain symlinks: ' + str(f.relative_to(root)))
        if not f.is_file():
            continue
        name = f.relative_to(root).as_posix()
        if name.startswith('app/'):
            rel = name[4:]
            if ((Path(rel).name.startswith('.env') and Path(rel).name != '.env.example') or (rel.startswith(('storage/', 'bootstrap/cache/')) and Path(rel).name != '.gitignore') or rel.startswith(('tests/', 'database/factories/', 'public/storage', 'public/camera/')) or Path(rel).suffix.lower() in ['.sqlite', '.db', '.sql', '.log'] or Path(rel).name in ['first-admin.txt', 'install-result.txt', 'install.json']):
                raise ValueError('Installation/development data in assembled app: ' + rel)
        if f.suffix.lower() in ['.php', '.ini', '.json', '.pth', '.ps1', '.cmd', '.bat', '.yaml', '.yml'] and f.stat().st_size < 4_000_000:
            text = f.read_text(errors='replace')
            if re.search(r'/Users/[^/\s]+/|/Applications/XAMPP/|[A-Za-z]:\\Users\\[^\\\s]+\\', text):
                raise ValueError('Development-machine absolute path in package: ' + name)
            if re.search(r'(?m)^(?:APP_KEY=base64:[A-Za-z0-9+/=]+|DETECTOR_API_KEY=[a-fA-F0-9]{32,})\s*$', text):
                raise ValueError('Installation secret in package: ' + name)


def manifest(root):
    root = Path(root)
    scan(root)
    files = {}
    for f in sorted(root.rglob('*')):
        if f.is_file() and f.relative_to(root).as_posix() not in ['CONTENT-MANIFEST.json', 'CONTENT-SIGNATURE.json']:
            name = f.relative_to(root).as_posix()
            files[name] = {'sha256': sha(f), 'bytes': f.stat().st_size}
    write_json(root / 'CONTENT-MANIFEST.json', {'format': 1, 'files': files, 'total_bytes': sum(x['bytes'] for x in files.values())})
    return files


def verify(root):
    root = Path(root)
    data = json.loads((root / 'CONTENT-MANIFEST.json').read_text())
    for name, entry in data['files'].items():
        if not safe_member(name) or not (root / name).is_file() or sha(root / name) != entry['sha256']:
            raise ValueError('Missing or corrupt package file: ' + name)
    actual = {f.relative_to(root).as_posix() for f in root.rglob('*') if f.is_file() and f.relative_to(root).as_posix() not in ['CONTENT-MANIFEST.json', 'CONTENT-SIGNATURE.json']}
    if actual != set(data['files']):
        raise ValueError('Unexpected or missing files in package')
    return len(actual)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['snapshot', 'stage', 'closure', 'scan', 'manifest', 'verify', 'extract'])
    parser.add_argument('root');parser.add_argument('output', nargs='?');parser.add_argument('--commit');parser.add_argument('--development', action='store_true')
    a = parser.parse_args()
    if a.command == 'snapshot':
        x = source_snapshot(a.root, a.output, a.commit, a.development); print(json.dumps({k:v for k,v in x.items() if k != 'files'}))
    elif a.command == 'stage':stage_source(a.root, a.output)
    elif a.command == 'closure':closure(a.root, a.output)
    elif a.command == 'extract':extract(a.root, a.output)
    elif a.command == 'manifest':print('Manifest files:', len(manifest(a.root)))
    elif a.command == 'verify':print('Verified files:', verify(a.root))
    else:scan(a.root); print('Assembled package boundary scan passed')


if __name__ == '__main__':
    try:main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print('Package refused: ' + str(error), file=sys.stderr);sys.exit(1)
