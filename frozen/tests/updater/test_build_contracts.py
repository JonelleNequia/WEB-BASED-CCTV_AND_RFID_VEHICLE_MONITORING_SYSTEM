import importlib.util
from pathlib import Path
import unittest
from fixture import ROOT
from contracts import c,binding,validate_index,now,stamp,canonical,sha
import datetime as dt


def module(name,path):
    import sys
    sys.path.insert(0,str(ROOT/'build/updates'))
    spec=importlib.util.spec_from_file_location(name,ROOT/path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m

class BuildContracts(unittest.TestCase):
    def test_exact_path_generator_binds_current_target_without_recursive_identity(self):
        paths=module('d5c_exact_paths','build/updates/paths.py')
        for arch in ('arm64','x86_64','win_amd64'):
            info={'product':'PHILCST Vehicle Monitoring','architecture':arch,'version':'1.1.0','commit':'a'*40,'build_identity':'SYNTHETIC','source':{'source_sha256':'b'*64,'files':{'database/migrations/fixture.php':'c'*64}},'runtimes':{'php':'synthetic'},'dependency_locks':{}}
            policy={'schema':'philcst.upgrade-paths.v1','product':c.PRODUCT,'paths':[{'from':{'platform':binding(info)['platform']},'database_schema_sha256':'d'*64,'service_contract':'D4-D5A-D5B-v1'}]}
            self.assertEqual(binding(info),paths.create(info,policy)['paths'][0]['to'])
        self.assertEqual([],paths.create(info,dict(policy,paths=[]))['paths'])
    def test_production_compatibility_and_exact_origins_remain_explicitly_unapproved(self):
        self.assertEqual([],c.load(ROOT/'build/updates/upgrade-paths.json')['paths'])
        rules=c.load(ROOT/'build/updates/compatibility.json')
        for value in rules['platforms'].values():self.assertEqual([],value['supported_upgrade_origins'])

if __name__=='__main__':unittest.main()
