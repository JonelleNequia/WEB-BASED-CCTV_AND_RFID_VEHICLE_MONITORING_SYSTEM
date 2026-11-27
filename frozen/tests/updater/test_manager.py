import copy
import datetime as dt
import io
import json
from pathlib import Path
import time
import unittest
from unittest.mock import patch
import urllib.error
from fixture import Fixture
from contracts import c,INDEX,REPOSITORY,canonical,sha,now,stamp,validate_index
from coordination import atomic,phase
from backup_contract import load,tree_hash
from manager import Manager
from transport import GitHub,Unavailable

class Published:
    def __init__(self,f,manifest,archive):
        self.calls=[];self.f=f;self.manifest=load(manifest);self.archive=archive;self.sequence=1;self.offline=False;self.partial=False
    def index(self):
        body={'schema':'philcst.release-index.v1','product':c.PRODUCT,'repository':REPOSITORY,'channel':'stable','sequence':self.sequence,'trust_generation':1,
            'issued_at':stamp(now()-dt.timedelta(minutes=1)),'expires_at':stamp(now()+dt.timedelta(days=1)),
            'releases':[{'tag':'v1.1.0','manifest':'fixture.update.json','manifest_sha256':sha(canonical(self.manifest)),'notes':'Synthetic <script>never executed</script>'}]}
        return self.f.sign(body)
    def releases(self):
        self.calls.append('releases')
        if self.offline:raise Unavailable(1200)
        return [{'tag_name':'v1.1.0','assets':[{'name':INDEX,'state':'uploaded'},{'name':'fixture.update.json','state':'uploaded'},{'name':self.archive.name,'state':'uploaded'}]}]
    def asset(self,releases,tag,name):return name
    def stream(self,url,limit,destination=None,**kwargs):
        self.calls.append(url)
        data=canonical(self.index()) if url==INDEX else canonical(self.manifest) if url.endswith('.json') else self.archive.read_bytes()
        if destination:
            destination.write(data[:123] if self.partial else data)
            if self.partial:raise Unavailable()
            return len(data)
        return data

class Preparation(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.f=Fixture();cls.manifest,cls.archive=cls.f.package();cls.original=copy.deepcopy(cls.f.store.read());cls.ledger=load(cls.f.p['base']/'recovery-security.json')
    @classmethod
    def tearDownClass(cls):cls.f.close()
    def setUp(self):
        atomic(self.f.p['base']/'recovery-security.json',self.ledger);self.f.store.write(copy.deepcopy(self.original));self.transport=Published(self.f,self.manifest,self.archive);self.manager=Manager(self.f.adapter,self.transport)
    def test_default_off_and_scheduled_discovery_never_stops_monitoring(self):
        self.assertEqual('not due',self.manager.check(scheduled=True)['outcome']);self.assertEqual([],self.transport.calls)
        before={role:tree_hash(self.f.p['targets'][role]) for role in ('environment','tls','network','database','configuration')}
        self.assertEqual('update available',self.manager.check()['outcome']);self.assertEqual('running',phase(self.f.p['base'])['phase'])
        self.assertEqual(before,{role:tree_hash(self.f.p['targets'][role]) for role in before});self.assertFalse(self.f.store.read()['candidate']['ready'])
    def test_offline_rate_limit_reports_unavailable_and_manual_backoff(self):
        self.transport.offline=True;self.assertEqual('check unavailable',self.manager.check()['outcome']);calls=len(self.transport.calls)
        self.assertIn('backoff',self.manager.check()['outcome']);self.assertEqual(calls,len(self.transport.calls));self.assertEqual('running',phase(self.f.p['base'])['phase'])
    def test_signed_index_replay_conflict_expiry_and_wrong_repo(self):
        envelope=self.transport.index();args=(self.f.trust,['php'],'stable','macos-arm64',{},now(),True)
        validate_index(envelope,*args)
        for field,value in [('sequence',0),('repository','attacker/other'),('expires_at',stamp(now()-dt.timedelta(seconds=1))),('trust_generation',2)]:
            bad=copy.deepcopy(envelope['signed']);bad[field]=value
            with self.subTest(field=field),self.assertRaises(ValueError):validate_index(self.f.sign(bad),self.f.trust,['php'],'stable','macos-arm64',{'sequence':1,'sha256':sha(canonical(envelope['signed']))},now(),True)
        bad=copy.deepcopy(envelope['signed']);bad['releases'][0]['notes']='replacement'
        with self.assertRaises(ValueError):validate_index(self.f.sign(bad),self.f.trust,['php'],'stable','macos-arm64',{'sequence':1,'sha256':sha(canonical(envelope['signed']))},now(),True)
    def test_newer_authenticated_index_persists_floor_and_old_release_is_not_newest(self):
        self.transport.sequence=4;self.manager.check();self.assertEqual(4,self.f.store.read()['floors']['stable']['sequence'])
        self.transport.sequence=3;self.assertEqual('check unavailable',self.manager.check()['outcome']);self.assertEqual(4,self.f.store.read()['floors']['stable']['sequence'])
    def test_download_and_usb_share_complete_validation_and_no_incoming_execution(self):
        self.manager.check();candidate=self.f.store.read()['candidate']['id'];self.assertFalse(self.f.store.read()['candidate']['ready'])
        self.assertTrue(self.manager.download(candidate)['ready']);online=self.manager.last_validation if hasattr(self.manager,'last_validation') else self.manager.validate(candidate)[0]
        self.transport.calls.clear();result=self.manager.import_local(self.manifest,self.archive);self.assertEqual(candidate,result['candidate']);self.assertFalse(result['installed']);self.assertEqual([],self.transport.calls)
        self.assertEqual('running',phase(self.f.p['base'])['phase']);self.assertEqual('1.0.0',self.f.adapter.source_binding()['version'])
    def test_interrupted_download_never_becomes_ready(self):
        # A fresh candidate identity avoids any complete cache retained by other tests.
        self.transport.manifest['signed']['source']['build_identity']='INTERRUPTED';self.transport.manifest=self.f.sign(self.transport.manifest['signed'])
        self.manager.check();candidate=self.f.store.read()['candidate']['id'];self.transport.partial=True
        with self.assertRaises(ValueError):self.manager.download(candidate)
        self.assertFalse(self.f.store.read()['candidate']['ready']);self.assertFalse((self.f.store.base.parent/'update-cache'/candidate).exists())
        self.assertTrue(list((self.f.store.base.parent/'update-cache').glob('.incomplete-*')))
    def test_wrong_platform_origin_downgrade_unknown_or_revoked_authority(self):
        original=load(self.manifest)
        cases=[]
        bad=copy.deepcopy(original['signed']);bad['compatibility']['runtime']['accepted']=[];cases.append(self.f.sign(bad))
        bad=copy.deepcopy(original['signed']);bad['version']='0.9.0';cases.append(self.f.sign(bad))
        bad=copy.deepcopy(original['signed']);bad['platform']={'id':'macos-x86_64','os':'macos','architecture':'x86_64','minimum_os':'14.0.0'};cases.append(self.f.sign(bad))
        bad=copy.deepcopy(original);bad['signatures'][0]['key_id']='f'*64;cases.append(bad)
        bad=copy.deepcopy(original);bad['signed']['source']['sha256']='f'*64;cases.append(bad)
        for envelope in cases:
            with self.assertRaises(ValueError):self.manager.compatible(copy.deepcopy(self.original),envelope)
        value=copy.deepcopy(self.original);value['trust']['keys'][next(iter(self.f.trust['keys']))]['revoked']=True
        with self.assertRaises(ValueError):self.manager.compatible(value,original)
    def test_explicit_channels_and_clock_rollback(self):
        self.manager.preferences(channel='prerelease');self.assertEqual('prerelease',self.f.store.read()['preferences']['channel'])
        with self.assertRaises(ValueError):self.manager.preferences(automatic_download=True)
        value=self.f.store.read();value['clock_floor']=stamp(now()+dt.timedelta(days=2));atomic(self.f.store.catalog,value)
        with self.assertRaises(ValueError):self.f.store.read()
    def test_trust_bootstrap_never_replaces_existing_or_uses_fixture_root_in_production(self):
        with self.assertRaises(ValueError):self.f.store.bootstrap(self.f.trust,sha(canonical(self.f.trust)),self.f.adapter.source_binding()['source_sha256'])
        with self.assertRaises(ValueError):c.validate_trust(self.f.trust,False)
    def test_atomic_rotation_and_revocation_floor(self):
        trust=copy.deepcopy(self.f.trust);trust['generation']=2
        body={'schema':'philcst.update-key-rotation.v1','product':c.PRODUCT,'previous_trust_sha256':sha(canonical(self.f.trust)),'generation':2,'replacement_trust':trust,
              'issued_at':stamp(now()-dt.timedelta(minutes=1)),'expires_at':stamp(now()+dt.timedelta(hours=1))}
        envelope=self.f.sign(body);self.f.store.rotate(envelope,sha(canonical(envelope)))
        self.assertEqual(2,self.f.store.read()['trust']['generation']);self.assertEqual(2,load(self.f.p['base']/'recovery-security.json')['update_generation'])
        with self.assertRaises(ValueError):self.f.store.rotate(envelope,sha(canonical(envelope)))
        value=copy.deepcopy(self.original);atomic(self.f.store.catalog,value)
        with self.assertRaises(ValueError):self.f.store.read()

class Transport(unittest.TestCase):
    def test_safe_https_destinations_and_no_secret_headers(self):
        seen=[]
        class Response(io.BytesIO):headers={'Content-Length':'2'}
        class Opener:
            def open(self,request,timeout):seen.append(request);return Response(b'[]')
        g=GitHub(opener=Opener());self.assertEqual([],g.releases())
        headers={k.lower():v for k,v in seen[0].header_items()};self.assertNotIn('authorization',headers);self.assertNotIn('cookie',headers);self.assertNotIn('x-api-key',headers)
        for url in ('http://github.com/'+REPOSITORY+'/releases/download/v1/x','https://attacker.test/x','https://github.com/other/project/releases/download/v1/x','https://user@api.github.com/repos/'+REPOSITORY+'/releases','https://api.github.com/repos/'+REPOSITORY+'/branches'):
            with self.subTest(url=url),self.assertRaises(Unavailable):g.allowed(url,True)
    def test_redirect_rate_limit_timeout_and_size_are_bounded(self):
        class Opener:
            def open(self,request,timeout):raise urllib.error.HTTPError(request.full_url,429,'rate limited',{'Retry-After':'1234'},None)
        g=GitHub(opener=Opener())
        with self.assertRaises(Unavailable) as error:g.releases()
        self.assertEqual(1234,error.exception.retry_after)
        class Redirect:
            def open(self,request,timeout):raise urllib.error.HTTPError(request.full_url,302,'redirect',{'Location':'https://attacker.test/package'},None)
        with self.assertRaises(Unavailable):GitHub(opener=Redirect()).releases()
        class Timeout:
            def open(self,request,timeout):raise TimeoutError()
        with self.assertRaises(Unavailable):GitHub(opener=Timeout()).releases()
        class Response(io.BytesIO):headers={'Content-Length':'1000'}
        class Large:
            def open(self,request,timeout):return Response(b'x'*1000)
        with self.assertRaises(Unavailable):GitHub(opener=Large()).stream('https://api.github.com/repos/'+REPOSITORY+'/releases',10)

if __name__=='__main__':unittest.main()
