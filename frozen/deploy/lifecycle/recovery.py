#!/usr/bin/env python3
"""D5B controlled same-installation recovery. No updater, transfer or reset.

All native mutation requires installed administrator tools. Incoming backups contain
data only and never provide the interpreter, validator, ini or executable scripts.
"""
import argparse
from contextlib import contextmanager
import datetime as dt
import json
import importlib.util
import os
from pathlib import Path
import shutil
import sys
import tempfile
import uuid
import zipfile
sys.path.insert(0, str(Path(__file__).resolve().parent))
from backup_contract import (InvalidBackup, ROLES, REQUIRED, SCHEMA, PRODUCT, canonical, sha, file_sha, load,
    require, no_links, inventory, tree_hash, database, snapshot_sqlite, sqlite_info,
    media_references, validate_delivery, validate_manifest, inspect_bundle, env_values)
from coordination import atomic, barrier, identity, phase, jobs
from maintenance import Coordinator
from recovery_platform import PRESERVE_CURRENT, installed
from recovery_security import (initial_ledger, validate_ledger, union_ledger,
    reconcile_database, reset_leases, security_state, uid)

CAPTURE_ONLY = {'coordination', 'security', 'delivery_identity'}
VERIFIED = {'backup_verified', 'accepted', 'rollback_accepted', 'current_verified'}


def safe_error(error):
    # Only controlled validator messages may be shown; vendor/path/ciphertext
    # exceptions do not become administrator diagnostics or public messages.
    if isinstance(error, InvalidBackup): return str(error)
    if isinstance(error, PermissionError): return 'Permission or locked-file refusal; keep the verified old/staged files and review ownership.'
    if isinstance(error, OSError): return 'Storage I/O failure; check capacity/availability before choosing recovery.'
    return 'Installed validation failed; inspect operation status and private administrator evidence.'


def sync_dir(path):
    if os.name == 'posix':
        fd = os.open(path, os.O_RDONLY)
        try: os.fsync(fd)
        finally: os.close(fd)


def durable_tree(path):
    path = Path(path)
    if not path.exists(): return
    if path.is_file():
        with path.open('rb') as stream: os.fsync(stream.fileno())
        return
    for member in path.rglob('*'):
        if member.is_file():
            with member.open('rb') as stream: os.fsync(stream.fileno())
    for directory, _, _ in os.walk(path, topdown=False): sync_dir(directory)


def copy_file(source, destination):
    destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with Path(source).open('rb') as src, destination.open('xb') as dst:
        shutil.copyfileobj(src, dst, 1024**2); dst.flush(); os.fsync(dst.fileno())
    destination.chmod(0o600)


def copy_tree(source, destination, skip=None):
    no_links(source)
    if source.is_file():
        copy_file(source, destination); return
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    for directory, dirs, files in os.walk(source, followlinks=False):
        for name in dirs + files: no_links(Path(directory) / name)
        rel = Path(directory).relative_to(source)
        dirs[:] = [name for name in dirs if not skip or not skip((rel / name).as_posix(), True)]
        for name in dirs: (destination / rel / name).mkdir(mode=0o700, parents=True, exist_ok=True)
        for name in files:
            relative = (rel / name).as_posix()
            if not skip or not skip(relative, False): copy_file(Path(directory) / name, destination / relative)


class Recovery:
    def __init__(self, adapter, checkpoint=None):
        self.adapter = adapter; self.p = adapter.p; self.base = self.p['base']
        self.root = self.p['recovery']; self.ctl = Coordinator(self.base, adapter)
        self.checkpoint = checkpoint or (lambda point: None)

    def host(self):
        from ownership_guard import check as ownership_check
        ownership_check(self.base)
        value = load(self.root / 'host.json')
        require(value.get('schema') == 'philcst.recovery-host.v1' and value.get('installation') == identity(self.base)['installation'], 'Recovery host identity is missing or belongs to another installation')
        require(value.get('machine') == self.adapter.machine() and value.get('root') == sha(str(self.p['root']).encode()), 'Cross-machine/path recovery is D5D; transfer rejected')
        return value

    def ledger(self):
        value = validate_ledger(load(self.base / 'recovery-security.json'), identity(self.base))
        marker = security_state(self.p['targets']['database'])
        require((value['epoch'], value['active_set']) == (marker['epoch'], marker['active_set']), 'Protected recovery/database epoch mismatch; writers must remain fenced')
        return value

    def initialize(self, operation, resume=True):
        require(not (self.root / 'host.json').exists() and not (self.base / 'recovery-security.json').exists(), 'Recovery metadata already exists or is incomplete; never silently reinitialize it')
        if not resume and phase(self.base).get('operation')==operation:
            self.quiescent(operation)
        else:self.ctl.enter(operation)
        with self.ctl.owned(operation), barrier(self.base, 'control'):
            self.adapter.verify(phase(self.base)['adapter']); self.adapter.check()
            self.adapter.private(self.root); self.adapter.private(self.root / 'operations'); self.adapter.private(self.root / 'backups')
            value = initial_ledger(identity(self.base), self.p['targets']['database'])
            host = {'schema': 'philcst.recovery-host.v1', 'installation': identity(self.base)['installation'],
                    'machine': self.adapter.machine(), 'root': sha(str(self.p['root']).encode()), 'registration': str(uuid.uuid4())}
            journal = self.begin(operation, 'initialize', initial_security=value, initial_host=host)
            atomic(self.base / 'maintenance.json', dict(phase(self.base), recovery_required=True))
            atomic(self.base / 'recovery-security.json', value)
            self.adapter.permissions_for('security', self.base / 'recovery-security.json')
            atomic(self.root / 'host.json', host)
            self.save(journal, 'current_verified', requires_prepare=False)
        # This is explicit first registration only; no files/keys/DB were replaced.
        if resume: self.ctl.resume(operation)
        return {'initialized': True, 'installation': identity(self.base)['installation']}

    def operation_dir(self, operation):
        uuid.UUID(operation)
        return self.root / 'operations' / operation

    def journal(self, operation):
        value = load(self.operation_dir(operation) / 'journal.json')
        require(value.get('schema') == 'philcst.recovery-journal.v1' and value.get('operation') == operation
                and value.get('installation') == identity(self.base)['installation'], 'Recovery journal identity mismatch')
        return value

    def save(self, journal, state=None, **extra):
        value = dict(journal, **extra)
        if state: value['state'] = state
        path = self.operation_dir(value['operation']) / 'journal.json'
        atomic(path, value)
        atomic(self.base / 'recovery-guard.json', {'schema': 1, 'operation': value['operation'],
            'status': 'validated' if value['state'] in VERIFIED else 'blocked',
            'journal_sha256': file_sha(path), 'requires_prepare': value.get('requires_prepare', False)})
        self.adapter.permissions_for('security', self.base / 'recovery-guard.json')
        self.checkpoint('journal:' + value['state'])
        return value

    def begin(self, operation, kind, **intent):
        directory = self.operation_dir(operation)
        require(not directory.exists(), 'This recovery operation already has a journal; use explicit status/recover')
        self.adapter.private(directory)
        return self.save({'schema': 'philcst.recovery-journal.v1', 'operation': operation,
                          'installation': identity(self.base)['installation'], 'kind': kind, 'state': 'preparing',
                          'created_at': dt.datetime.now(dt.timezone.utc).isoformat(), 'requires_prepare': kind == 'restore', **intent})

    def quiescent(self, operation):
        state = phase(self.base)
        require(state['operation'] == operation and state['phase'] in ('quiescent', 'quiescent_pending', 'failed', 'fenced'), 'Enter and verify owned D5A maintenance before recovery mutation')
        require(not jobs(self.base), 'Unfinished producer work prevents a coherent recovery point')
        self.adapter.verify(state['adapter'])
        return state

    def refresh_security(self):
        value = self.ledger(); current = security_state(self.p['targets']['database'])
        value['lost_uids'] = sorted(set(value['lost_uids']) | set(current['lost_uids']))
        store = self.p['targets']['update_trust'] / 'trust.json'
        if store.exists():
            trust = load(store)
            value['update_generation'] = max(value['update_generation'], trust['generation'])
            value['revoked_update_keys'] = sorted(set(value['revoked_update_keys']) | {k for k, v in trust['keys'].items() if v['revoked']})
        atomic(self.base / 'recovery-security.json', value)
        self.adapter.permissions_for('security', self.base / 'recovery-security.json')
        return value

    def capture(self, destination, operation):
        """Caller holds owner+writer reservation, stopped collectors and Caddy."""
        require(not destination.exists(), 'Capture destination already exists')
        destination.mkdir(mode=0o700, parents=True)
        refs = set(media_references(self.p['targets']['database']))
        components = {}; payload = destination / 'payload'; payload.mkdir(mode=0o700)
        for role, source in self.p['targets'].items():
            no_links(source)
            present = source.exists()
            require(present or role not in REQUIRED, 'Required persistent component missing: ' + role)
            kind = 'directory' if (source.is_dir() if present else role in ('update_trust', 'setup')) else 'file'
            components[role] = {'present': present, 'kind': kind}
            if not present: continue
            target = payload / role
            if kind == 'file': target = target / 'data'
            if role == 'database':
                snapshot_sqlite(source, target)
            elif role in ('delivery', 'coordination'):
                target.mkdir(mode=0o700)
                name = 'outbox.sqlite' if role == 'delivery' else 'barrier.sqlite'
                require(set(p.name for p in source.iterdir()) <= {name, name + '-wal', name + '-shm', name + '-journal'}, 'Unknown persistent coordination/spool file needs review')
                snapshot_sqlite(source / name, target / name)
            elif role == 'storage':
                def skip(name, directory):
                    if name.split('/')[0] in ('logs', 'framework', 'backups'): return True
                    if name in ('app/go2rtc', 'app/ultralytics') or name.startswith(('app/go2rtc/', 'app/ultralytics/')): return True
                    if not directory and (name.startswith('app/public/') or name.startswith('app/private/occurrence-media/')):
                        # Keep nonimage exports; unreferenced images are not resurrected.
                        if Path(name).suffix.lower() in ('.jpg', '.jpeg', '.png', '.webp') and name not in refs: return True
                    return False
                copy_tree(source, target, skip)
            elif role == 'update_trust':
                copy_tree(source, target, lambda name, directory: name.split('/')[0] == 'cache')
            elif role == 'tls':
                copy_tree(source, target, lambda name, directory: name.split('/')[0] in ('logs', 'cache'))
            else:
                copy_tree(source, target)
            self.checkpoint('capture:' + role)
        validation = self.validate_payload(payload, components)
        files = {}
        for role, item in components.items():
            if item['present']:
                entries = inventory(payload / role)
                files.update({f'payload/{role}/' + name: value for name, value in entries.items()})
        value = {'schema': SCHEMA, 'product': PRODUCT, 'backup': str(uuid.uuid4()),
                 'created_at': dt.datetime.now(dt.timezone.utc).isoformat(), 'timezone': 'Asia/Manila',
                 'compatibility': self.adapter.source_binding(), 'installation': {'identity': identity(self.base), 'host_sha256': sha(canonical(self.host()))},
                 'maintenance': {k: v for k, v in phase(self.base).items() if k != 'secret_fingerprints'},
                 'components': components, 'files': files, 'validation': dict(validation, complete=True)}
        validate_manifest(value)
        return value

    def validate_trust(self, directory):
        if not directory.exists(): return None
        require((directory / 'trust.json').is_file() and (directory / 'state.json').is_file(), 'Incomplete protected software-update trust')
        trust = load(directory / 'trust.json'); state = load(directory / 'state.json')
        module = Path(__file__).with_name('update_contract.py')
        if not module.exists(): module = Path(__file__).resolve().parents[2] / 'build/updates/contract.py'
        spec = importlib.util.spec_from_file_location('trusted_update_contract', module)
        contract = importlib.util.module_from_spec(spec); spec.loader.exec_module(contract)
        if (directory/'control.json').exists():
            control=load(directory/'control.json')
            require(control.get('trust')==trust and control.get('state')==state, 'Protected updater catalog/mirrors disagree; repair from the authoritative controller before backup')
        contract.validate_trust(trust, fixture=self.adapter.fixture)
        contract.validate_state(state, fixture=self.adapter.fixture)
        expected = self.adapter.source_binding()
        require(state.get('version') == expected['version'] and state.get('platform') == expected['platform']
                and state.get('schema_id') == expected['schema_id'] and state.get('runtime_id') == expected['runtime_id'], 'Installed update compatibility state is inconsistent')
        require(state['source'] == {'commit': expected['commit'], 'sha256': expected['source_sha256'], 'build_identity': expected['build_identity']}, 'Installed update source identity is inconsistent')
        return trust

    def validate_payload(self, payload, components, expected=None):
        directories = {'storage', 'tls', 'update_trust', 'setup', 'delivery', 'coordination'}
        if not self.p['windows']: directories.add('configuration')
        for role, component in components.items():
            require(component['kind'] == ('directory' if role in directories else 'file'), 'Recovery component has the wrong platform-specific type')
        db = payload / 'database/data'; info = sqlite_info(db)
        actual_migrations = {row['migration'] for row in info['migrations']}
        source = load(self.p['release'] / 'BUILD-INFO.json')['source']['files']
        installed = {Path(name).stem for name in source if name.startswith('database/migrations/') and name.endswith('.php')}
        require(actual_migrations == installed, 'Unapproved schema/migration origin; D5C compatibility is required')
        if expected:
            require(info['schema_sha256'] == expected['database']['schema_sha256'] and info['migrations'] == expected['database']['migrations'], 'Backup database schema/ledger was changed')
            require(info['foreign_key_defects'] == expected['database']['foreign_key_defects'], 'New historical relationship corruption')
        for reference in media_references(db):
            require((payload / 'storage' / reference).is_file(), 'Required referenced historical/private media is missing')
        ident = load(payload / 'delivery_identity/data')
        require(ident == identity(self.base), 'Wrong installation/database generation; transfer refused')
        ledger = validate_ledger(load(payload / 'security/data'), ident)
        marker = security_state(db)
        require((marker['epoch'], marker['active_set']) == (ledger['epoch'], ledger['active_set']), 'Backup database/security epoch mismatch')
        with database(payload / 'coordination/barrier.sqlite') as coordination:
            require([r[0] for r in coordination.execute('PRAGMA integrity_check')] == ['ok'] and coordination.execute('SELECT COUNT(*) FROM producer_jobs').fetchone()[0] == 0, 'Outstanding producer/coordination state is not a recovery point')
        delivery = validate_delivery(db, payload / 'delivery/outbox.sqlite', ident)
        fingerprints = self.adapter.environment_binding(payload / 'environment/data')
        trust = self.validate_trust(payload / 'update_trust')
        if trust:
            require(trust['generation'] >= ledger['update_generation'] and all(k not in trust['keys'] or trust['keys'][k]['revoked'] for k in ledger['revoked_update_keys']), 'Protected update revocation/generation rollback')
        else:
            require(ledger['update_generation'] == 0 and not ledger['revoked_update_keys'], 'Previously provisioned update trust is missing')
        proof = self.adapter.crypto_check(payload)
        return {'database': info, 'delivery': delivery, 'secret_fingerprints': fingerprints,
                'media_references': len(media_references(db)), 'crypto': proof, 'update_trust': 'provisioned' if trust else 'not_provisioned'}

    def publish(self, capture, manifest, output):
        output = Path(output)
        require(not output.resolve().is_relative_to(self.p['app'].resolve()), 'Backups must not be placed in application/public storage')
        self.adapter.private(output)
        # Output may be a separate ACL-capable USB/disk; reserve space there too.
        require(shutil.disk_usage(output).free >= sum(v['size'] for v in manifest['files'].values()) + 64 * 1024**2, 'Insufficient backup destination space')
        incomplete = output / ('.incomplete-' + manifest['backup']); self.adapter.private(incomplete)
        archive_path = incomplete / 'backup.zip'
        with zipfile.ZipFile(archive_path, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
            for name in sorted(manifest['files']): archive.write(capture / name, name)
            archive.writestr('BACKUP.json', canonical(manifest))
        archive_path.chmod(0o600)
        with archive_path.open('rb') as stream: os.fsync(stream.fileno())
        atomic(incomplete / 'receipt.json', {'schema': 'philcst.backup-receipt.v1', 'backup': manifest['backup'],
            'archive_sha256': file_sha(archive_path), 'archive_size': archive_path.stat().st_size,
            'manifest_sha256': sha(canonical(manifest)), 'complete': True})
        inspect_bundle(incomplete)
        self.checkpoint('backup:before_publish')
        final = output / manifest['backup']; require(not final.exists(), 'Completed backup destination already exists')
        sync_dir(incomplete); os.rename(incomplete, final); sync_dir(output)
        self.checkpoint('backup:published')
        return final

    def backup(self, operation, output=None, resume=True):
        self.host(); self.ledger()
        if phase(self.base)['phase'] == 'running': self.ctl.enter(operation)
        with self.ctl.owned(operation), barrier(self.base, 'control'):
            self.quiescent(operation); self.adapter.check_configuration()
            journal = self.begin(operation, 'backup')
            try:
                self.refresh_security(); self.adapter.free(2 * sum(x['size'] for path in self.p['targets'].values() for x in (inventory(path) or {}).values()))
                directory = self.operation_dir(operation) / 'capture'
                manifest = self.capture(directory, operation)
                completed = self.publish(directory, manifest, output or self.root / 'backups')
                # Never overwrite a valid earlier recovery set; publication is a new directory.
                journal = self.save(journal, 'backup_verified', backup=str(completed), requires_prepare=False)
            except BaseException as error:
                self.save(journal, 'failed_backup', error=safe_error(error) + ' Incomplete backup; previous completed sets remain. Validate current state before explicit resume.')
                raise
        if resume: self.ctl.resume(operation)
        return {'backup': str(completed), 'recovery_point': manifest['created_at'], 'pending_deliveries': manifest['validation']['delivery']['pending'], 'consistent': True}

    @contextmanager
    def scratch(self):
        # Administrator-only temporary directory is protected before incoming bytes.
        with tempfile.TemporaryDirectory(prefix='inspect-', dir=self.root) as name:
            directory = Path(name).resolve(); self.adapter.private(directory)
            yield directory

    def plan(self, archive):
        self.host(); ledger = self.ledger()
        with self.scratch() as temp:
            manifest = inspect_bundle(archive)
            self.adapter.free(sum(item['size'] for item in manifest['files'].values()) * 4)
            inspect_bundle(archive, temp / 'incoming')
            require(manifest['compatibility'] == self.adapter.source_binding(), 'Unsupported source/schema/runtime/platform origin; D5C/D5D required')
            require(manifest['installation'] == {'identity': identity(self.base), 'host_sha256': sha(canonical(self.host()))}, 'Backup belongs to another installation/machine')
            proof = self.validate_payload(temp / 'incoming/payload', manifest['components'], manifest['validation'])
            require(proof['crypto']['tls_root_sha256'] == load(self.p['targets']['tls_identity'])['sha256'], 'TLS root rotation cannot be undone by a data restore')
            require(proof['secret_fingerprints']['APP_KEY'] == self.adapter.environment_binding(self.p['targets']['environment'])['APP_KEY'], 'Encryption-key rotation needs a separately reviewed recovery plan')
            current_info = sqlite_info(self.p['targets']['database'])
            require(current_info['schema_sha256'] == proof['database']['schema_sha256'], 'Active and backup schema origins differ')
            current_trust = self.p['targets']['update_trust']
            if manifest['components']['update_trust']['present']: require(current_trust.is_dir(), 'Cannot bootstrap missing update trust from incoming data')
            self.validate_trust(current_trust)
            current = {role: tree_hash(path) for role, path in self.p['targets'].items() if role != 'coordination'}
            snapshot_sqlite(self.p['targets']['database'], temp / 'current.sqlite')
            snapshot_sqlite(self.p['targets']['delivery'] / 'outbox.sqlite', temp / 'outbox.sqlite')
            current['database'] = file_sha(temp / 'current.sqlite'); current['delivery'] = file_sha(temp / 'outbox.sqlite')
            archive_path = Path(archive) / 'backup.zip' if Path(archive).is_dir() else Path(archive)
            value = {'schema': 'philcst.restore-plan.v1', 'backup': manifest['backup'], 'archive_sha256': file_sha(archive_path),
                     'installation': identity(self.base)['installation'], 'compatibility': manifest['compatibility'],
                     'recovery_point': manifest['created_at'], 'current_state': sha(canonical(current)), 'security_epoch': ledger['epoch'],
                     'known_relationship_defects': proof['database']['foreign_key_defects'],
                     'later_data_policy': 'Later activity is replaced, not merged. A verified current recovery set and retained raw files are preserved.',
                     'security_policy': 'Latest account security, permanent UID denies, trust revocations and epoch survive; sessions/codes invalidated.',
                     'writers_must_be_quiescent_for_final_approval': True}
            self.adapter.free(sum(item['size'] for item in manifest['files'].values()) * 4)
            return dict(value, confirmation=sha(canonical(value)))

    def protected_stage(self, source, destination):
        if source.exists(): copy_tree(source, destination)

    def prepare_sets(self, incoming, current, manifest, journal):
        target = incoming / 'payload'; rollback = current / 'payload'
        old_ledger = load(target / 'security/data'); current_ledger = self.ledger()
        ledger, target_id, rollback_id = union_ledger(current_ledger, old_ledger,
            rollback / 'database/data', target / 'database/data', rollback / 'delivery/outbox.sqlite', target / 'delivery/outbox.sqlite',
            self.p['targets']['update_trust'] / 'trust.json' if (self.p['targets']['update_trust'] / 'trust.json').exists() else None)
        # Keep current executable/native/environment/network/trust configuration,
        # never execute scripts or re-enroll roots supplied by a backup.
        for role in PRESERVE_CURRENT | {'coordination', 'delivery_identity'}:
            if (target / role).exists():
                if (target / role).is_dir(): shutil.rmtree(target / role)
                else: (target / role).unlink()
            if (rollback / role).exists(): copy_tree(rollback / role, target / role)
        for payload, set_id in ((target, target_id), (rollback, rollback_id)):
            reconcile_database(payload / 'database/data', rollback / 'database/data' if payload == target else self.p['targets']['database'], ledger, set_id)
            reset_leases(payload / 'delivery/outbox.sqlite')
            if (payload / 'setup').exists(): shutil.rmtree(payload / 'setup')
            if manifest['components']['setup']['present']: (payload / 'setup').mkdir(mode=0o700)
            for name in ('framework/sessions', 'framework/views', 'framework/cache/data', 'logs'):
                (payload / 'storage' / name).mkdir(mode=0o700, parents=True, exist_ok=True)
            trust_path = payload / 'update_trust/trust.json'
            if trust_path.exists():
                trust = load(trust_path)
                require(trust['generation'] >= ledger['update_generation'], 'Current trusted generation is older than protected state; no invented rotation is allowed')
                for key in ledger['revoked_update_keys']:
                    if key in trust['keys']: trust['keys'][key]['revoked'] = True
                atomic(trust_path, trust)
            staged_ledger = dict(ledger, active_set=set_id)
            atomic(payload / 'security/data', staged_ledger)
            components = {role: {'present': (payload / role).exists(), 'kind': manifest['components'][role]['kind']} for role in ROLES}
            proof = self.validate_payload(payload, components)
            allowed_defects = sqlite_info(self.p['targets']['database'])['foreign_key_defects'] if payload == rollback else manifest['validation']['database']['foreign_key_defects']
            require(proof['database']['foreign_key_defects'] == allowed_defects, 'Security reconciliation introduced a relationship defect; no guessed repair permitted')
            for role in ROLES - CAPTURE_ONLY:
                path = payload / role
                if components[role]['kind'] == 'file': path = path / 'data'
                self.adapter.permissions_for(role, path)
        # Write monotonic state before any active data promotion. Active set stays
        # current until all target components are installed and verified.
        ledger['active_set'] = current_ledger['active_set']
        # Epoch mismatch deliberately closes admission during the multi-file operation.
        moves = []
        for role in sorted(ROLES - CAPTURE_ONLY):
            old = self.p['targets'][role]
            prepared = target / role; back = rollback / role
            if manifest['components'][role]['kind'] == 'file': prepared = prepared / 'data'; back = back / 'data'
            moves.append({'role': role, 'old_hash': tree_hash(old), 'new_hash': tree_hash(prepared), 'rollback_hash': tree_hash(back), 'step': 'prepared', 'rollback_step': 'prepared'})
        for suffix in ('-wal', '-shm', '-journal'):
            path = Path(str(self.p['targets']['database']) + suffix)
            moves.append({'role': 'database' + suffix, 'old_hash': tree_hash(path), 'new_hash': None, 'rollback_hash': None, 'step': 'prepared', 'rollback_step': 'prepared'})
        durable_tree(target); durable_tree(rollback)
        journal = self.save(journal, 'prepared', moves=moves, target_set=target_id, rollback_set=rollback_id, epoch=ledger['epoch'],
                            security_before_sha256=sha(canonical(current_ledger)), security_after=ledger,
                            target_components={role: {'present': (target / role).exists(), 'kind': manifest['components'][role]['kind']} for role in ROLES})
        atomic(self.base / 'recovery-security.json', ledger)
        self.adapter.permissions_for('security', self.base / 'recovery-security.json')
        return journal

    def move_paths(self, operation, move, rollback=False):
        role = move['role']; directory = self.operation_dir(operation)
        if role.startswith('database-'):
            return Path(str(self.p['targets']['database']) + role[len('database'):]), None, directory / ('discarded/' if rollback else 'raw/') / role
        destination = self.p['targets'][role]
        prepared = directory / ('current/payload/' if rollback else 'incoming/payload/') / role
        if self.journal(operation)['target_components'][role]['kind'] == 'file': prepared = prepared / 'data'
        return destination, prepared, directory / ('discarded/' if rollback else 'raw/') / role

    def promote(self, journal, rollback=False):
        ledger = load(self.base / 'recovery-security.json')
        require(sha(canonical(ledger)) == journal['security_before_sha256'] or (ledger['epoch'] == journal['epoch']
                and ledger['lost_uids'] == journal['security_after']['lost_uids']
                and ledger['revoked_update_keys'] == journal['security_after']['revoked_update_keys']), 'Recovery security intent disagrees with protected state')
        if ledger['epoch'] != journal['epoch']:
            atomic(self.base / 'recovery-security.json', journal['security_after'])
            self.adapter.permissions_for('security', self.base / 'recovery-security.json')
        step_key = 'rollback_step' if rollback else 'step'; desired_key = 'rollback_hash' if rollback else 'new_hash'
        journal = self.save(journal, 'rolling_back' if rollback else 'promoting')
        for index, move in enumerate(journal['moves']):
            target, prepared, old = self.move_paths(journal['operation'], move, rollback)
            self.checkpoint(('rollback:component:' if rollback else 'promote:component:') + move['role'])
            desired = move[desired_key]; actual = tree_hash(target)
            if actual == desired:
                if move['role'] in ROLES: self.adapter.permissions_for(move['role'], target)
                move[step_key] = 'installed'; journal = self.save(journal); continue
            allowed = {move['old_hash'], move['new_hash']} if rollback else {move['old_hash']}
            require(actual in allowed or actual is None, 'Unexpected active component bytes; recovery cannot guess')
            require(desired is None or (prepared is not None and tree_hash(prepared) == desired), 'Verified staged component is missing/corrupt')
            if target.exists():
                require(not old.exists(), 'Ambiguous retained old component; explicit recovery required')
                old.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                journal = self.save(journal, **{'intent': ('rollback:' if rollback else 'promote:') + move['role']})
                os.rename(target, old); sync_dir(target.parent); sync_dir(old.parent)
                self.checkpoint(('rollback:old_moved:' if rollback else 'promote:old_moved:') + move['role'])
            elif move[step_key] != 'installed' and move['old_hash'] is not None and not rollback:
                require(tree_hash(old) == move['old_hash'], 'Missing verified prior component')
            move[step_key] = 'old_moved'; journal = self.save(journal)
            if desired is not None:
                target.parent.mkdir(parents=True, exist_ok=True); os.rename(prepared, target); sync_dir(target.parent)
                self.checkpoint(('rollback:new_installed:' if rollback else 'promote:new_installed:') + move['role'])
            if move['role'] in ROLES: self.adapter.permissions_for(move['role'], target)
            move[step_key] = 'installed'; journal = self.save(journal)
        ledger = load(self.base / 'recovery-security.json')
        ledger['active_set'] = journal['rollback_set'] if rollback else journal['target_set']
        atomic(self.base / 'recovery-security.json', ledger)
        self.adapter.permissions_for('security', self.base / 'recovery-security.json')
        journal = self.save(journal, 'rollback_installed' if rollback else 'installed')
        self.verify_active(journal, rollback)
        return self.save(journal, 'rollback_verified' if rollback else 'promoted_verified')

    def verify_active(self, journal, rollback=False):
        for move in journal['moves']:
            target, _, _ = self.move_paths(journal['operation'], move, rollback)
            require(tree_hash(target) == move['rollback_hash' if rollback else 'new_hash'], 'Active recovery component failed final hash validation: ' + move['role'])
            if move['role'] in ROLES: self.adapter.permissions_for(move['role'], target, verify=True)
        self.ledger(); self.adapter.check_configuration()
        # Full data/key/media/receipt proof, using another private coherent capture.
        proof_path = self.operation_dir(journal['operation']) / ('proof-rollback' if rollback else 'proof-forward')
        if proof_path.exists(): shutil.rmtree(proof_path)
        self.capture(proof_path, journal['operation'])

    def restore(self, operation, archive, confirmation, accept_loss=False, acknowledge_defects=False):
        require(accept_loss, 'Explicit acceptance of loss of later data is required')
        approved = self.plan(archive); require(confirmation == approved['confirmation'], 'Recovery plan is stale or not explicitly confirmed')
        with self.ctl.owned(operation), barrier(self.base, 'control'):
            self.quiescent(operation); self.adapter.check_configuration()
            require(self.plan(archive)['confirmation'] == confirmation, 'Recovery point changed after approval; obtain a new plan while fenced')
            require(not approved['known_relationship_defects'] or acknowledge_defects, 'Acknowledge the listed pre-existing relationship defects explicitly; no repair is inferred')
            journal = self.begin(operation, 'restore')
            try:
                manifest = inspect_bundle(archive, self.operation_dir(operation) / 'incoming')
                current_path = self.operation_dir(operation) / 'current'
                self.refresh_security(); current_manifest = self.capture(current_path, operation)
                safety = self.publish(current_path, current_manifest, self.root / 'backups')
                journal = self.save(journal, 'safety_verified', safety_backup=str(safety), backup=manifest['backup'])
                journal = self.prepare_sets(self.operation_dir(operation) / 'incoming', current_path, manifest, journal)
                journal = self.promote(journal)
            except BaseException as error:
                # Never unconditionally restart, infer success or discard rollback material.
                current = self.journal(operation)
                self.save(current, error=safe_error(error) + ' Recovery interrupted/failed. Writers remain fenced; inspect and choose verified forward or rollback recovery.')
                raise
        return {'operation': operation, 'state': journal['state'], 'writers_reopened': False, 'acceptance_required': True, 'safety_backup': journal['safety_backup']}

    def recover(self, operation, decision):
        initial = self.journal(operation)
        require(initial['kind'] != 'update' or type(self) is not Recovery, 'Use the retained D5C controller for program/runtime/data recovery')
        if initial['kind'] != 'initialize': self.host()
        with self.ctl.owned(operation), barrier(self.base, 'control'):
            self.quiescent(operation); journal = self.journal(operation)
            require(journal['state'] not in ('accepted', 'rollback_accepted'), 'An accepted set may contain newer activity; do not roll it back implicitly')
            if decision == 'current':
                require(journal['kind'] == 'backup' or 'moves' not in journal, 'Current bytes may be partially promoted; choose verified forward/rollback')
                if journal['kind'] == 'initialize':
                    expected = journal['initial_host']
                    require(expected['machine'] == self.adapter.machine() and expected['root'] == sha(str(self.p['root']).encode()), 'Interrupted initialization belongs to another host')
                    require(initial_ledger(identity(self.base), self.p['targets']['database']) == journal['initial_security'], 'Initialization source state changed')
                    for path, value in ((self.root / 'host.json', expected), (self.base / 'recovery-security.json', journal['initial_security'])):
                        require(not path.exists() or load(path) == value, 'Conflicting initialization receipt; no replacement permitted')
                        atomic(path, value)
                    self.adapter.permissions_for('security', self.base / 'recovery-security.json')
                    atomic(self.base / 'maintenance.json', dict(phase(self.base), recovery_required=True))
                proof = self.operation_dir(operation) / 'proof-current'
                if proof.exists(): shutil.rmtree(proof)
                self.capture(proof, operation); self.adapter.check_configuration()
                journal = self.save(journal, 'current_verified', requires_prepare=False)
            else:
                require('moves' in journal and (decision == 'rollback' or journal['state'] not in ('rolling_back', 'rollback_installed', 'rollback_verified')), 'No verified promotion/rollback plan is available')
                journal = self.promote(journal, decision == 'rollback')
        return {'operation': operation, 'state': journal['state'], 'writers_reopened': False}

    def accept(self, operation):
        require(self.journal(operation)['kind'] != 'update' or type(self) is not Recovery, 'Use the retained D5C controller for update acceptance')
        with self.ctl.owned(operation), barrier(self.base, 'control'):
            self.quiescent(operation); journal = self.journal(operation)
            require(journal['state'] in ('promoted_verified', 'rollback_verified'), 'No fully verified recovery set awaits acceptance')
            rollback = journal['state'] == 'rollback_verified'; self.verify_active(journal, rollback)
            self.save(journal, 'rollback_accepted' if rollback else 'accepted')
        return {'accepted': True, 'writers_reopened': False, 'rollback_retained': True}

    def resume(self, operation):
        journal = self.journal(operation)
        require(journal['kind'] != 'update' or type(self) is not Recovery, 'Use the retained D5C controller for update resume')
        require(journal['state'] in VERIFIED, 'Recovery is not verified/accepted; maintenance must remain')
        def prepare():
            self.adapter.prepare(); self.adapter.check_configuration()
        # Mark preparation complete before reopening; a failed resume remains fenced.
        def prepare_and_receipt():
            prepare()
            self.save(self.journal(operation), requires_prepare=False)
        self.ctl.resume(operation, prepare=prepare_and_receipt if journal.get('requires_prepare') else None)
        return {'running': phase(self.base)['phase'] == 'running', 'operation': operation}

    def status(self):
        value = {'maintenance': self.ctl.status(), 'recovery_initialized': (self.root / 'host.json').is_file()}
        guard = self.base / 'recovery-guard.json'
        if guard.exists():
            value['recovery_guard'] = load(guard)
            journal = self.journal(value['recovery_guard']['operation'])
            value['recovery'] = {k: journal.get(k) for k in ('operation', 'kind', 'state', 'backup', 'safety_backup', 'error')}
        return value


def main():
    parser = argparse.ArgumentParser(description='Installed D5B same-machine backup/recovery; no update or transfer')
    parser.add_argument('action', choices=['initialize', 'status', 'backup', 'inspect', 'plan', 'restore', 'recover', 'accept', 'resume'])
    parser.add_argument('--root', type=Path, required=True); parser.add_argument('--operation'); parser.add_argument('--archive', type=Path)
    parser.add_argument('--output', type=Path); parser.add_argument('--confirm-plan'); parser.add_argument('--accept-later-data-loss', action='store_true')
    parser.add_argument('--acknowledge-known-defects', action='store_true'); parser.add_argument('--decision', choices=['current', 'forward', 'rollback'])
    parser.add_argument('--scheduled', action='store_true')
    args = parser.parse_args(); adapter = installed(args.root); recovery = Recovery(adapter)
    if args.scheduled:
        require(args.action == 'backup', 'Only coherent backup supports unattended scheduling')
        os.environ['PHILCST_RECOVERY_SCHEDULED'] = '1'
    if args.action == 'status': result = recovery.status()
    elif args.action == 'initialize': result = recovery.initialize(args.operation or str(uuid.uuid4()))
    elif args.action == 'backup': result = recovery.backup(args.operation or str(uuid.uuid4()), args.output)
    elif args.action in ('inspect', 'plan'):
        require(args.archive is not None, 'Choose a complete backup directory or its backup.zip')
        result = recovery.plan(args.archive)
    elif args.action == 'restore':
        require(args.archive is not None, 'Choose a complete backup')
        result = recovery.restore(args.operation, args.archive, args.confirm_plan, args.accept_later_data_loss, args.acknowledge_known_defects)
    elif args.action == 'recover': result = recovery.recover(args.operation, args.decision)
    elif args.action == 'accept': result = recovery.accept(args.operation)
    else: result = recovery.resume(args.operation)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('PHILCST recovery refused: ' + safe_error(error) + ' No automatic restart; use status and the reviewed recovery instructions.', file=sys.stderr)
        sys.exit(1)
