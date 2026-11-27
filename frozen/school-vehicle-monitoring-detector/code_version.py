"""
Which detector code is running.

The detector reports the version of the code it loaded (status
"code_version"); Laravel works out the same version from the files on disk
(DetectorRuntimeService::codeVersion). They differ when the code was
updated but an older detector still runs: System status says so, and the
detector restarts itself as soon as it sees its files change.

The version: the first 12 hex digits of a SHA-1 over every top-level .py
file of this folder (name and content, sorted by name), except the
programs that are not the detector.
"""

import hashlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent
NOT_THE_DETECTOR = {"device_service.py", "camera_service.py", "video_test.py"}


def code_files(root=ROOT):
    return sorted(path for path in Path(root).glob("*.py") if path.name not in NOT_THE_DETECTOR)


def code_version(root=ROOT):
    digest = hashlib.sha1()
    for path in code_files(root):
        digest.update(path.name.encode() + b"\0" + hashlib.sha1(path.read_bytes()).hexdigest().encode() + b"\n")
    return digest.hexdigest()[:12]


def code_stamp(root=ROOT):
    """Cheap check between versions: the files' names, sizes and modification times."""
    return tuple((path.name, path.stat().st_size, path.stat().st_mtime_ns) for path in code_files(root))
