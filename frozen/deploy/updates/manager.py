"""Optional preparation only. Installation is a separate privileged explicit command."""
import datetime as dt
import json
import os
from pathlib import Path
import shutil
import time
import uuid
from contracts import c, INDEX, MAX_PACKAGE, MAX_CACHE, canonical, sha, load, require, now, stamp, validate_index
from backup_contract import no_links, file_sha, tree_hash
from coordination import producer_work, admission, phase
from store import Store
from transport import GitHub, Unavailable


class Manager:
    def __init__(self, adapter, transport=None): self.adapter=adapter;self.store=Store(adapter);self.transport=transport or GitHub()

    def compatible(self, value, envelope):
        channel=value['preferences']['channel']
        state=dict(value['state'],os_version=self.adapter.os_version())
        proof=c.validate_metadata(envelope,state,value['trust'],self.adapter.php_verifier(),channel,fixture=self.adapter.fixture,now=now())
        body=envelope['signed']; require(body['package']['size'] <= MAX_PACKAGE,'Package exceeds local download/import limit')
        key=channel+':'+body['version']; observed=value.setdefault('observed',{}).get(key)
        identity={'source':body['source'],'package':body['package']['sha256']}
        require(observed is None or observed == identity,'Known version was replaced with different source/package bytes')
        value['observed'][key]=identity
        return proof

    def check(self, scheduled=False):
        require(admission(self.adapter.p['base']), 'Update preparation pauses during maintenance')
        with producer_work('update preparation',base=self.adapter.p['base']), self.store.owned():
            value=self.store.read()
            if scheduled and (not value['preferences']['automatic_check'] or time.time()<value['next_check']):return {'outcome':'not due'}
            if time.time()<value.get('retry_not_before',0):return {'outcome':'check unavailable; retry backoff active'}
            channel=value['preferences']['channel'];outcome='check unavailable'
            try:
                releases=self.transport.releases()
                indices=[(r['tag_name'],a) for r in releases for a in r['assets'] if a.get('name')==INDEX and a.get('state')=='uploaded']
                require(indices,'No authenticated published release index is available')
                verified=[]
                for tag,asset in indices[:5]:
                    envelope=c.parse(self.transport.stream(self.transport.asset(releases,tag,INDEX),c.MAX_METADATA,asset=True,seconds=30))
                    if envelope.get('signed',{}).get('channel')!=channel:continue
                    body=validate_index(envelope,value['trust'],self.adapter.php_verifier(),channel,value['state']['platform'],value['floors'].get(channel,{}),now(),self.adapter.fixture)
                    verified.append((body['sequence'],envelope,body))
                require(verified,'No authenticated index for selected channel')
                _,index,body=max(verified,key=lambda x:x[0]); manifests=[];incompatible=False;matched=0
                tags=[c.Version(e['tag'][1:]) for e in body['releases']]
                known=value.setdefault('release_floor',{}).get(channel)
                require(not known or (tags and max(tags)>=c.Version(known)), 'Discovery attempts to forget a known release')
                if tags:value['release_floor'][channel]=max(tags).value
                for entry in sorted(body['releases'],key=lambda e:c.Version(e['tag'][1:]),reverse=True)[:12]:
                    if c.Version(entry['tag'][1:])<c.Version(value['state']['version']):continue
                    envelope=c.parse(self.transport.stream(self.transport.asset(releases,entry['tag'],entry['manifest']),c.MAX_METADATA,asset=True,seconds=20))
                    require(sha(canonical(envelope))==entry['manifest_sha256'],'Published manifest does not match signed index')
                    if envelope['signed']['platform']['id']!=value['state']['platform']:continue
                    require(envelope['signed']['version']==entry['tag'][1:],'Manifest version differs from its published tag')
                    c.validate_manifest(envelope['signed']);c.authenticate(envelope,value['trust'],self.adapter.php_verifier(),channel=channel,platform=value['state']['platform'],fixture=self.adapter.fixture,now=now())
                    require(c.timestamp(envelope['signed']['issued_at'])<=now()<c.timestamp(envelope['signed']['expires_at']),'Expired manifest; obtain fresh offline metadata')
                    matched+=1
                    try:proof=self.compatible(value,envelope)
                    except (c.Rejected,ValueError):incompatible=True;continue
                    if proof['status']=='eligible-for-D5-review':manifests.append((envelope,entry))
                value['floors'][channel]={'sequence':body['sequence'],'sha256':sha(canonical(body))}
                if manifests:
                    envelope,entry=max(manifests,key=lambda x:c.Version(x[0]['signed']['version']))
                    candidate={'id':sha(canonical(envelope['signed'])),'manifest':envelope,'tag':entry['tag'],'notes':entry['notes'],'ready':False}
                    prior=value.get('candidate')
                    if prior and prior['id']==candidate['id']:candidate['ready']=prior['ready']
                    value['candidate']=candidate;outcome='update available'
                else:outcome='no compatible update for this installation' if incompatible else ('up to date in authenticated index' if matched else 'no published compatible update')
                value['next_check']=time.time()+21600;value['retry_not_before']=0
            except Exception as error:
                # No raw URL, exception, API credential, remote HTML or app secret in status.
                value['next_check']=time.time()+(error.retry_after if isinstance(error,Unavailable) else 900);value['retry_not_before']=value['next_check'];outcome='check unavailable'
            value['last_check']={'at':stamp(now()),'outcome':outcome};self.store.write(value)
        if scheduled and outcome=='update available' and value['preferences']['automatic_download']:return self.download(value['candidate']['id'])
        return value['last_check']

    def capacity(self, required):
        cache=self.store.base.parent/'update-cache';self.adapter.private(cache)
        used=sum(p.stat().st_size for p in cache.rglob('*') if p.is_file())
        require(used+required<=MAX_CACHE,'Update cache is full; explicitly remove an unused candidate, never backups/outbox')
        require(shutil.disk_usage(cache).free >= required+256*1024**2,'Insufficient update staging space')
        return cache

    def stage(self, value, envelope, source=None, releases=None):
        proof=self.compatible(value,envelope);require(proof['status']=='eligible-for-D5-review','Candidate is already installed')
        body=envelope['signed'];cache=self.capacity(body['package']['size']);candidate=sha(canonical(body));directory=cache/candidate
        if directory.exists():
            self.validate(candidate,value);return candidate
        temporary=cache/('.incomplete-'+uuid.uuid4().hex);self.adapter.private(temporary)
        package=temporary/body['package']['filename']
        with package.open('xb') as out:
            if source is not None:
                no_links(source)
                with Path(source).open('rb') as incoming:
                    count=0
                    for block in iter(lambda:incoming.read(1024**2),b''):
                        count+=len(block);require(count<=body['package']['size'],'Import exceeds its authenticated size');out.write(block)
            else:
                url=self.transport.asset(releases,value['candidate']['tag'],body['package']['filename'])
                self.transport.stream(url,body['package']['size'],destination=out,asset=True,seconds=1800)
            out.flush();os.fsync(out.fileno())
        package.chmod(0o600)
        c.validate_import(envelope,package,dict(value['state'],os_version=self.adapter.os_version()),value['trust'],self.adapter.php_verifier(),value['preferences']['channel'],delivery='local' if source else 'downloaded',fixture=self.adapter.fixture,now=now())
        from coordination import atomic
        atomic(temporary/'manifest.json',envelope)
        from recovery import sync_dir
        sync_dir(temporary);os.rename(temporary,directory);sync_dir(cache)
        return candidate

    def import_local(self, manifest, package, notes='Offline package'):
        require(admission(self.adapter.p['base']), 'Import pauses during maintenance')
        with producer_work('update import',base=self.adapter.p['base']),self.store.owned():
            value=self.store.read();no_links(manifest);envelope=c.load(manifest);candidate=self.stage(value,envelope,source=package)
            value['candidate']={'id':candidate,'manifest':envelope,'tag':None,'notes':notes,'ready':True};value['result']={'action':'import','outcome':'validated'};self.store.write(value)
            return {'candidate':candidate,'ready':True,'installed':False}

    def download(self, candidate):
        require(admission(self.adapter.p['base']), 'Download pauses during maintenance')
        with producer_work('update download',base=self.adapter.p['base']),self.store.owned():
            value=self.store.read();require(value['candidate'] and candidate==value['candidate']['id'],'Candidate selection changed')
            try:
                value['result']={'action':'download','outcome':'downloading','bytes_total':value['candidate']['manifest']['signed']['package']['size']}
                self.store.write(value)
                self.stage(value,value['candidate']['manifest'],releases=self.transport.releases());value['candidate']['ready']=True;value['result']={'action':'download','outcome':'validated'}
            except Exception:
                value['candidate']['ready']=False;value['candidate']['install_ready']=False
                value['result']={'action':'download','outcome':'download unavailable or invalid; incomplete files are not installable'};self.store.write(value);raise ValueError(value['result']['outcome']) from None
            self.store.write(value);return {'candidate':candidate,'ready':True,'installed':False}

    def validate(self,candidate,value=None):
        c.digest(candidate);value=value or self.store.read();directory=self.store.base.parent/'update-cache'/candidate;no_links(directory)
        envelope=c.load(directory/'manifest.json');require(sha(canonical(envelope['signed']))==candidate,'Staged identity changed')
        self.compatible(value,envelope)
        package=directory/envelope['signed']['package']['filename']
        self.last_validation=c.validate_import(envelope,package,dict(value['state'],os_version=self.adapter.os_version()),value['trust'],self.adapter.php_verifier(),value['preferences']['channel'],fixture=self.adapter.fixture,now=now())
        return envelope,package

    def preferences(self,automatic_check=False,automatic_download=False,channel='stable'):
        require(channel in c.CHANNELS,'Invalid update channel')
        require(not automatic_download or automatic_check,'Automatic downloads require automatic checking')
        with self.store.owned():
            value=self.store.read();value['preferences']={'automatic_check':bool(automatic_check),'automatic_download':bool(automatic_download),'channel':channel};value['candidate']=None;value['next_check']=0;self.store.write(value)

    def cleanup(self,candidate):
        require(__import__('re').fullmatch(r'(?:[a-f0-9]{64}|\.incomplete-[a-f0-9]{32})',candidate),'Invalid cache identifier')
        with self.store.owned():
            value=self.store.read();require(not value['candidate'] or candidate!=value['candidate']['id'],'Selected candidate cannot be removed')
            path=self.store.base.parent/'update-cache'/candidate;no_links(path);require(path.is_dir(),'Unknown cached candidate');shutil.rmtree(path)
        return {'removed_cache_only':candidate}
