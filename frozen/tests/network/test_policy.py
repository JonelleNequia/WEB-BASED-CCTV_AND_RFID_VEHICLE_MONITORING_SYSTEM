import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import shutil

ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'deploy/network'))
import policy as p
import server as server

LAN={'schema':1,'mode':'lan','address':'192.168.44.10','interface':'en0','remote_scope':'192.168.44.0/24'}

class NetworkPolicy(unittest.TestCase):
    def test_windows_fresh_preflight_uses_verified_payload_adapter_before_copy(self):
        with tempfile.TemporaryDirectory() as directory:
            destination=Path(directory)/'not-installed-yet'
            adapter=server.Native({'root':destination,'release':destination})
            with patch.object(server,'run') as invoke:
                invoke.return_value.stdout='{"interfaces":[]}'
                self.assertEqual(adapter.win('Inspect'),{'interfaces':[]})
                args=invoke.call_args.args[0]
                self.assertEqual(args[args.index('-File')+1],ROOT/'deploy/windows/network-adapter.ps1')
                self.assertFalse(destination.exists())

    def test_public_handoff_must_match_persistent_issuer_before_export_or_trust(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory);issuer=base/'tls/pki/authorities/local/root.crt'
            issuer.parent.mkdir(parents=True);issuer.write_bytes(b'fixture-original-public-root')
            public=base/'public-root.crt';public.write_bytes(issuer.read_bytes())
            with patch.object(server,'certificate',return_value={'sha256':'synthetic'}):
                self.assertEqual(server.public_certificate({'tls':base/'tls','public_root':public}),{'sha256':'synthetic'})
                public.write_bytes(b'fixture-wrong-public-root')
                with self.assertRaisesRegex(ValueError,'handoff differs'):
                    server.public_certificate({'tls':base/'tls','public_root':public})
            self.assertEqual(issuer.read_bytes(),b'fixture-original-public-root')

    def test_modes_require_specific_interface_and_private_scope(self):
        self.assertEqual(p.validate(dict(p.DEFAULT)),p.DEFAULT)
        self.assertEqual(p.validate(LAN,[{'name':'en0','address':'192.168.44.10','prefix':24}]),LAN)
        for change in [{'address':'0.0.0.0'},{'address':'8.8.8.8'},{'address':'::1'},{'address':'192.168.44.0'},{'remote_scope':'0.0.0.0/0'},{'remote_scope':'192.168.43.0/24'},{'interface':'en0;echo bad'}]:
            with self.assertRaises(ValueError):p.validate(LAN|change)
        with self.assertRaises(ValueError):p.validate(LAN,[])
        with self.assertRaises(ValueError):p.validate(LAN,[{'name':'en0','address':LAN['address'],'prefix':28}])
        with self.assertRaises(ValueError):p.validate(dict(p.DEFAULT,address=LAN['address']))
    def test_hosts_idempotence_preservation_and_removal(self):
        original='# local\n127.0.0.1 localhost alias\n::1 localhost\n192.168.1.9 school.internal\n'
        result=p.hosts_plan(original,'127.0.0.1');self.assertEqual(p.hosts_plan(result,'127.0.0.1'),result)
        switched=p.hosts_plan(result,LAN['address']);self.assertIn(LAN['address']+'\t'+p.HOST,switched)
        self.assertEqual(p.hosts_plan(switched),original)
        crlf=original.replace('\n','\r\n');self.assertEqual(p.hosts_plan(p.hosts_plan(crlf,'127.0.0.1')),crlf)
    def test_unowned_aliases_ipv6_duplicate_and_tampered_blocks_fail(self):
        for text in ['127.0.0.1 '+p.HOST+'\n','::1 localhost '+p.HOST.upper()+'.\n',p.BEGIN+'\n10.0.0.1 unrelated\n'+p.END+'\n',p.BEGIN+'\n',p.END+'\n',p.BEGIN+'\n'+p.BEGIN+'\n']:
            with self.assertRaises(ValueError):p.hosts_plan(text,'127.0.0.1')
        self.assertEqual(p.hosts_plan('::1 '+p.HOST+'\n'),'::1 '+p.HOST+'\n')
    def test_dns_refuses_conflicting_ipv6_and_wrong_server(self):
        p.dns_matches([LAN['address']],LAN['address'])
        for addresses in [[],['::1'],[LAN['address'],'fd00::2'],['192.168.44.11']]:
            with self.assertRaises(ValueError):p.dns_matches(addresses,LAN['address'])
    def test_env_preserves_secrets_accounts_device_settings_and_independent_urls(self):
        original='APP_KEY=base64:fixture\r\nDETECTOR_API_KEY=fixture-key\r\nCAMERA_SETTING=untouched\r\nAPP_URL=http://127.0.0.1:8080\r\n'
        updated=p.env_plan(original,LAN)
        for line in ['APP_KEY=base64:fixture\r\n','DETECTOR_API_KEY=fixture-key\r\n','CAMERA_SETTING=untouched\r\n']:self.assertIn(line,updated)
        self.assertIn('APP_URL='+p.URL,updated);self.assertIn('PYTHON_INTEGRATION_URL=http://127.0.0.1:8080',updated)
        self.assertIn('SESSION_SECURE_COOKIE=true',updated);self.assertIn('GO2RTC_WEBRTC_HOST='+LAN['address'],updated)
        self.assertEqual(p.env_plan(updated,LAN),updated)
    def test_generation_keeps_internal_services_loopback_and_removes_lan_scope(self):
        template=(ROOT/'build/macos/templates/Caddyfile.template').read_text()
        local=p.caddy_config(template,'/fixture/app/public','/fixture/shared/web/tls','/fixture/shared/web/logs/access.log',dict(p.DEFAULT))
        lan=p.caddy_config(template,'/fixture/app/public','/fixture/shared/web/tls','/fixture/shared/web/logs/access.log',LAN)
        self.assertIn('bind 127.0.0.1\n',local);self.assertNotIn(LAN['address'],local)
        self.assertIn('bind 127.0.0.1 '+LAN['address'],lan);self.assertIn('remote_ip 127.0.0.1/32 '+LAN['remote_scope'],lan)
        for text in [local,lan]:
            for item in ['admin off','skip_install_trust','tls internal','auto_https disable_redirects','protocols h1 h2','http://127.0.0.1:8080','stream_timeout 30s','@protected path_regexp']:self.assertIn(item,text)
            self.assertNotIn('0.0.0.0',text);self.assertNotIn('trusted_proxies',text)
        pinned=p.caddy_config(template,'/app/public','/private/tls','/logs/access.log',dict(p.DEFAULT),windows=True,pinned=True)
        self.assertIn('root.key',pinned);self.assertIn('127.0.0.1:9004',pinned)
        with self.assertRaises(ValueError):p.caddy_config(template,'/path" bad','/tls','/log',dict(p.DEFAULT))
    def test_transaction_failure_restores_settings_and_owned_exposure(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);env=root/'.env';hosts=root/'hosts';created=root/'new-config';db=root/'database';db.write_bytes(b'fixture DB');env.write_bytes(b'APP_KEY=old');hosts.write_bytes(b'unrelated')
            before={x:x.read_bytes() for x in (env,hosts,db)};calls=[]
            def failure():raise ValueError('fixture restart failure')
            with self.assertRaises(ValueError):p.transaction({env:'new',hosts:'newhosts',created:'new'},lambda:calls.append('apply'),lambda:calls.append('rollback'),failure)
            self.assertEqual(before,{x:x.read_bytes() for x in before});self.assertFalse(created.exists());self.assertEqual(calls,['apply','rollback'])
    def test_transaction_success_writes_only_explicit_config_targets(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/'config';p.transaction({path:'approved'},lambda:None,lambda:self.fail('unexpected rollback'),lambda:None)
            self.assertEqual(path.read_text(),'approved')

    def test_installer_preflight_conflict_creates_no_state_or_certificate(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory).resolve();hosts=base/'hosts';hosts.write_text('::1 '+p.HOST+'\n')
            paths={'shared':base/'not-created/shared','web':base/'not-created/shared/web','state':base/'not-created/shared/network.json','identity':base/'not-created/shared/identity.json','hosts':hosts}
            class ReadOnly:
                def inspect(self):return {'interfaces':[]}
                def exposure_plan(self,*args):raise AssertionError('Hosts conflict must be rejected first')
            with self.assertRaisesRegex(ValueError,'unowned'):server.preflight_configuration(paths,dict(p.DEFAULT),ReadOnly())
            self.assertFalse((base/'not-created').exists());self.assertEqual(hosts.read_text(),'::1 '+p.HOST+'\n')
    def test_mac_service_identity_and_state_layout(self):
        spec=importlib.util.spec_from_file_location('maccontrol',ROOT/'deploy/macos/control.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
        shared=Path('/fixture/shared');release=Path('/fixture/releases/1.0')
        self.assertEqual(m.service_plist(Path('/fixture'),release,shared,'web')['UserName'],'root')
        self.assertEqual(m.service_plist(Path('/fixture'),release,shared,'php')['UserName'],'_philcstvms')
        self.assertEqual(m.runtime_env(release,shared)['MJPEG_STREAM_BIND_HOST'],'127.0.0.1')
        web=m.service_plist(Path('/fixture'),release,shared,'web')
        self.assertEqual(web['EnvironmentVariables']['HOME'],str(shared/'web'))
        self.assertEqual(web['EnvironmentVariables']['XDG_CONFIG_HOME'],str(shared/'web/config'))
        self.assertEqual(web['StandardErrorPath'],str(shared/'web/logs/launchd.log'))
        runner=(ROOT/'deploy/macos/service_runner.py').read_text()
        self.assertIn("shared/'web/logs/supervisor.log'",runner)
    def test_windows_firewall_contract_is_owned_narrow_and_contains_no_internal_allow(self):
        text=(ROOT/'deploy/windows/network-adapter.ps1').read_text()
        for token in ['-InterfaceAlias $rule.interface','-LocalAddress $rule.local_address','-RemoteAddress $rule.remote_scope','-Profile $rule.profiles','Assert-OwnedRules','NT SERVICE']: 
            if token!='NT SERVICE':self.assertIn(token,text)
        rules=(ROOT/'deploy/windows/lib/network-policy.ps1').read_text()
        self.assertIn("'TCP',443",rules);self.assertIn("'UDP',8555",rules)
        self.assertNotIn('Set-NetFirewallProfile',text);self.assertNotIn('Set-NetConnectionProfile',text)
    def test_client_helpers_do_not_install_collectors_or_bypass_tls(self):
        for name in ['Connect-to-PHILCST.ps1','Connect-to-PHILCST.command']:
            text=(ROOT/'deploy/clients'/name).read_text()
            for forbidden in ['verify=False','--insecure','-SkipCertificateCheck','pip install','Start-Service','New-Service','launchctl bootstrap']:
                self.assertNotIn(forbidden,text)
            self.assertIn('fingerprint',text.lower());self.assertIn('Remove' if name.endswith('.ps1') else 'remove',text)
        common=(ROOT/'deploy/clients/client-common.ps1').read_text();self.assertIn('RemoteCertificateNameMismatch',common);self.assertIn('root.RawData',common)

    def test_real_configure_switch_rollback_and_ca_persistence_with_disposable_adapter(self):
        binary=ROOT/'build/macos/dist/Mac-arm64/PHILCST-VMS/runtime/caddy/caddy'
        if sys.platform!='darwin' or not binary.exists() or not shutil.which('php'):self.skipTest('Native Caddy/PHP fixture unavailable')
        with tempfile.TemporaryDirectory(prefix='d4-mode-fixture-') as directory:
            base=Path(directory).resolve();shared=base/'shared';web=shared/'web';shared.mkdir();template=base/'Caddyfile.template';shutil.copyfile(ROOT/'build/macos/templates/Caddyfile.template',template)
            env=shared/'.env';env.write_text('APP_KEY=fixture-preserved\nDETECTOR_API_KEY=fixture-api\n');hosts=base/'hosts';hosts.write_text('127.0.0.1 localhost\n');ini=base/'php.ini';ini.write_text('')
            db=shared/'database.sqlite';db.write_bytes(b'synthetic-unchanged-db')
            paths={'root':base,'release':base,'shared':shared,'web':web,'tls':web/'tls','state':shared/'network.json','identity':shared/'tls-identity.json','public_root':shared/'public-root.crt','config':base,'caddyfile':web/'Caddyfile','template':template,'env':env,'php':Path(shutil.which('php')),'phpini':ini,'caddy':binary,'windows':False,'hosts':hosts}
            class Adapter:
                def __init__(self):self.calls=[];self.fail=False;self.active=None
                def inspect(self):return {'interfaces':[{'name':'en0','address':LAN['address'],'prefix':24}]}
                def exposure_plan(self,config,old):return {'added_apps':[],'previous_added_apps':[]}
                def exposure(self,state):self.active=state['config'];self.calls.append('exposure')
                def stop(self):self.calls.append('stop')
                def refresh(self):self.calls.append('refresh')
                def permissions(self):self.calls.append('permissions')
                def start(self):self.calls.append('start')
                def ready(self):
                    if self.fail:raise ValueError('isolated restart failure')
            adapter=Adapter();server.configure(paths,dict(p.DEFAULT),True,adapter=adapter)
            root=paths['tls']/'pki/authorities/local/root.crt';issuer=root.read_bytes();rootkey=root.with_suffix('.key').read_bytes();before_db=db.read_bytes()
            server.configure(paths,LAN,adapter=adapter)
            self.assertEqual(adapter.active,LAN);self.assertEqual(root.read_bytes(),issuer)
            before={x:x.read_bytes() for x in [env,hosts,paths['state'],paths['caddyfile']]};adapter.fail=True
            with self.assertRaises(ValueError):server.configure(paths,dict(p.DEFAULT),adapter=adapter)
            self.assertEqual(before,{x:x.read_bytes() for x in before});self.assertEqual(adapter.active,LAN)
            adapter.fail=False;server.configure(paths,dict(p.DEFAULT),adapter=adapter)
            before={x:x.read_bytes() for x in [env,hosts,paths['state'],paths['caddyfile']]}
            def failed_access():raise ValueError('isolated D5A access readiness failure')
            with self.assertRaisesRegex(ValueError,'readiness failure'):
                server._configure(paths,LAN,prepare_only=True,adapter=adapter,verification=failed_access,announce=False)
            self.assertEqual(before,{x:x.read_bytes() for x in before})
            self.assertNotIn(LAN['address'],paths['caddyfile'].read_text());self.assertEqual(root.read_bytes(),issuer);self.assertEqual(root.with_suffix('.key').read_bytes(),rootkey)
            self.assertEqual(db.read_bytes(),before_db);self.assertIn('APP_KEY=fixture-preserved',env.read_text());self.assertIn('DETECTOR_API_KEY=fixture-api',env.read_text())
            root.with_suffix('.key').unlink();before_env=env.read_bytes()
            with self.assertRaisesRegex(ValueError,'incomplete'):server.configure(paths,LAN,adapter=adapter)
            self.assertEqual(env.read_bytes(),before_env);self.assertFalse(root.with_suffix('.key').exists())

if __name__=='__main__':unittest.main()
