import datetime as dt
from pathlib import Path
import unittest
import uuid
from fixture import Fixture
from cli import requests,actor
from contracts import now
from coordination import atomic,phase
from backup_contract import database,load,tree_hash

class Broker(unittest.TestCase):
    def setUp(self):self.f=Fixture();self.addCleanup(self.f.close)
    def request(self,action='check',inputs=None,**extra):
        with database(self.f.p['targets']['database']) as db:user=dict(db.execute('SELECT * FROM users').fetchone())
        id=str(uuid.uuid4());body={'schema':1,'id':id,'actor':user['id'],'session_version':user['session_version'],'created_at':now().isoformat(),'action':action,'inputs':inputs or {}};body.update(extra)
        directory=self.f.p['targets']['storage']/'app/update-inbox'/id;directory.mkdir(parents=True);atomic(directory/'request.json',body);return directory,body
    def test_malformed_expired_guard_and_disabled_requests_cannot_control_system(self):
        before={role:tree_hash(self.f.p['targets'][role]) for role in ('tls','environment','configuration')}
        directory,body=self.request(action='shell',inputs={'command':'anything','path':'/outside'})
        result=requests(self.f.manager);self.assertIn('invalid',result[0]['outcome']);self.assertTrue((directory/'processed.json').exists())
        for change in ({'actor':99999},{'created_at':(now()-dt.timedelta(days=2)).isoformat()},{'session_version':999999}):
            with self.assertRaises(ValueError):actor(self.f.adapter,body|change)
        with database(self.f.p['targets']['database'],write=True) as db:db.execute("UPDATE users SET role='guard'")
        with self.assertRaises(ValueError):actor(self.f.adapter,body)
        with database(self.f.p['targets']['database'],write=True) as db:db.execute("UPDATE users SET role='admin',account_status='disabled'")
        with self.assertRaises(ValueError):actor(self.f.adapter,body)
        self.assertEqual('running',phase(self.f.p['base'])['phase']);self.assertEqual(before,{role:tree_hash(self.f.p['targets'][role]) for role in before})
    def test_processed_requests_do_not_starve_later_requests_and_false_preferences_stay_false(self):
        for _ in range(21):
            directory,_=self.request();atomic(directory/'processed.json',{'schema':1})
        directory,_=self.request('preferences',{'automatic_check':'0','automatic_download':'0','channel':'stable'})
        result=requests(self.f.manager);self.assertEqual('preferences saved',result[0]['outcome']);self.assertFalse(self.f.store.read()['preferences']['automatic_check']);self.assertTrue((directory/'processed.json').exists())
    def test_active_control_owner_cannot_be_stolen(self):
        with self.f.store.owned():
            with self.assertRaises(ValueError):
                with self.f.store.owned():pass
        self.assertFalse((self.f.p['base']/'update-operation.lock').exists())

if __name__=='__main__':unittest.main()
