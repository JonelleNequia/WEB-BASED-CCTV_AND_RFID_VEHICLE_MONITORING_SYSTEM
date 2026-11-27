import json
from pathlib import Path
import unittest
import uuid
from fixture import Installation
from backup_contract import database, inspect_bundle, load
from coordination import phase
from service_host import allowed


class RecoveryWorkflow(unittest.TestCase):
    def setUp(self): self.installation = Installation(); self.addCleanup(self.installation.close)

    def test_complete_backup_restore_pending_delivery_and_same_canonical_movement(self):
        f = self.installation; operation_id = f.pending(); original = f.php()
        backup = f.backup(); manifest = inspect_bundle(backup)
        self.assertTrue(manifest['validation']['complete']); self.assertEqual(1, manifest['validation']['delivery']['pending'])
        with database(f.p['targets']['database'], write=True) as db: db.execute("UPDATE vehicles SET vehicle_owner_name='Later Owner'")
        operation = f.restore(backup)
        self.assertEqual('promoted_verified', f.recovery.journal(operation)['state'])
        self.assertFalse(allowed(f.p['base'], 'detector'))
        f.recovery.accept(operation); f.recovery.resume(operation)
        self.assertTrue(allowed(f.p['base'], 'detector'))
        with database(f.p['targets']['database']) as db:
            self.assertEqual('Fixture Owner', db.execute('SELECT vehicle_owner_name FROM vehicles').fetchone()[0])
        row = f.box.inspect(operation_id); self.assertEqual(operation_id, row['operation'])
        self.assertEqual(original['entries'], f.php()['entries'])
        self.assertEqual(original['events'], f.php()['events'])
        self.assertEqual('running', phase(f.p['base'])['phase'])

    def test_windows_layout_uses_the_same_data_contract(self):
        other = Installation(windows=True)
        try:
            backup = other.backup(); operation = other.restore(backup)
            other.recovery.accept(operation); other.recovery.resume(operation)
            self.assertTrue(allowed(other.p['base'], 'web'))
        finally: other.close()


if __name__ == '__main__': unittest.main()
