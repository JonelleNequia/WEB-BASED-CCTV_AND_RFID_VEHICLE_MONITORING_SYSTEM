"""D4 pure network/configuration policy. No OS mutation or live application access."""
import hashlib
import ipaddress
import json
import re
from pathlib import Path

HOST = 'philcst.vms.internal'
URL = 'https://' + HOST
OWNER = 'PHILCST-VMS-D4'
BEGIN = '# BEGIN ' + OWNER
END = '# END ' + OWNER
PRIVATE = tuple(ipaddress.ip_network(n) for n in ('10.0.0.0/8','172.16.0.0/12','192.168.0.0/16'))
DEFAULT = {'schema':1,'mode':'local','address':None,'interface':None,'remote_scope':None}


def require(ok, message):
    if not ok: raise ValueError(message)


def lan_address(value):
    try: address = ipaddress.IPv4Address(value)
    except (ValueError,TypeError) as error: raise ValueError('Use a stable RFC1918 IPv4 LAN address; IPv6 sharing is not configured by this helper.') from error
    require(any(address in n for n in PRIVATE),'Use a private RFC1918 LAN address, not loopback/public/link-local/multicast.')
    return str(address)


def validate(config, interfaces=None):
    require(type(config) is dict and set(config)==set(DEFAULT),'Unknown/missing network configuration fields')
    require(config['schema']==1 and config['mode'] in ('local','lan'),'Unsupported network mode/schema')
    if config['mode']=='local':
        require(all(config[k] is None for k in ('address','interface','remote_scope')),'Local-only mode must have no LAN interface/address/scope')
        return dict(config)
    address=lan_address(config['address']);interface=config['interface']
    require(type(interface) is str and re.fullmatch(r'[A-Za-z0-9 _().:-]{1,80}',interface),'Select a real LAN interface name')
    try: scope=ipaddress.IPv4Network(config['remote_scope'],strict=True)
    except (ValueError,TypeError) as error: raise ValueError('Use an explicit IPv4 subnet for authorized LAN clients') from error
    require(any(scope.subnet_of(n) for n in PRIVATE) and ipaddress.IPv4Address(address) in scope,'LAN address/scope mismatch or overly broad/public scope')
    require(scope.prefixlen<=30 and ipaddress.IPv4Address(address) not in (scope.network_address,scope.broadcast_address),'Use a usable host address/subnet')
    if interfaces is not None:
        candidates=[x for x in interfaces if x['name']==interface and x['address']==address]
        require(len(candidates)==1,'Selected address is not uniquely assigned to the selected interface; configure a DHCP reservation/static address separately')
        actual=ipaddress.IPv4Network(address+'/'+str(candidates[0]['prefix']),strict=False)
        require(scope.subnet_of(actual),'Remote scope must lie within the selected interface subnet')
    return dict(config)


def hosts_plan(original, address=None):
    """Replace/remove only our exact block; refuse unowned references including IPv6."""
    require(type(original) is str and '\x00' not in original,'Invalid hosts file')
    lines=original.splitlines(keepends=True);owned=False;found=False;kept=[]
    for line in lines:
        stripped=line.strip()
        if stripped==BEGIN:
            require(not owned and not found,'Duplicate/malformed managed hosts block');owned=True;found=True;continue
        if stripped==END:
            require(owned,'Malformed managed hosts block');owned=False;continue
        if owned:
            body=line.split('#',1)[0].split()
            require(not body or len(body)==2 and body[1].lower()==HOST,'Managed hosts block contains unrelated content; repair manually')
            continue
        body=line.split('#',1)[0].split()
        if address is not None:
            require(HOST not in [x.lower().rstrip('.') for x in body[1:]],'Existing unowned IPv4/IPv6 mapping for '+HOST+'; resolve it explicitly before enrollment')
        kept.append(line)
    require(not owned,'Unclosed managed hosts block')
    text=''.join(kept)
    if address is not None:
        require(address=='127.0.0.1' or lan_address(address)==address,'Invalid managed hosts address')
        eol='\r\n' if '\r\n' in original else '\n'
        if text and not text.endswith(('\n','\r')):text+=eol
        text+=BEGIN+eol+address+'\t'+HOST+eol+END+eol
    return text


def dns_matches(addresses, expected):
    require(bool(addresses) and set(addresses)=={expected},'DNS must resolve only to the chosen IPv4 address; conflicting A/AAAA records require administrator correction')


def env_plan(original, config):
    config=validate(config)
    values={'APP_URL':URL,'SESSION_SECURE_COOKIE':'true','SESSION_HTTP_ONLY':'true','SESSION_SAME_SITE':'lax','SESSION_DOMAIN':'null',
        'PHILCST_NAMED_HTTPS':'true','PHILCST_LAN_IP':config['address'] or '127.0.0.1',
        'PYTHON_INTEGRATION_URL':'http://127.0.0.1:8080','DETECTOR_STREAM_HOST':'127.0.0.1','DETECTOR_STREAM_PROXY':'true',
        'GO2RTC_WEBRTC_HOST':config['address'] or '127.0.0.1','MONITORING_SERVICES':'managed'}
    text=original
    for key,value in values.items():
        pattern=r'(?m)^'+key+r'=[^\r\n]*(\r?)$'
        if re.search(pattern,text):text=re.sub(pattern,lambda match:key+'='+value+match[1],text)
        else:text+=('' if text.endswith('\n') or not text else '\n')+key+'='+value+'\n'
    return text


def caddy_config(template, app_public, tls_dir, log_file, config, windows=False, pinned=False):
    config=validate(config)
    for path in (app_public,tls_dir,log_file):
        require(not re.search(r'[\x00-\x1f"{}`$]',str(path)),'Unsafe generated configuration path')
    tls=str(tls_dir).replace('\\','/')
    root=''
    if pinned:
        root='pki {\n\t\tca local {\n\t\t\troot {\n\t\t\t\tcert "'+tls+'/pki/authorities/local/root.crt"\n\t\t\t\tkey "'+tls+'/pki/authorities/local/root.key"\n\t\t\t}\n\t\t}\n\t}'
    values={'APP_PUBLIC':str(app_public).replace('\\','/'),'TLS_DIR':tls,'LOG_FILE':str(log_file).replace('\\','/'),
        'BIND':'127.0.0.1'+(' '+config['address'] if config['mode']=='lan' else ''),
        'ALLOWED':'127.0.0.1/32'+(' '+config['remote_scope'] if config['mode']=='lan' else ''),
        'FASTCGI':' '.join('127.0.0.1:'+str(n) for n in (range(9001,9005) if windows else [9001])), 'PINNED_ROOT':root}
    text=template
    for key,value in values.items():text=text.replace('{{'+key+'}}',value)
    require(not re.search(r'{{\w+}}',text),'Unresolved Caddy configuration placeholder')
    return text


def atomic_file(path, data, mode=0o600):
    """Same-filesystem atomic file replacement; caller owns parent directory."""
    import os,tempfile
    path=Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.d4-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data if isinstance(data,bytes) else data.encode('utf-8'));f.flush();os.fsync(f.fileno())
        os.chmod(name,mode);os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)


def transaction(changes, apply_os, rollback_os, verify):
    """Narrow D4 configuration transaction; does not migrate/backup application data."""
    before={Path(p):Path(p).read_bytes() if Path(p).exists() else None for p in changes}
    try:
        for path,data in changes.items():atomic_file(path,data)
        apply_os();verify()
    except Exception:
        for path,data in before.items():
            if data is None: path.unlink(missing_ok=True)
            else: atomic_file(path,data)
        rollback_os()
        raise
