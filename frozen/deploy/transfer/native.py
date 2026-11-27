"""Native target tools only. No source OS configuration or incoming executable is run."""
import importlib.util
import json
import os
from pathlib import Path
import plistlib
import re
import shutil
import sys
from contract import require,load,c,sha,canonical
from backup_contract import env_values,no_links
from coordination import atomic,identity
from updates_native_import import NativeUpdate
from maintenance import layout,command
from recovery import copy_tree
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'network'))
import policy


class NativeTransfer(NativeUpdate):
    def capsule(self):
        receipt=load(self.p['base'].parent/'update-controller.json')
        current=identity(self.p['base'])
        if receipt['installation']==current:return super().capsule()
        # A D5D target changes deployment identity, never its trusted executable.
        # Accept this exact transitional binding only after authenticating the
        # already protected handoff with that retained executable's own verifier.
        import uuid,types
        from recovery import tree_hash
        from contract import verify
        uuid.UUID(receipt['id']);path=self.p['base'].parent/'update-controller'/receipt['id'];no_links(path)
        require(tree_hash(path)==receipt['sha256'],'Retained target controller bytes changed')
        state=load(self.p['base'].parent/'transfer/control.json')
        require(state['schema']=='philcst.transfer-control.v1' and state['phase'] in ('promoting','verified','activating','active'),'No authenticated controller handoff is pending')
        require(state['local_host']==self.machine() and state['local_root']==sha(str(self.p['root']).encode()),'Controller handoff belongs to another target')
        envelope=state['import_manifest'];peer=state['peers'].get(envelope['key_id']);require(peer,'Unenrolled controller handoff source')
        php=[str(path/('runtime/php/php.exe' if self.p['windows'] else 'runtime/php/bin/php')),'-c',str(path/'config/recovery-php.ini')]
        body=verify(types.SimpleNamespace(php_verifier=lambda:php),envelope,peer,'manifest')
        require(body['source_identity']==current and state['challenge']['signed']['target_installation']==receipt['installation'],'Unrecognized old/new controller identity')
        require(body['challenge']==state['challenge'] and body['target_host']==self.machine() and body['target_root']==state['local_root'] and body['target_key']==state['authority']['key_id'],'Controller handoff target substitution')
        require(any(m['role']=='delivery_identity' and tree_hash(self.p['base']/'identity.json')==m['new_hash'] for m in state['moves']),'Transferred identity has no matching promotion intent')
        return path

    def finish_transfer_controller(self):
        self.capsule() # Verifies either the ordinary or exact signed transition.
        file=self.p['base'].parent/'update-controller.json';receipt=load(file)
        if receipt['installation']!=identity(self.p['base']):
            atomic(file,dict(receipt,installation=identity(self.p['base'])))
            self.permissions_for('security',file)

    def admin(self):
        super().admin();self.require_capsule()
        require((self.capsule()/'app/deploy/lifecycle/ownership_guard.py').is_file() and (self.capsule()/'app/deploy/transfer/control.py').is_file(),'A retained authenticated D5D controller is required; old D5C capsules cannot enforce retirement')
        if self.p['windows']:
            require(not str(self.p['root']).startswith('\\\\'),'Network-share roots are unsupported')
            command(['powershell.exe','-NoProfile','-File',self.capsule()/'app/deploy/windows/transfer-preflight.ps1','-Root',self.p['root']])
        else:self.mac().local_disk(self.p['root'],command(['/sbin/mount']))
    def permissions_for(self,role,path,verify=False):
        if role=='private' and not self.p['windows']:
            path=Path(path)
            if not path.exists():return
            for member in [path]+(list(path.rglob('*')) if path.is_dir() else []):
                no_links(member);mode=0o700 if member.is_dir() else 0o600
                if verify:require(member.stat().st_uid==0 and member.stat().st_mode & 0o7777==mode,'Unsafe protected transfer permissions')
                else:os.chown(member,0,0);member.chmod(mode)
            return
        return super().permissions_for(role,path,verify)

    def relocate_transfer(self,payload,manifest,body):
        network=policy.validate(body['decisions']['target_network'])
        saved_network=load(self.p['targets']['network']);require(saved_network['config']==network,'Configure target NIC/mode separately before its challenge; never import source exposure rules')
        # Keep existing target installer/service policy, replace only portable app data.
        for role in ('configuration','installation','network'):
            dst=payload/role
            if dst.exists():shutil.rmtree(dst)
            src=self.p['targets'][role]
            if src.is_dir():copy_tree(src,dst)
            else:dst.mkdir(mode=0o700);__import__('recovery').copy_file(src,dst/'data')
            manifest['components'][role]={'present':True,'kind':'directory' if src.is_dir() else 'file'}
        # Source server Keychain/Windows certificate enrollment receipt has no
        # authority on the new host. Do not import it or remove any OS trust.
        if (payload/'server_trust').exists():shutil.rmtree(payload/'server_trust')
        manifest['components']['server_trust']['present']=False
        web=payload/'tls'
        for child in web.iterdir():
            if child.name!='tls':shutil.rmtree(child) if child.is_dir() else child.unlink()
        env=env_values(payload/'environment/data')
        replacement={'DB_DATABASE':str(self.p['targets']['database']),'DB_URL':'','PHILCST_LIFECYCLE_ROOT':str(self.p['base']),
            'APP_BASE_PATH':str(self.p['app']),'APP_STORAGE_PATH':str(self.p['targets']['storage']),
            'APP_CONFIG_CACHE':str((self.p['app']/'bootstrap/cache' if self.p['windows'] else self.p['root']/'shared/cache')/'config.php'),
            'APP_ROUTES_CACHE':str((self.p['app']/'bootstrap/cache' if self.p['windows'] else self.p['root']/'shared/cache')/'routes.php'),
            'CAMERA_FILES_PATH':str(self.p['targets']['storage']/'app/camera'),'DEVICE_FILES_PATH':str(self.p['targets']['storage']/'app/devices')}
        for key,value in env.items():
            if key not in replacement and any(old in value for old in (body['source_paths']['storage'],body['source_base'],body['source_paths']['database'])):
                raise ValueError('Unrecognized source-specific environment path; separate explicit configuration review required')
        env.update(replacement)
        require(all('\n' not in v and '\r' not in v and '"' not in v for v in env.values()),'Unsupported environment quoting; review before transfer')
        (payload/'environment/data').write_text(policy.env_plan(''.join(k+'="'+v+'"\n' for k,v in env.items()),network))
        template=(self.p['release']/'config/Caddyfile.template').read_text()
        (web/'Caddyfile').write_text(policy.caddy_config(template,self.p['app']/'public',self.p['targets']['tls']/'tls',self.p['targets']['tls']/'logs/access.log',network,windows=self.p['windows'],pinned=True))
        self.permissions_for('tls',web)

    def validate_transfer_activation(self,body):
        self.verify_installed_program()
        # Existing native service identities belong to target and are never read
        # from transfer media. Source/target network state must be separately set.
        import server
        paths=server.layout(self.p['root']);adapter=server.Native(paths)
        selected=policy.validate(body['decisions']['target_network'],adapter.inspect()['interfaces'])
        require(load(paths['state'])['config']==selected,'Target network configuration changed; renewed handoff approval required')
        policy.hosts_plan(paths['hosts'].read_bytes().decode(),'127.0.0.1')
        adapter.exposure_plan(selected,load(paths['state']))
        server.public_certificate(paths)
        # No camera access or fabricated inference; target service readiness is
        # exercised by D5A resume. Device acceptance remains a separate result.


def installed(root):
    adapter=NativeTransfer(layout(root));adapter.admin();return adapter
