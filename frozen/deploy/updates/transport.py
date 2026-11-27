"""Bounded anonymous published-release transport; no installation or secret access."""
import json
import os
import sys
from pathlib import Path
import subprocess
sys.path.insert(0,str(Path(__file__).resolve().parent))
import ssl
import time
import urllib.request
import urllib.parse
import urllib.error
from contracts import REPOSITORY, MAX_PACKAGE


class Unavailable(RuntimeError):
    def __init__(self, retry_after=300): self.retry_after = min(21600, max(60, retry_after)); super().__init__('Check unavailable; installed monitoring remains operational')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None


class GitHub:
    def __init__(self, opener=None, monotonic=time.monotonic, isolated=False):
        try:
            import certifi
            context=ssl.create_default_context(cafile=certifi.where())
        except ImportError:context=ssl.create_default_context()
        self.direct=opener is not None or isolated
        self.opener = opener or urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=context))
        self.monotonic = monotonic

    def allowed(self, url, asset=False):
        p = urllib.parse.urlsplit(url)
        if p.scheme != 'https' or p.username or p.password or p.port not in (None, 443) or p.fragment: raise Unavailable()
        if p.hostname == 'api.github.com':
            if not p.path.startswith('/repos/' + REPOSITORY + '/releases'): raise Unavailable()
        elif p.hostname == 'github.com':
            if not asset or not p.path.startswith('/' + REPOSITORY + '/releases/download/'): raise Unavailable()
        elif p.hostname in ('release-assets.githubusercontent.com', 'objects.githubusercontent.com'):
            if not asset: raise Unavailable()
        else: raise Unavailable()
        return url

    def stream(self, url, limit, destination=None, asset=False, seconds=120):
        self.allowed(url, asset)
        if not self.direct:
            args=[sys.executable,'-I','-B',str(Path(__file__).resolve()),url,str(limit),str(seconds),'1' if asset else '0']
            env={k:v for k,v in os.environ.items() if k in ('SystemRoot','WINDIR')}
            env.update(PYTHONDONTWRITEBYTECODE='1',PYTHONNOUSERSITE='1')
            try:
                result=subprocess.run(args,stdout=destination if destination is not None else subprocess.PIPE,stderr=subprocess.PIPE,env=env,timeout=seconds+5)
            except (OSError,subprocess.TimeoutExpired):raise Unavailable() from None
            if result.returncode:
                try:retry=int(result.stderr.decode().strip())
                except (ValueError,UnicodeError):retry=300
                raise Unavailable(retry)
            return result.stdout if destination is None else destination.tell()
        deadline = self.monotonic() + seconds
        for _ in range(4):
            if self.monotonic()>=deadline:raise Unavailable()
            request = urllib.request.Request(url, headers={'User-Agent': 'PHILCST-VMS-Updater/1', 'Accept': 'application/octet-stream' if asset else 'application/vnd.github+json', 'X-GitHub-Api-Version': '2026-03-10'})
            try: response = self.opener.open(request, timeout=min(10,max(.1,deadline-self.monotonic())))
            except urllib.error.HTTPError as error:
                if error.code in (301,302,303,307,308):
                    url = urllib.parse.urljoin(url, error.headers.get('Location', '')); error.close(); self.allowed(url, asset); continue
                retry = error.headers.get('Retry-After', '300')
                try: retry = int(retry)
                except ValueError: retry = 300
                error.close(); raise Unavailable(retry) from None
            except (OSError, urllib.error.URLError): raise Unavailable() from None
            data = bytearray(); count = 0
            try:
                if int(response.headers.get('Content-Length', '0')) > limit: raise Unavailable()
                with response:
                    while True:
                        if self.monotonic() >= deadline: raise Unavailable()
                        block = (response.read1 if hasattr(response,'read1') else response.read)(128 * 1024)
                        if not block: break
                        count += len(block)
                        if count > limit: raise Unavailable()
                        if destination: destination.write(block)
                        else: data.extend(block)
                return bytes(data) if destination is None else count
            except (OSError, ValueError): raise Unavailable() from None
        raise Unavailable()

    def releases(self):
        raw = self.stream('https://api.github.com/repos/' + REPOSITORY + '/releases?per_page=50', 2 * 1024**2, seconds=30)
        try: values = json.loads(raw)
        except ValueError: raise Unavailable() from None
        if not isinstance(values, list) or len(values) > 50: raise Unavailable()
        return [r for r in values if not r.get('draft') and r.get('published_at') and isinstance(r.get('assets'), list)]

    def asset(self, releases, tag, name):
        matches = [a for r in releases if r.get('tag_name') == tag for a in r['assets'] if a.get('name') == name and a.get('state') == 'uploaded']
        if len(matches) != 1: raise Unavailable()
        url = matches[0].get('browser_download_url', '')
        self.allowed(url, True)
        return url


if __name__=='__main__':
    import threading
    try:
        url,limit,seconds,asset=sys.argv[1:];seconds=int(seconds)
        watchdog=threading.Timer(seconds+1,lambda:os._exit(75));watchdog.daemon=True;watchdog.start()
        GitHub(isolated=True).stream(url,int(limit),destination=sys.stdout.buffer,asset=asset=='1',seconds=seconds)
    except Unavailable as error:
        print(error.retry_after,file=sys.stderr);sys.exit(75)
