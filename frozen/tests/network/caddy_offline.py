#!/usr/bin/env python3
"""Explicit isolated native Caddy acceptance fixture; no machine trust/hosts mutations.
Uses high loopback ports. This is not native 443/system-install or multi-PC acceptance.
"""
import hashlib
import http.client
import http.server
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time

ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'deploy/network'))
from policy import caddy_config,DEFAULT,HOST


def freeport():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]


def main():
    work=Path(sys.argv[1]);work.mkdir(parents=True,exist_ok=True)
    binary=ROOT/'build/macos/dist/Mac-arm64/PHILCST-VMS/runtime/caddy/caddy'
    if not binary.exists():raise SystemExit('Provide the native D3 Caddy candidate first')
    ports=[freeport() for _ in range(3)];https,internal,backend=ports
    public=work/'public';public.mkdir(exist_ok=True);(public/'index.php').write_text('synthetic placeholder')
    (public/'storage').mkdir(exist_ok=True);(public/'storage/secret.png').write_text('MUST NOT BE STATICALLY SERVED')
    template=(ROOT/'build/macos/templates/Caddyfile.template').read_text()
    text=caddy_config(template,public,work/'tls',work/'logs/access.log',dict(DEFAULT))
    text=text.replace('https://'+HOST+' {','https://'+HOST+':'+str(https)+' {').replace('127.0.0.1:8080','127.0.0.1:'+str(internal))
    # Isolate the config's actual routing/auth boundaries with a fake HTTP app upstream.
    import re
    text=re.sub(r'php_fastcgi 127\.0\.0\.1:9001(?: \{\n(?:[^}]+)\n\t\t\})?', 'reverse_proxy 127.0.0.1:'+str(backend),text)
    # Use the original request path: upstream rewrite to /index.php is PHP-specific.
    text=text.replace('\t\trewrite * /index.php\n','')
    config=work/'Caddyfile';config.write_text(text)
    class App(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            status=200 if self.path=='/up' else (401 if self.path=='/live/proxy-auth' else 418)
            self.send_response(status);self.end_headers();self.wfile.write(b'synthetic app response')
        def log_message(self,*args):pass
    httpd=http.server.ThreadingHTTPServer(('127.0.0.1',backend),App);threading.Thread(target=httpd.serve_forever,daemon=True).start()
    env=os.environ|{'HOME':str(work),'XDG_CONFIG_HOME':str(work/'config'),'XDG_DATA_HOME':str(work/'data')}
    subprocess.run([binary,'validate','--config',config,'--adapter','caddyfile'],env=env,check=True,capture_output=True)
    root=work/'tls/pki/authorities/local/root.crt';root_hash=hashlib.sha256(root.read_bytes()).hexdigest()
    # The production controller explicitly pins this persisted issuer after initialization.
    unpinned=text
    pin=caddy_config('{{PINNED_ROOT}}',public,work/'tls',work/'logs/access.log',dict(DEFAULT),pinned=True)
    text=text.replace('\tservers {','\t'+pin+'\n\tservers {',1);config.write_text(text)
    def start():
        log=(work/'caddy.log').open('a');proc=subprocess.Popen([binary,'run','--config',config,'--adapter','caddyfile'],stdout=log,stderr=log,env=env)
        for _ in range(100):
            if proc.poll() is not None:raise AssertionError('Caddy stopped: '+(work/'caddy.log').read_text()[-1800:])
            try:
                with socket.create_connection(('127.0.0.1',https),timeout=.1):return proc,log
            except OSError:time.sleep(.1)
        raise AssertionError('Caddy did not bind fixture HTTPS')
    def request(path,rootfile=root,hostname=HOST):
        ctx=ssl.create_default_context(cafile=str(rootfile))
        with socket.create_connection(('127.0.0.1',https),timeout=5) as raw:
            with ctx.wrap_socket(raw,server_hostname=hostname) as tls:
                cert=tls.getpeercert(binary_form=True);tls.sendall(('GET '+path+' HTTP/1.0\r\nHost: '+HOST+':'+str(https)+'\r\n\r\n').encode());response=http.client.HTTPResponse(tls);response.begin();return response.status,response.read(),cert
    process,log=start();results={}
    try:
        deadline=time.monotonic()+10
        while True:
            try:status,_,leaf1=request('/up');break
            except ssl.SSLError:
                if time.monotonic()>=deadline:raise
                time.sleep(.1)
        assert status==200
        results['canonical_sni_private_root_https']=True
        if __import__('shutil').which('pwsh'):
            client=subprocess.run(['pwsh','-NoProfile','-File',ROOT/'tests/windows/client.tests.ps1','-RootCertificate',root,'-HttpsPort',str(https)],capture_output=True,text=True)
            (work/'powershell-client.log').write_text(client.stdout+client.stderr)
            assert client.returncode==0,client.stdout+client.stderr
            results['powershell_client_pinned_tls_fixture']=client.stdout.strip()
        fingerprint=subprocess.check_output(['/usr/bin/openssl','x509','-in',root,'-noout','-fingerprint','-sha256'],text=True).strip().split('=')[1].replace(':','').lower()
        shell=subprocess.run(['/bin/sh','-c','. "$1"; validate_root "$2" "$3"; if validate_root "$2" "0000000000000000000000000000000000000000000000000000000000000000"; then exit 9; fi','fixture',ROOT/'deploy/clients/client-common.sh',root,fingerprint],capture_output=True,text=True)
        assert shell.returncode==0,shell.stdout+shell.stderr
        results['mac_shell_public_root_and_wrong_fingerprint_fixture']=True
        status,body,_=request('/storage/secret.png');assert status==418 and b'MUST NOT' not in body
        for path in ['/Storage/secret.png','//STORAGE/secret.png','/storage%5csecret.png']:
            status,body,_=request(path);assert status!=200 and b'MUST NOT' not in body
        results['historical_static_bypass_blocked']=True
        status,_,_=request('/api/v1/integration/status');assert status==404
        conn=http.client.HTTPConnection('127.0.0.1',internal,timeout=5);conn.request('GET','/login');response=conn.getresponse();assert response.status==404;conn.close()
        results['public_integration_and_internal_browser_blocked']=True
        status,_,_=request('/detector/stream/gate-1');assert status==401
        results['unauthenticated_detector_blocked']=True
        try:request('/up',hostname='wrong.invalid');raise AssertionError('Wrong hostname accepted')
        except (ssl.SSLError,ConnectionError):pass
        # Distinct local Caddy CA, no OS trust installation.
        wrong=work/'wrong-root';wrong.mkdir(exist_ok=True)
        badconfig=unpinned.replace(str(work/'tls'),str(wrong/'tls'));(wrong/'Caddyfile').write_text(badconfig)
        subprocess.run([binary,'validate','--config',wrong/'Caddyfile','--adapter','caddyfile'],env=env,check=True,capture_output=True)
        try:request('/up',wrong/'tls/pki/authorities/local/root.crt');raise AssertionError('Wrong root accepted')
        except ssl.SSLCertVerificationError:pass
        results['wrong_hostname_and_root_refused']=True
        # Renewal fixture: replace only the fixture leaf with an expired, same-key/same-SAN
        # leaf signed by this fixture's issuer. Restart Caddy offline and observe renewal.
        process.terminate();process.wait(timeout=10);log.close()
        leaves=list((work/'tls/certificates/local').rglob('*.crt'));assert len(leaves)==1
        leafpath=leaves[0];keypath=leafpath.with_suffix('.key')
        py=Path(os.environ.get('D4_FIXTURE_PYTHON',sys.executable))
        script='''from cryptography import x509
from cryptography.hazmat.primitives import hashes,serialization
from pathlib import Path
from datetime import datetime,timedelta,timezone
import sys
leaf,issuer,key=map(Path,sys.argv[1:]);original=x509.load_pem_x509_certificate(leaf.read_bytes());ca=x509.load_pem_x509_certificate(issuer.read_bytes());signer=serialization.load_pem_private_key(key.read_bytes(),password=None)
b=x509.CertificateBuilder().subject_name(original.subject).issuer_name(ca.subject).public_key(original.public_key()).serial_number(x509.random_serial_number()).not_valid_before(datetime.now(timezone.utc)-timedelta(days=2)).not_valid_after(datetime.now(timezone.utc)-timedelta(days=1))
for extension in original.extensions:b=b.add_extension(extension.value,extension.critical)
leaf.write_bytes(b.sign(signer,hashes.SHA256()).public_bytes(serialization.Encoding.PEM))
'''
        subprocess.run([py,'-B','-c',script,leafpath,work/'tls/pki/authorities/local/intermediate.crt',work/'tls/pki/authorities/local/intermediate.key'],check=True,capture_output=True)
        # Removal needs the recorded identity, even when an owned root has expired.
        expired_root=work/'expired-owned-root.crt';expired_root.write_bytes(root.read_bytes())
        subprocess.run([py,'-B','-c',script,expired_root,root,root.with_suffix('.key')],check=True,capture_output=True)
        expired_fingerprint=subprocess.check_output(['/usr/bin/openssl','x509','-in',expired_root,'-noout','-fingerprint','-sha256'],text=True).strip().split('=')[1].replace(':','').lower()
        removal=subprocess.run(['/bin/sh','-c','. "$1"; validate_root_identity "$2" "$3" || exit 8; if validate_root "$2" "$3"; then exit 9; fi','fixture',ROOT/'deploy/clients/client-common.sh',expired_root,expired_fingerprint],capture_output=True,text=True)
        assert removal.returncode==0,removal.stdout+removal.stderr
        results['expired_owned_root_removal_identity_allowed_but_enrollment_refused']=True
        expired=leafpath.read_bytes();process,log=start()
        deadline=time.monotonic()+20
        while time.monotonic()<deadline:
            try:
                _,_,leaf2=request('/up')
                if leafpath.read_bytes()!=expired and leaf2!=leaf1:break
            except ssl.SSLError:pass
            time.sleep(.2)
        else:raise AssertionError('Expired fixture leaf did not renew')
        assert hashlib.sha256(root.read_bytes()).hexdigest()==root_hash
        results['expired_leaf_renewed_same_issuer_without_public_acme']=True
        results['limits']='High-port loopback fixture, fake app upstream; no hosts/trust/firewall/services/devices modified. Physical WAN was not disabled; issuer/config has no public ACME/STUN endpoint.'
        (work/'results.json').write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))
    finally:
        if process.poll() is None:process.terminate();process.wait(timeout=10)
        log.close();httpd.shutdown();httpd.server_close()

if __name__=='__main__':main()
