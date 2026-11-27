"""D3 read-only update contracts. No network, extraction, installation or trust-store writes.
D5 must invoke an installation-trusted copy, PHP runtime, state and public trust store.
"""
import base64
import datetime as dt
from functools import total_ordering
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import unicodedata
import zipfile

PRODUCT='philcst-vms'
SCHEMA='philcst.update.v1'
PLATFORMS={'windows-x64':('windows','x64'),'macos-x86_64':('macos','x86_64'),'macos-arm64':('macos','arm64')}
CHANNELS={'stable','prerelease','development'}
MAX_METADATA=1024*1024
MAX_CONTENT_METADATA=32*1024*1024


class Rejected(ValueError):pass


def require(condition,message):
    if not condition:raise Rejected(message)


def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=True,allow_nan=False).encode('ascii')

def sha_bytes(value):return hashlib.sha256(value).hexdigest()

def sha_file(path):
    with Path(path).open('rb') as stream:return sha_stream(stream)

def sha_stream(stream):
    h=hashlib.sha256()
    for block in iter(lambda:stream.read(1024*1024),b''):h.update(block)
    return h.hexdigest()


def parse(raw,limit=MAX_METADATA):
    require(isinstance(raw,bytes) and len(raw)<=limit,'Missing/oversized metadata')
    def pairs(items):
        out={}
        for key,value in items:
            require(key not in out,'Duplicate JSON field: '+key);out[key]=value
        return out
    try:return json.loads(raw.decode('utf-8'),object_pairs_hook=pairs,parse_constant=lambda _:(_ for _ in ()).throw(Rejected('Non-finite JSON value')))
    except (UnicodeError,json.JSONDecodeError,RecursionError) as error:raise Rejected('Malformed/truncated metadata') from error


def load(path):
    try:
        with Path(path).open('rb') as f:return parse(f.read(MAX_METADATA+1))
    except OSError as error:raise Rejected('Missing/unreadable metadata') from error


def fields(value,names):require(type(value) is dict and set(value)==set(names),'Unexpected/missing fields: '+','.join(names))
def text(value):require(type(value) is str and 0<len(value)<=512,'Invalid text field')
def digest(value):require(type(value) is str and re.fullmatch('[a-f0-9]{64}',value),'Invalid SHA-256')
def strings(value):require(type(value) is list and all(type(x) is str for x in value) and len(value)==len(set(value)),'Invalid string list')
def integer(value,minimum=0):require(type(value) is int and value>=minimum,'Invalid integer')


def timestamp(value):
    require(type(value) is str and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ',value),'Invalid UTC timestamp')
    try:return dt.datetime.strptime(value,'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=dt.timezone.utc)
    except ValueError as error:raise Rejected('Invalid UTC timestamp') from error


@total_ordering
class Version:
    def __init__(self,value):
        text(value)
        m=re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?',value)
        require(m is not None,'Malformed SemVer');self.value=value;self.core=tuple(map(int,m.group(1,2,3)));self.pre=tuple(m[4].split('.')) if m[4] else ()
        require(all(not (x.isdigit() and len(x)>1 and x[0]=='0') for x in self.pre),'Leading zero in SemVer prerelease')
    def __eq__(self,other):return self.core==other.core and self.pre==other.pre
    def __lt__(self,other):
        if self.core!=other.core:return self.core<other.core
        if not self.pre:return False
        if not other.pre:return True
        for a,b in zip(self.pre,other.pre):
            if a==b:continue
            if a.isdigit() and b.isdigit():return int(a)<int(b)
            if a.isdigit()!=b.isdigit():return a.isdigit()
            return a<b
        return len(self.pre)<len(other.pre)


def os_version(value):
    require(type(value) is str and re.fullmatch(r'[0-9]+\.[0-9]+(?:\.[0-9]+)?',value),'Malformed OS version')
    nums=tuple(map(int,value.split('.')));return nums+(0,)*(3-len(nums))


def safe_path(name):
    require(type(name) is str and name and len(name)<1024,'Invalid archive path')
    require('\\' not in name and not re.search(r'[\x00-\x1f<>:"|?*]',name),'Unsafe archive path')
    require(not name.startswith('/') and all(x not in ('','.', '..') for x in name.rstrip('/').split('/')),'Unsafe archive path')
    for part in PurePosixPath(name).parts:
        require(not part.endswith((' ','.')) and not re.fullmatch(r'(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?',part,re.I),'Unsafe Windows-compatible path')
    return name.rstrip('/')


def path_key(name):
    # macOS filesystems may normalize Unicode; Windows/macOS may ignore case.
    return unicodedata.normalize('NFC',name).casefold()


def validate_manifest(body):
    fields(body,['schema','product','version','channel','source','platform','compatibility','package','issued_at','expires_at'])
    require(body['schema']==SCHEMA and body['product']==PRODUCT,'Wrong product/schema')
    version=Version(body['version']);require(body['channel'] in CHANNELS,'Unknown channel')
    require((not version.pre)==(body['channel']=='stable'),'Version/channel mismatch')
    fields(body['source'],['commit','sha256','build_identity']);require(type(body['source']['commit']) is str and re.fullmatch('[a-f0-9]{40}',body['source']['commit']),'Invalid source commit');digest(body['source']['sha256']);text(body['source']['build_identity'])
    platform=body['platform'];fields(platform,['id','os','architecture','minimum_os']);require(platform['id'] in PLATFORMS and tuple(platform[x] for x in ['os','architecture'])==PLATFORMS[platform['id']],'Wrong platform identity');os_version(platform['minimum_os'])
    c=body['compatibility'];fields(c,['supported_upgrade_origins','schema','runtime','installation_phase']);require(c['installation_phase']=='D5','Unknown installation contract')
    require(type(c['supported_upgrade_origins']) is list,'Invalid origins')
    for origin in c['supported_upgrade_origins']:
        fields(origin,['minimum_inclusive','maximum_exclusive']);require(Version(origin['minimum_inclusive'])<Version(origin['maximum_exclusive']),'Invalid origin range')
    for key in ['schema','runtime']:
        fields(c[key],['accepted','target']);strings(c[key]['accepted']);digest(c[key]['target'])
        for value in c[key]['accepted']:digest(value)
    package=body['package'];fields(package,['filename','format','size','sha256','content_manifest_sha256']);safe_path(package['filename']);require('/' not in package['filename'],'Package must be a basename');integer(package['size'],1);digest(package['sha256']);digest(package['content_manifest_sha256'])
    expected='zip' if platform['id']=='windows-x64' else 'tar.gz';require(package['format']==expected and package['filename'].endswith('.'+expected),'Wrong package format')
    issued=timestamp(body['issued_at']);expires=timestamp(body['expires_at']);require(issued<expires and expires-issued<=dt.timedelta(days=90),'Invalid metadata lifetime')
    return body


def fingerprint(pem):
    require(type(pem) is str and len(pem)<=16384,'Invalid public key')
    m=re.fullmatch(r'-----BEGIN PUBLIC KEY-----\s+([A-Za-z0-9+/=\s]+)-----END PUBLIC KEY-----\s*',pem)
    require(m is not None,'Only public SPKI PEM verification material is accepted')
    try:return sha_bytes(base64.b64decode(re.sub(r'\s','',m[1]),validate=True))
    except ValueError as error:raise Rejected('Malformed public key') from error


def validate_trust(store,fixture=False):
    fields(store,['schema','product','environment','generation','keys']);require(store['schema']=='philcst.update-trust.v1' and store['product']==PRODUCT,'Wrong trust identity');integer(store['generation'],1)
    require(store['environment']==('fixture' if fixture else 'production'),'Fixture trust is never a production trust root')
    require(type(store['keys']) is dict and store['keys'],'No installation-trusted keys')
    for keyid,key in store['keys'].items():
        digest(keyid);fields(key,['public_key','channels','platforms','roles','not_before','not_after','revoked']);require(fingerprint(key['public_key'])==keyid,'Trusted key fingerprint mismatch');strings(key['channels']);strings(key['platforms']);strings(key['roles']);require(set(key['channels'])<=CHANNELS and set(key['platforms'])<=set(PLATFORMS) and set(key['roles'])<={'update','rotate'},'Invalid trust scope');require(type(key['revoked']) is bool,'Invalid revocation state');require(timestamp(key['not_before'])<timestamp(key['not_after']),'Invalid key lifetime')
    return store


def authenticate(envelope,store,php,role='update',channel=None,platform=None,now=None,fixture=False):
    fields(envelope,['signed','signatures']);validate_trust(store,fixture);require(type(envelope['signatures']) is list and len(envelope['signatures'])<=16,'Malformed signatures')
    now=now or dt.datetime.now(dt.timezone.utc);failures=[]
    for sig in envelope['signatures']:
        fields(sig,['algorithm','key_id','value']);require(sig['algorithm']=='RSA-PKCS1-SHA256','Unsupported signature algorithm');digest(sig['key_id'])
        key=store['keys'].get(sig['key_id'])
        if not key or key['revoked'] or role not in key['roles']:continue
        if channel and channel not in key['channels'] or platform and platform not in key['platforms']:continue
        if not timestamp(key['not_before'])<=now<timestamp(key['not_after']):continue
        require(type(sig['value']) is str and len(sig['value'])<=16384,'Invalid signature value')
        try:base64.b64decode(sig['value'],validate=True)
        except ValueError as error:raise Rejected('Invalid signature encoding') from error
        request={'public_key':key['public_key'],'signature':sig['value'],'data':base64.b64encode(canonical(envelope['signed'])).decode()}
        try:result=subprocess.run([*map(str,php),str(Path(__file__).with_name('signature.php'))],input=json.dumps(request),capture_output=True,text=True,timeout=30)
        except (OSError,subprocess.TimeoutExpired) as error:raise Rejected('Installation-trusted signature verifier unavailable') from error
        if result.returncode==0:
            require(parse(result.stdout.encode())=={'verified':True},'Malformed verifier response');return key
        failures.append(sig['key_id'])
    raise Rejected('No valid installation-trusted signature (unsigned, untrusted, revoked, out of scope, expired or tampered)')


def validate_state(state,fixture=False):
    fields(state,['schema','product','environment','platform','os_version','version','channel','source','schema_id','runtime_id','max_expanded_bytes'])
    require(state['schema']=='philcst.installation-update-state.v1' and state['product']==PRODUCT,'Wrong installed-state identity');require(state['environment']==('fixture' if fixture else 'production'),'Untrusted installation environment');require(state['platform'] in PLATFORMS,'Unknown installed platform');os_version(state['os_version']);Version(state['version']);require(state['channel'] in CHANNELS,'Unknown installed channel');digest(state['schema_id']);digest(state['runtime_id']);integer(state['max_expanded_bytes'],1)
    fields(state['source'],['commit','sha256','build_identity']);require(re.fullmatch('[a-f0-9]{40}',state['source']['commit']) is not None,'Invalid installed commit');digest(state['source']['sha256']);text(state['source']['build_identity'])


def validate_metadata(envelope,state,store,php,channel='stable',fixture=False,now=None):
    validate_state(state,fixture);fields(envelope,['signed','signatures']);body=validate_manifest(envelope['signed']);now=now or dt.datetime.now(dt.timezone.utc)
    require(channel in CHANNELS and body['channel']==channel,'Channel was not explicitly selected')
    authenticate(envelope,store,php,channel=channel,platform=body['platform']['id'],now=now,fixture=fixture)
    require(timestamp(body['issued_at'])<=now<timestamp(body['expires_at']),'Update metadata is not current')
    require(body['platform']['id']==state['platform'],'Wrong OS/architecture');require(os_version(state['os_version'])>=os_version(body['platform']['minimum_os']),'Unsupported OS version')
    candidate,current=Version(body['version']),Version(state['version']);require(candidate>=current,'Ordinary downgrade rejected')
    if candidate==current:
        require(body['version']==state['version'] and body['source']==state['source'],'Same-version source/build identity mismatch')
        require(state['schema_id']==body['compatibility']['schema']['target'] and state['runtime_id']==body['compatibility']['runtime']['target'],'Installed same-version schema/runtime mismatch')
        return {'status':'already-installed','version':body['version'],'platform':state['platform']}
    c=body['compatibility'];require(any(Version(x['minimum_inclusive'])<=current<Version(x['maximum_exclusive']) for x in c['supported_upgrade_origins']),'Unsupported upgrade origin; D5 compatibility approval required')
    require(state['schema_id'] in c['schema']['accepted'],'Incompatible database schema');require(state['runtime_id'] in c['runtime']['accepted'],'Incompatible runtime origin')
    return {'status':'eligible-for-D5-review','version':body['version'],'platform':state['platform'],'installation_performed':False}


def archive_members(path,fmt):
    if fmt=='zip':
        with zipfile.ZipFile(path) as archive:
            for item in archive.infolist():
                require(not item.flag_bits&1,'Encrypted ZIP is unsupported');mode=(item.external_attr>>16)&0o170000;require(mode in (0,0o100000,0o040000),'Archive links/special files rejected')
                if item.is_dir():yield item.filename,0,None
                else:
                    with archive.open(item) as stream:yield item.filename,item.file_size,stream
    else:
        with tarfile.open(path,'r:gz') as archive:
            for item in archive:
                require(item.isfile() or item.isdir(),'Archive links/special files rejected')
                if item.isdir():yield item.name,0,None
                else:
                    with archive.extractfile(item) as stream:yield item.name,item.size,stream


def compatibility_ids(info,platform):
    migrations={n:h for n,h in info['source']['files'].items() if n.startswith('database/migrations/')}
    require(bool(migrations),'Missing captured migration contract')
    runtime={'platform':platform,'runtimes':info['runtimes'],'dependency_locks':info['dependency_locks']}
    return sha_bytes(canonical(migrations)),sha_bytes(canonical(runtime))


def inspect_archive(path,body,max_expanded):
    package=body['package'];require(Path(path).name==package['filename'],'Package filename mismatch')
    try:require(Path(path).stat().st_size==package['size'],'Missing/truncated/mismatched package size');require(sha_file(path)==package['sha256'],'Package SHA-256 mismatch')
    except OSError as error:raise Rejected('Missing/unreadable package') from error
    seen=set();file_paths=set();required_directories=set();files={};content=None;build=None;total=0
    try:
        for name,size,stream in archive_members(path,package['format']):
            name=safe_path(name);require(name=='PHILCST-VMS' or name.startswith('PHILCST-VMS/'),'Unexpected archive root');key=path_key(name);require(key not in seen,'Duplicate/case-colliding archive path')
            parents=PurePosixPath(key).parents
            require(not any(str(parent) in file_paths for parent in parents),'Archive file shadows a directory')
            if stream is not None:
                require(name!='PHILCST-VMS' and key not in required_directories,'Archive file shadows a directory')
                file_paths.add(key)
            required_directories.update(str(parent) for parent in parents)
            seen.add(key)
            if stream is None:continue
            total+=size;require(total<=max_expanded and len(files)<100000,'Expanded package exceeds installation limits');rel=name.removeprefix('PHILCST-VMS/')
            if rel in ['CONTENT-MANIFEST.json','BUILD-INFO.json']:
                limit=MAX_CONTENT_METADATA if rel=='CONTENT-MANIFEST.json' else MAX_METADATA
                raw=stream.read(limit+1);require(len(raw)==size,'Truncated/oversized internal metadata');value=parse(raw,limit)
                if rel=='CONTENT-MANIFEST.json':content=value;require(sha_bytes(raw)==package['content_manifest_sha256'],'Content manifest hash mismatch')
                else:build=value
                files[rel]={'sha256':sha_bytes(raw),'bytes':size}
            else:files[rel]={'sha256':sha_stream(stream),'bytes':size}
    except (OSError,EOFError,tarfile.TarError,zipfile.BadZipFile,RuntimeError) as error:raise Rejected('Malformed/truncated archive') from error
    require(content is not None and build is not None,'Missing content/build manifest')
    fields(content,['format','files','total_bytes']);require(content['format']==1 and type(content['files']) is dict,'Malformed content manifest');integer(content['total_bytes'])
    listed=set()
    for name,entry in content['files'].items():
        safe_path(name);fields(entry,['sha256','bytes']);digest(entry['sha256']);integer(entry['bytes']);require(path_key(name) not in listed,'Colliding content manifest path');listed.add(path_key(name));require(files.get(name)==entry,'Missing/corrupt extracted file: '+name)
    require(set(files)==set(content['files'])|{'CONTENT-MANIFEST.json'}|({'CONTENT-SIGNATURE.json'} if 'CONTENT-SIGNATURE.json' in files else set()),'Unexpected extracted files')
    require(content['total_bytes']==sum(x['bytes'] for x in content['files'].values()),'Content byte total mismatch')
    require(build.get('version')==body['version'] and build.get('commit')==body['source']['commit'] and build.get('source',{}).get('source_sha256')==body['source']['sha256'] and build.get('build_identity',build.get('version'))==body['source']['build_identity'],'Package/build/update identity mismatch')
    actual_platform='windows-x64' if build.get('architecture')=='win_amd64' else 'macos-'+str(build.get('architecture'))
    require(actual_platform==body['platform']['id'],'Packaged architecture mismatch')
    require(os_version(build.get('minimum_macos','10.0.0'))==os_version(body['platform']['minimum_os']),'Packaged minimum OS mismatch')
    schema_id,runtime_id=compatibility_ids(build,actual_platform)
    require(schema_id==body['compatibility']['schema']['target'] and runtime_id==body['compatibility']['runtime']['target'],'Packaged schema/runtime contract mismatch')
    return {'files_verified':len(content['files']),'expanded_bytes':total}


def validate_import(envelope,package,state,trust,php,channel='stable',delivery='local',fixture=False,now=None):
    require(delivery in ['local','downloaded'],'Unknown delivery interface')
    result=validate_metadata(envelope,state,trust,php,channel,fixture,now)
    result.update(inspect_archive(package,envelope['signed'],state['max_expanded_bytes']));result.update(delivery=delivery,active_data_mutated=False)
    return result


def select_candidate(envelopes,state,trust,php,channel='stable',fixture=False,now=None):
    """Pure selection after a future discovery adapter. No network or active-state changes."""
    validate_state(state,fixture);validate_trust(trust,fixture);valid=[];rejected=0
    for envelope in envelopes:
        try:
            result=validate_metadata(envelope,state,trust,php,channel,fixture,now)
            if result['status']=='eligible-for-D5-review':valid.append(envelope)
        except (Rejected,KeyError,TypeError):rejected+=1
    if not valid:return {'status':'no-eligible-update','installed_version':state['version'],'rejected':rejected,'active_data_mutated':False}
    chosen=max(valid,key=lambda x:Version(x['signed']['version']))
    return {'status':'candidate','manifest':chosen,'rejected':rejected,'active_data_mutated':False}


def validate_rotation(envelope,trust,php,fixture=False,now=None):
    """Return an authorized PROPOSAL only; D5 atomically persists trust after admin approval."""
    fields(envelope,['signed','signatures']);body=envelope['signed'];fields(body,['schema','product','previous_trust_sha256','generation','replacement_trust','issued_at','expires_at'])
    require(body['schema']=='philcst.update-key-rotation.v1' and body['product']==PRODUCT,'Wrong rotation identity');require(body['previous_trust_sha256']==sha_bytes(canonical(trust)),'Rotation does not extend installation trust');require(body['generation']==trust['generation']+1,'Trust rollback/replayed generation')
    now=now or dt.datetime.now(dt.timezone.utc);require(timestamp(body['issued_at'])<=now<timestamp(body['expires_at']),'Rotation expired/not current')
    signer=authenticate(envelope,trust,php,role='rotate',fixture=fixture,now=now);replacement=validate_trust(body['replacement_trust'],fixture)
    require(replacement['generation']==body['generation'],'Rotation generation mismatch')
    for key in replacement['keys'].values():require(set(key['platforms'])<=set(signer['platforms']) and set(key['channels'])<=set(signer['channels']),'Rotation exceeds authorized signing scope')
    require(any(not k['revoked'] and 'update' in k['roles'] and timestamp(k['not_before'])<=now<timestamp(k['not_after']) for k in replacement['keys'].values()),'Rotation leaves no active update verifier')
    return {'status':'authorized-trust-proposal','trust':replacement,'trust_written':False}
