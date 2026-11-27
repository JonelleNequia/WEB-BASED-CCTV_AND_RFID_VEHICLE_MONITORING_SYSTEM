"""Installation-trusted D5C contracts. Import has no network or installation effects."""
import datetime as dt
import importlib.util
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lifecycle'))
from backup_contract import require, canonical, sha, load, no_links, tree_hash, sqlite_info
from recovery_platform import compatibility

module = Path(__file__).resolve().parents[1] / 'lifecycle/update_contract.py'
if not module.exists(): module = Path(__file__).resolve().parents[2] / 'build/updates/contract.py'
spec = importlib.util.spec_from_file_location('philcst_installed_update_contract', module)
c = importlib.util.module_from_spec(spec); spec.loader.exec_module(c)
REPOSITORY = 'JonelleNequia/WEB-BASED-CCTV_AND_RFID_VEHICLE_MONITORING_SYSTEM'
INDEX = 'philcst-release-index.v1.json'
MAX_PACKAGE = 4 * 1024**3
MAX_CACHE = 8 * 1024**3


def now(): return dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
def stamp(value): return value.strftime('%Y-%m-%dT%H:%M:%SZ')
def binding(info):
    value = compatibility(info)
    return {k: value[k] for k in ('platform', 'version', 'source_sha256', 'commit', 'build_identity', 'schema_id', 'runtime_id')}

def installed_state(adapter):
    b = adapter.source_binding()
    info=load(adapter.p['release']/'BUILD-INFO.json')
    channel='development' if info.get('channel')=='development' else ('prerelease' if c.Version(b['version']).pre else 'stable')
    import platform
    return {'schema': 'philcst.installation-update-state.v1', 'product': c.PRODUCT,
            'environment': 'fixture' if adapter.fixture else 'production', 'platform': b['platform'],
            'os_version': adapter.os_version(), 'version': b['version'],
            'channel': channel,
            'source': {'commit': b['commit'], 'sha256': b['source_sha256'], 'build_identity': b['build_identity']},
            'schema_id': b['schema_id'], 'runtime_id': b['runtime_id'], 'max_expanded_bytes': 16 * 1024**3}


def validate_index(envelope, trust, php, channel, platform, floor, clock, fixture=False):
    c.fields(envelope, ['signed', 'signatures']); body = envelope['signed']
    c.fields(body, ['schema', 'product', 'repository', 'channel', 'sequence', 'trust_generation', 'issued_at', 'expires_at', 'releases'])
    require(body['schema'] == 'philcst.release-index.v1' and body['product'] == c.PRODUCT and body['repository'] == REPOSITORY, 'Wrong authenticated release index')
    require(body['channel'] == channel, 'Index channel was not selected')
    c.authenticate(envelope, trust, php, channel=channel, platform=platform, fixture=fixture, now=clock)
    require(c.timestamp(body['issued_at']) <= clock < c.timestamp(body['expires_at']), 'Release index expired/not current; obtain fresh signed online or offline metadata')
    require(c.timestamp(body['expires_at']) - c.timestamp(body['issued_at']) <= dt.timedelta(days=90), 'Excessive index lifetime')
    require(type(body['sequence']) is int and body['sequence'] >= floor.get('sequence', 0), 'Replayed release index')
    require(body['trust_generation'] == trust['generation'], 'Index trust generation differs from installed trust')
    if body['sequence'] == floor.get('sequence', 0): require(sha(canonical(body)) == floor.get('sha256'), 'Conflicting same-sequence release index')
    require(isinstance(body['releases'], list) and len(body['releases']) <= 50, 'Release index exceeds local limits')
    for release in body['releases']:
        c.fields(release, ['tag', 'manifest', 'manifest_sha256', 'notes'])
        require(isinstance(release['tag'], str) and __import__('re').fullmatch(r'v[0-9A-Za-z.+-]{1,100}', release['tag']), 'Invalid published release tag')
        c.safe_path(release['manifest']); require('/' not in release['manifest'] and release['manifest'].endswith('.update.json'), 'Invalid manifest asset name')
        c.digest(release['manifest_sha256']); require(isinstance(release['notes'], str) and len(release['notes']) <= 12000, 'Oversized release notes')
    return body


def exact_path(package, state, schema):
    info = load(package / 'BUILD-INFO.json')
    paths = c.load(package / 'UPDATE-PATHS.json')
    c.fields(paths, ['schema', 'product', 'paths'])
    require(paths['schema'] == 'philcst.upgrade-paths.v1' and paths['product'] == c.PRODUCT, 'Missing exact upgrade path contract')
    require(isinstance(paths['paths'], list) and len(paths['paths']) <= 50, 'Invalid exact path inventory')
    source = {'version': state['version'], 'platform': state['platform'], 'source': state['source'], 'schema_id': state['schema_id'], 'runtime_id': state['runtime_id'], 'database_schema_sha256': schema['schema_sha256']}
    found = [p for p in paths['paths'] if p.get('from') == source]
    require(len(found) == 1, 'This exact source/database/runtime upgrade origin is not certified')
    c.fields(found[0], ['from', 'to', 'database_schema_sha256', 'service_contract'])
    require(found[0]['to'] == binding(info) and found[0]['service_contract'] == 'D4-D5A-D5B-v1', 'Target/service contract changed')
    c.digest(found[0]['database_schema_sha256'])
    return found[0]
