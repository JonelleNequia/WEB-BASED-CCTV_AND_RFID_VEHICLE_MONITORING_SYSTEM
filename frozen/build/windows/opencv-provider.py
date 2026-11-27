"""Deterministically select and verify cv2 files from the pinned headless wheel."""
import hashlib
from pathlib import Path
import sys
import zipfile
wheelhouse, target = map(Path, sys.argv[1:])
wheels = list(wheelhouse.glob('opencv_python_headless-*.whl'))
if len(wheels) != 1:raise RuntimeError('Exactly one pinned headless provider required')
with zipfile.ZipFile(wheels[0]) as z:
    names = [n for n in z.namelist() if n.startswith('cv2/') and not n.endswith('/')]
    if not names:raise RuntimeError('Headless wheel has no cv2 payload')
    # Remove files owned only by the competing cv2 payload, without removing dist-info.
    import shutil
    shutil.rmtree(target / 'cv2', ignore_errors=True)
    for name in names:
        f = target / name;f.parent.mkdir(parents=True, exist_ok=True);f.write_bytes(z.read(name))
    for name in names:
        if hashlib.sha256((target/name).read_bytes()).digest() != hashlib.sha256(z.read(name)).digest():raise RuntimeError('cv2 payload differs')
print('Pinned headless cv2 payload verified:',len(names),'files; distribution metadata preserved for dependency closure')
