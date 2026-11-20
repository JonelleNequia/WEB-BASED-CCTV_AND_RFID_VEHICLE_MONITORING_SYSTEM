"""Small startup/admission receipt, readable without granting access to backups.
Only the privileged controller verifies the private operation journal itself.
"""
import hashlib
import json
from pathlib import Path
import uuid


def check(base, verify_journal=False, operation=None, has_prepare=False):
    base = Path(base)
    state = json.loads((base / 'maintenance.json').read_text())
    guard_path = base / 'recovery-guard.json'; security = base / 'recovery-security.json'
    required = state.get('recovery_required') or guard_path.exists() or security.exists()
    if not required:
        return
    if not guard_path.is_file() or not security.is_file():
        raise ValueError('Protected recovery state is missing; do not reopen writers')
    guard = json.loads(guard_path.read_text()); ledger = json.loads(security.read_text())
    if guard.get('schema') != 1 or guard.get('status') != 'validated' or ledger.get('schema') != 'philcst.recovery-security.v1':
        raise ValueError('Incomplete recovery journal; writers remain fenced')
    uuid.UUID(guard['operation']); uuid.UUID(ledger['active_set'])
    if verify_journal:
        journal_file = base.parent / 'recovery/operations' / guard['operation'] / 'journal.json'
        raw = journal_file.read_bytes(); journal = json.loads(raw)
        if hashlib.sha256(raw).hexdigest() != guard.get('journal_sha256') or journal.get('state') not in ('backup_verified', 'accepted', 'rollback_accepted', 'current_verified'):
            raise ValueError('Verified recovery receipt does not bind the protected journal')
        if guard.get('requires_prepare') and operation is not None and guard['operation'] != operation:
            raise ValueError('Recovery resume requires the exact accepted operation')
        if guard.get('requires_prepare') and not has_prepare:
            raise ValueError('Use D5B recovery resume to rebuild trusted configuration before reopening')
