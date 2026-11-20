"""Installed Windows/Mac D5B adapters. Importing never touches an installation."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from backup_contract import canonical, env_values, load, no_links, require, sha
from maintenance import Native, command, layout
from coordination import phase

PRESERVE_CURRENT = {'environment', 'configuration', 'tls', 'network', 'tls_identity', 'public_root', 'server_trust', 'update_trust', 'installation'}


def recovery_paths(paths):
    p = dict(paths); shared = p['root'] / ('state' if p['windows'] else 'shared')
    p['recovery'] = shared / 'recovery'
    p['targets'] = {
        'database': p['app'] / 'database/database.sqlite' if p['windows'] else shared / 'database/database.sqlite',
        'environment': p['app'] / '.env' if p['windows'] else shared / '.env',
        'storage': p['app'] / 'storage' if p['windows'] else shared / 'storage',
        'configuration': p['root'] / 'config/php.ini' if p['windows'] else shared / 'config',
        'tls': shared / 'web', 'network': shared / 'network.json', 'tls_identity': shared / 'tls-identity.json',
        'public_root': shared / 'public-root.crt', 'server_trust': shared / 'server-trust.json',
        'update_trust': shared / 'update', 'setup': p['root'] / 'config/setup' if p['windows'] else shared / 'setup',
        'installation': p['root'] / 'config/install.json' if p['windows'] else shared / 'installation.json',
        'delivery_identity': p['base'] / 'identity.json', 'delivery': p['base'] / 'delivery',
        'coordination': p['base'] / 'coordination', 'security': p['base'] / 'recovery-security.json'}
    return p


def compatibility(info):
    platform = 'windows-x64' if info['architecture'] == 'win_amd64' else 'macos-' + info['architecture']
    require(info['product'] == 'PHILCST Vehicle Monitoring' and platform in ('windows-x64', 'macos-arm64', 'macos-x86_64'), 'Unsupported installed product/platform')
    migrations = {n: h for n, h in info['source']['files'].items() if n.startswith('database/migrations/')}
    require(migrations, 'Missing installed migration identity')
    return {'platform': platform, 'version': info['version'], 'source_sha256': info['source']['source_sha256'],
            'build_identity': info.get('build_identity', info['version']), 'commit': info['commit'],
            'schema_id': sha(canonical(migrations)),
            'runtime_id': sha(canonical({'platform': platform, 'runtimes': info['runtimes'], 'dependency_locks': info['dependency_locks']}))}


class NativeRecovery(Native):
    fixture = False

    def __init__(self, paths):
        super().__init__(recovery_paths(paths))

    def machine(self):
        if self.p['windows']:
            import winreg
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, r'SOFTWARE\Microsoft\Cryptography', 0, winreg.KEY_READ | winreg.KEY_WOW64_64KEY) as key:
                value = winreg.QueryValueEx(key, 'MachineGuid')[0]
        else:
            raw = command(['/usr/sbin/ioreg', '-rd1', '-c', 'IOPlatformExpertDevice'])
            match = re.search(r'"IOPlatformUUID"\s*=\s*"([A-Fa-f0-9-]+)"', raw)
            require(match is not None, 'Native machine identity unavailable')
            value = match[1]
        require(isinstance(value, str) and value, 'Native machine identity unavailable')
        return sha(value.encode())

    def private(self, path):
        path = Path(path); no_links(path)
        existed = path.exists()
        if existed and not self.p['windows']:
            require(path.is_dir() and path.stat().st_uid == 0 and path.stat().st_mode & 0o077 == 0, 'Choose an existing root-private directory or a new private destination')
        path.mkdir(mode=0o700, parents=True, exist_ok=True)
        if self.p['windows']:
            args = ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', self.p['app'] / 'deploy/windows/recovery-acl.ps1',
                    '-Root', self.p['root'], '-Path', path, '-Role', 'private', '-Action', 'Protect']
            command(args + (['-ExistingPrivate'] if existed else []))
        else:
            os.chown(path, 0, 0); path.chmod(0o700)

    def permission_policy(self, role):
        import pwd, grp
        user = pwd.getpwnam('_philcstvms').pw_uid; group = grp.getgrnam('_philcstvms').gr_gid
        if role in ('tls', 'setup'):
            return 0, 0, 0o700, 0o600
        if role in ('delivery', 'coordination'):
            return 0, group, 0o2770, 0o660
        if role in ('storage', 'database'):
            return user, group, 0o750, 0o660 if role == 'database' else 0o640
        if role == 'environment':
            return user, group, 0o750, 0o600
        return 0, group, 0o750, 0o640

    def permissions_for(self, role, path, verify=False):
        path = Path(path)
        if not path.exists():
            return
        no_links(path)
        if self.p['windows']:
            command(['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
                     self.p['app'] / 'deploy/windows/recovery-acl.ps1', '-Root', self.p['root'],
                     '-Path', path, '-Role', role, '-Action', 'Verify' if verify else 'Protect'])
            return
        user, group, directories, files = self.permission_policy(role)
        members = [path] + (list(path.rglob('*')) if path.is_dir() else [])
        for member in members:
            no_links(member); mode = directories if member.is_dir() else files
            if verify:
                st = member.stat()
                require((st.st_uid, st.st_gid, st.st_mode & 0o7777) == (user, group, mode), 'Restored ownership/permissions do not match the installed service policy')
            else:
                os.chown(member, user, group); member.chmod(mode)

    def crypto_check(self, payload):
        trust = payload / 'update_trust/trust.json'
        public = {}
        if trust.exists():
            public = {key: value['public_key'] for key, value in load(trust)['keys'].items()}
        argument = {'database': str(payload / 'database/data'), 'environment': str(payload / 'environment/data'),
                    'web': str(payload / 'tls'), 'tls_identity': str(payload / 'tls_identity/data'),
                    'public_root': str(payload / 'public_root/data'), 'public_keys': public}
        result = subprocess.run([str(self.p['php']), '-c', str(self.p['ini']), str(self.p['app'] / 'deploy/lifecycle/recovery-check.php')],
                                input=json.dumps(argument), capture_output=True, text=True, timeout=60, env=self.env())
        require(result.returncode == 0, 'Key/secret/issuer validation failed; no key regeneration is permitted')
        return json.loads(result.stdout)

    def check_configuration(self):
        self.check()
        web = self.p['targets']['tls']
        executable = self.p['release'] / ('runtime/caddy/caddy.exe' if self.p['windows'] else 'runtime/caddy/caddy')
        command([executable, 'validate', '--config', web / 'Caddyfile', '--adapter', 'caddyfile'])

    def prepare(self):
        # Only installed configuration/code is used. No archive ini, script or executable.
        app = self.p['app']; storage = self.p['targets']['storage']
        for name in ('framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/go2rtc'):
            (storage / name).mkdir(parents=True, exist_ok=True)
        if not self.p['windows']:
            (self.p['root'] / 'shared/cache').mkdir(parents=True, exist_ok=True)
        self.permissions_for('storage', storage)
        for args in (['config:clear'], ['config:cache'], ['system:recovery-prepare']):
            command([self.p['php'], '-c', self.p['ini'], app / 'artisan', *args], self.env())
        self.permissions_for('storage', storage)

    def env(self):
        # Use installed environment/cache paths, not an administrator shell's
        # APP_KEY/DB overrides or the incoming backup's configuration.
        values = env_values(self.p['targets']['environment'])
        result = {k: v for k, v in os.environ.items() if k not in values and not k.startswith(('APP_', 'DB_', 'SESSION_', 'CACHE_', 'QUEUE_', 'PHILCST_', 'CAMERA_', 'DEVICE_', 'MONITORING_'))}
        if not self.p['windows']:
            result.update(self.mac().runtime_env(self.p['release'], self.p['root'] / 'shared'))
        result.update({'APP_BASE_PATH': str(self.p['app']), 'APP_STORAGE_PATH': str(self.p['targets']['storage']),
                       'APP_CONFIG_CACHE': str((self.p['app'] / 'bootstrap/cache' if self.p['windows'] else self.p['root'] / 'shared/cache') / 'config.php'),
                       'PHP_INI_SCAN_DIR': '', 'PHILCST_LIFECYCLE_ROOT': str(self.p['base']),
                       'PHILCST_LIFECYCLE_CONTROL': str(phase(self.p['base'])['operation'])})
        return result

    def source_binding(self):
        return compatibility(load(self.p['release'] / 'BUILD-INFO.json'))

    def environment_binding(self, path):
        values = env_values(path)
        require(values['DB_DATABASE'] == str(self.p['targets']['database']) and not values.get('DB_URL'), 'Environment points outside the installed SQLite file')
        require(values.get('PHILCST_LIFECYCLE_ROOT') == str(self.p['base']), 'Environment points outside the installed delivery identity')
        require(values.get('MONITORING_SERVICES') == 'managed', 'Recovery requires externally managed collectors; app-launched services are unsupported')
        require(values.get('QUEUE_CONNECTION', 'sync') == 'sync' and values.get('SESSION_DRIVER', 'file') in ('file', 'database'), 'Unsupported background writer/session driver')
        for key in ('CAMERA_FILES_PATH', 'DEVICE_FILES_PATH'):
            require(not values.get(key) or Path(values[key]).is_relative_to(self.p['targets']['storage']), 'External device/config storage requires a reviewed recovery contract')
        require(values.get('MONITORING_MEDIA_DISK', 'public') == 'public' and values.get('MONITORING_ARCHIVE_DISK', 'local') == 'local', 'External/custom media disks need a reviewed backup contract')
        require(values.get('APP_URL') == 'https://philcst.vms.internal', 'Canonical origin changed')
        return {k: sha(values[k].encode()) for k in ('APP_KEY', 'DETECTOR_API_KEY')}

    def free(self, required):
        require(shutil.disk_usage(self.p['recovery']).free >= required + 256 * 1024**2, 'Insufficient private staging/rollback disk space')


def installed(root):
    native = NativeRecovery(layout(root)); native.admin()
    return native
