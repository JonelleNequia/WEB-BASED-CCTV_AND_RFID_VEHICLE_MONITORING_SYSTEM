"""
Camera connection health for the detector.

- rtsp_check(): when OpenCV cannot open an RTSP camera it only says "could not
  open". This asks the camera directly (RTSP DESCRIBE, with the saved login)
  so the status can say *why*: wrong password, wrong stream path, camera not
  reachable, or timeout.
- take_over_stale_detector(): a detector left running from older code (or a
  hung one) keeps the stream port but no longer writes the status Laravel
  reads. Laravel then keeps starting new detectors that exit as duplicates.
  A new detector stops such a stale one (only this project's detector).
"""

import hashlib
import json
import os
import re
import socket
import time
from base64 import b64encode
from datetime import datetime
from urllib.parse import urlparse

ERROR_MESSAGES = {
    "unauthorized": "Camera login rejected (RTSP 401). Enter the camera username and password in Settings › Stations & Readers › Devices.",
    "not_found": "The camera has no stream at this path (RTSP 404). Re-assign the camera in Settings › Devices.",
    "unreachable": "The camera is not reachable from this PC ({reason}). Check its cable and power, or scan again in Settings › Devices.",
    "timeout": "The camera did not answer in time. Check its cable and network.",
    "error": "The camera answered with an error ({reason}).",
}


def rtsp_check(url, username="", password="", timeout=3.0):
    """
    Returns {"code": ok|unauthorized|not_found|unreachable|timeout|error, "message": str}.
    """
    parsed = urlparse(url)
    host = parsed.hostname
    port = parsed.port or 554
    if not host:
        return _result("error", reason="no camera address")
    clean_url = url if "@" not in parsed.netloc else parsed._replace(netloc=parsed.netloc.split("@", 1)[1]).geturl()

    try:
        sock = socket.create_connection((host, port), timeout=timeout)
    except socket.timeout:
        return _result("timeout")
    except OSError as error:
        return _result("unreachable", reason=error.strerror or str(error))

    try:
        sock.settimeout(timeout)
        status, headers = _request(sock, clean_url, 1)
        if status == 401 and username:
            authorization = _authorization(headers.get("www-authenticate", ""), clean_url, username, password)
            if authorization:
                status, headers = _request(sock, clean_url, 2, authorization)
    except socket.timeout:
        return _result("timeout")
    except OSError as error:
        return _result("unreachable", reason=error.strerror or str(error))
    finally:
        sock.close()

    if status == 200:
        return {"code": "ok", "message": "Camera answers and the login is accepted."}
    if status in (401, 403):
        return _result("unauthorized")
    if status == 404:
        return _result("not_found")
    return _result("error", reason=f"RTSP {status}")


def _result(code, reason=""):
    return {"code": code, "message": ERROR_MESSAGES[code].format(reason=reason)}


def _request(sock, url, sequence, authorization=None):
    request = f"DESCRIBE {url} RTSP/1.0\r\nCSeq: {sequence}\r\nAccept: application/sdp\r\nUser-Agent: philcst-detector\r\n"
    if authorization:
        request += f"Authorization: {authorization}\r\n"
    sock.sendall((request + "\r\n").encode())

    data = b""
    while b"\r\n\r\n" not in data and len(data) < 16384:
        chunk = sock.recv(4096)
        if not chunk:
            break
        data += chunk
    head, _, body = data.partition(b"\r\n\r\n")
    lines = head.decode("latin-1", "ignore").split("\r\n")
    match = re.match(r"RTSP/\d\.\d\s+(\d{3})", lines[0] if lines else "")
    headers = {}
    for line in lines[1:]:
        if ":" in line:
            name, value = line.split(":", 1)
            headers[name.strip().lower()] = value.strip()
    # Drain the SDP body so the next request on this socket starts clean.
    remaining = int(headers.get("content-length", "0") or 0) - len(body)
    while remaining > 0:
        chunk = sock.recv(min(remaining, 4096))
        if not chunk:
            break
        remaining -= len(chunk)
    return (int(match.group(1)) if match else None), headers


def _authorization(challenge, url, username, password):
    if challenge.lower().startswith("digest"):
        values = {key.lower(): value for key, value in re.findall(r'(\w+)="?([^",]+)"?', challenge)}
        if "realm" not in values or "nonce" not in values:
            return None
        ha1 = hashlib.md5(f"{username}:{values['realm']}:{password}".encode()).hexdigest()
        ha2 = hashlib.md5(f"DESCRIBE:{url}".encode()).hexdigest()
        response = hashlib.md5(f"{ha1}:{values['nonce']}:{ha2}".encode()).hexdigest()
        return (f'Digest username="{username}", realm="{values["realm"]}", nonce="{values["nonce"]}", '
                f'uri="{url}", response="{response}"')
    if challenge.lower().startswith("basic"):
        return "Basic " + b64encode(f"{username}:{password}".encode()).decode()
    return None


class RtspDiagnosis:
    """Cache one check per camera so a failing camera is not probed every frame."""

    def __init__(self, every_seconds=20.0):
        self.every = every_seconds
        self.cache = {}

    def get(self, role, url, username, password):
        key = (url, username, password)
        cached = self.cache.get(role)
        now = time.monotonic()
        if cached and cached[0] == key and now - cached[1] < self.every:
            return cached[2]
        result = rtsp_check(url, username, password)
        self.cache[role] = (key, now, result)
        return result

    def forget(self, role):
        self.cache.pop(role, None)


def take_over_stale_detector(port, status_path, module_root, stale_after_seconds=20, log=print):
    """
    Stop this project's older detector process when it holds the stream port
    but its status is missing or stale. Returns True when one was stopped.
    """
    if _status_fresh(status_path, stale_after_seconds):
        return False

    try:
        import psutil
    except ImportError:
        return False

    stopped = False
    own_pid = os.getpid()
    for process in psutil.process_iter(["pid", "cmdline"]):
        if process.info["pid"] == own_pid:
            continue
        command = " ".join(process.info.get("cmdline") or [])
        if str(module_root) not in command or not any(name in command for name in ("camera_service.py", "detector_service.py")):
            continue
        try:
            listening = any(
                connection.status == psutil.CONN_LISTEN and connection.laddr and connection.laddr.port == port
                for connection in process.net_connections(kind="tcp")
            )
        except psutil.Error:
            listening = True  # same project, same script: treat as the port owner
        if not listening:
            continue
        try:
            # A detector that started moments ago is still loading: leave it.
            if time.time() - process.create_time() < 60:
                continue
        except psutil.Error:
            continue
        log(f"Stopping stale detector process {process.pid} that holds port {port} without writing status.")
        try:
            process.terminate()
            process.wait(timeout=5)
        except psutil.TimeoutExpired:
            process.kill()
        except psutil.Error:
            continue
        stopped = True
    return stopped


def _status_fresh(status_path, stale_after_seconds):
    try:
        status = json.loads(open(status_path, "r", encoding="utf-8").read())
        updated = datetime.fromisoformat(str(status.get("updated_at")))
        age = (datetime.now(updated.tzinfo) - updated).total_seconds()
        # Any recent write means a detector is alive (also while it is starting).
        return age <= stale_after_seconds
    except (OSError, ValueError, TypeError):
        return False
