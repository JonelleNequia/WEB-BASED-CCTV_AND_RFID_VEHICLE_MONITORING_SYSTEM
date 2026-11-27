"""Build-time only, immutable input fetches. Installed machines never call this."""
import concurrent.futures
import hashlib
import json
from pathlib import Path
import sys
import urllib.parse
import urllib.request


def download(entry, cache):
    name = Path(urllib.parse.unquote(urllib.parse.urlparse(entry['url']).path)).name
    target = cache / name
    if target.is_file() and hashlib.sha256(target.read_bytes()).hexdigest() == entry['sha256']:
        return name
    temporary = target.with_suffix(target.suffix + '.partial')
    try:
        with urllib.request.urlopen(entry['url'], timeout=120) as response, temporary.open('wb') as out:
            while data := response.read(1024 * 1024):out.write(data)
        if hashlib.sha256(temporary.read_bytes()).hexdigest() != entry['sha256']:
            raise ValueError('Wheel checksum mismatch: ' + name)
        temporary.replace(target)
        return name
    finally:
        temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    source, cache = Path(sys.argv[1]), Path(sys.argv[2]);cache.mkdir(parents=True, exist_ok=True)
    rows = json.loads(source.read_text())['packages']
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        for name in pool.map(lambda x: download(x, cache), rows):print('Verified wheel:', name, flush=True)
