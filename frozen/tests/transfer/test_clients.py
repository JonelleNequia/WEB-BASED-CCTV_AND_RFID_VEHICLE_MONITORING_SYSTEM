import sys
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'deploy/network'))
from policy import hosts_plan,dns_matches

class ClientHandoff(unittest.TestCase):
    def test_only_owned_old_mapping_moves_and_unrelated_lines_survive(self):
        source='127.0.0.1 localhost\r\n192.168.44.3 printer.school\r\n'
        old=hosts_plan(source,'192.168.44.10');new=hosts_plan(old,'192.168.44.20')
        self.assertIn('192.168.44.3 printer.school\r\n',new);self.assertNotIn('192.168.44.10',new);self.assertIn('192.168.44.20\tphilcst.vms.internal',new)
        self.assertEqual(new,hosts_plan(new,'192.168.44.20'))
    def test_conflicting_dns_hosts_ipv6_and_tampered_blocks_refuse(self):
        for text in ('192.168.44.10 philcst.vms.internal\n','::1 philcst.vms.internal\n','# BEGIN PHILCST-VMS-D4\n192.168.44.4 other\n# END PHILCST-VMS-D4\n'):
            with self.assertRaises(ValueError):hosts_plan(text,'192.168.44.20')
        for values in (['192.168.44.10'],['192.168.44.20','::1'],[]):
            with self.assertRaises(ValueError):dns_matches(values,'192.168.44.20')
        dns_matches(['192.168.44.20'],'192.168.44.20')
    def test_helpers_reuse_pinned_existing_connection_controls_and_require_isolation(self):
        win=(ROOT/'deploy/clients/Reconnect-to-PHILCST.ps1').read_text();mac=(ROOT/'deploy/clients/Reconnect-to-PHILCST.command').read_text()
        self.assertIn('OldServerIsolated',win);self.assertIn('--old-server-isolated',mac)
        self.assertIn('Connect-to-PHILCST.ps1',win);self.assertIn('Connect-to-PHILCST.command',mac)
        for value in (win,mac):
            self.assertNotIn('SkipCertificateCheck',value);self.assertNotIn('curl -k',value);self.assertNotIn('Remove-Item',value)
        self.assertIn('old.address -ne $OldServerAddress',win);self.assertIn('s/^ADDRESS=',mac)

if __name__=='__main__':unittest.main()
