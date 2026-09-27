"""
Live connection from this PC to the UHF reader assigned to each station.

- TCP or UDP to the reader (address from Laravel's assignment; if the reader
  moved to a new IP, the latest scan's MAC -> IP map is used).
- Readers in "active" mode push tags; otherwise the inventory command of the
  detected protocol is sent every poll interval ("answer" mode).
- Each tag is sent to Laravel's existing RFID ingest API with the station, so
  the normal RFID / guest pass rules apply unchanged.
- A tag that stays in the field is sent once; it is sent again only after it
  was absent for a while (Laravel's cooldown still applies on top).
- Readers in "TCP client" mode connect to this PC instead (ClientModeListener).
"""

import queue
import select
import socket
import threading
import time
from datetime import datetime, timezone

import requests

from . import uhf
from .paths import CAPTURE_LOG_PATH


def utc_now():
    return datetime.now(timezone.utc).isoformat()


class TagPoster(threading.Thread):
    """
    Posts tags to Laravel on its own thread so socket reads never wait on HTTP.
    """

    def __init__(self, log):
        super().__init__(daemon=True)
        self.log = log
        self.queue = queue.Queue(maxsize=500)
        self.app = {}

    def configure(self, app):
        self.app = app or {}

    def post(self, payload):
        try:
            self.queue.put_nowait(payload)
        except queue.Full:
            self.log("Tag queue full; dropping a read")

    def run(self):
        while True:
            payload = self.queue.get()
            url = self.app.get("rfid_ingest_url")
            if not url:
                self.log("No RFID ingest URL yet; tag not sent")
                continue
            headers = {"Accept": "application/json", "X-Source-Name": "philcst-uhf-reader"}
            if self.app.get("api_key"):
                headers["X-Api-Key"] = self.app["api_key"]
            try:
                response = requests.post(url, json=payload, headers=headers, timeout=6)
                self.log(f"Tag {payload['tag_uid']} -> {payload['scan_location']}: HTTP {response.status_code}")
            except requests.RequestException as error:
                self.log(f"Tag {payload['tag_uid']} could not be sent: {error}")


class CaptureLog:
    """
    Raw bytes from readers, kept small, for diagnosing an unknown data format.
    """

    def __init__(self, max_bytes):
        self.max_bytes = int(max_bytes)
        self.lock = threading.Lock()

    def write(self, source, data, frames):
        line = f"{utc_now()} {source} {len(data)}B {data[:256].hex(' ').upper()}"
        if frames:
            line += " | " + ", ".join(f"{frame.protocol}:{frame.kind}:{frame.epc or frame.command}" for frame in frames[:5])
        with self.lock:
            try:
                CAPTURE_LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
                if CAPTURE_LOG_PATH.exists() and CAPTURE_LOG_PATH.stat().st_size > self.max_bytes:
                    CAPTURE_LOG_PATH.replace(CAPTURE_LOG_PATH.with_suffix(".log.1"))
                with open(CAPTURE_LOG_PATH, "a", encoding="utf-8") as handle:
                    handle.write(line + "\n")
            except OSError:
                pass


class TagFilter:
    """Send a tag when it appears, not on every read while it stays in range."""

    def __init__(self, absent_seconds):
        self.absent_seconds = float(absent_seconds)
        self.last_seen = {}

    def should_send(self, epc):
        now = time.monotonic()
        previous = self.last_seen.get(epc)
        self.last_seen[epc] = now
        if len(self.last_seen) > 2000:
            cutoff = now - self.absent_seconds
            self.last_seen = {key: value for key, value in self.last_seen.items() if value >= cutoff}
        return previous is None or now - previous > self.absent_seconds


class ReaderLink(threading.Thread):
    def __init__(self, station, poster, profiles, capture, resolve_ip, log):
        super().__init__(daemon=True, name=f"reader-{station}")
        self.station = station
        self.poster = poster
        self.profiles = profiles
        self.settings = profiles.get("reader_link", {})
        self.capture = capture
        self.resolve_ip = resolve_ip
        self.log = log
        self.target = None
        self.target_version = 0
        self.lock = threading.Lock()
        self.filter = TagFilter(self.settings.get("repeat_after_absent_seconds", 5))
        self.failures = 0
        self.lost = False
        self.status = {"state": "unassigned"}

    # ------------------------------------------------------------------
    def set_target(self, target):
        """target: dict from device_runtime_config stations.<station>.reader, or None."""
        with self.lock:
            if _target_key(target) != _target_key(self.target):
                self.target = target
                self.target_version += 1
                self.failures = 0
                self.lost = False
                self.status = {"state": "connecting" if target else "unassigned"}

    def snapshot(self):
        with self.lock:
            return dict(self.status)

    def _set(self, **values):
        with self.lock:
            self.status.update(values)

    def take_lost(self):
        """True once after the reader was lost (for a rescan by MAC)."""
        with self.lock:
            lost, self.lost = self.lost, False
            return lost

    # ------------------------------------------------------------------
    def run(self):
        delay = float(self.settings.get("reconnect_min_seconds", 2))
        while True:
            with self.lock:
                target = self.target
                version = self.target_version
            if not target or target.get("work_mode") == "client":
                # Client-mode readers connect to us (ClientModeListener).
                time.sleep(1.0)
                continue

            ip = self.resolve_ip(target) or target.get("ip")
            port = target.get("port")
            if not ip or not port:
                self._set(state="error", last_error="Reader address unknown; waiting for the next scan.")
                time.sleep(3.0)
                continue

            self._set(state="connecting", ip=ip, port=port, transport=target.get("transport") or "tcp")
            try:
                self._session(target, version, ip, int(port))
                delay = float(self.settings.get("reconnect_min_seconds", 2))
            except OSError as error:
                with self.lock:
                    self.failures += 1
                    failures = self.failures
                    if failures == int(self.settings.get("lost_after_failures", 3)):
                        self.lost = True
                self._set(state="disconnected", last_error=str(error) or error.__class__.__name__, since=utc_now())
                self.log(f"{self.station} reader {ip}:{port} failed ({failures}): {error}")
                time.sleep(delay)
                delay = min(delay * 2, float(self.settings.get("reconnect_max_seconds", 30)))

    def _session(self, target, version, ip, port):
        transport = (target.get("transport") or "tcp").lower()
        timeout = float(self.settings.get("connect_timeout_seconds", 3))
        if transport == "udp":
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.bind(("", 0))
            sock.connect((ip, port))
        else:
            sock = socket.create_connection((ip, port), timeout=timeout)
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        sock.setblocking(False)

        decoder = uhf.StreamDecoder(target.get("protocol"))
        poll_after = float(self.settings.get("poll_after_idle_seconds", 2.0))
        poll_every = float(self.settings.get("poll_interval_seconds", 0.3))
        captured = 0
        capture_limit = int(self.settings.get("capture_frames_per_session", 40))
        connected_at = time.monotonic()
        last_data = connected_at
        last_poll = 0.0
        work_mode = target.get("work_mode") or "unknown"
        probe_cycle = []
        undecoded = 0

        with self.lock:
            self.failures = 0
        self._set(state="connected", ip=ip, port=port, transport=transport, since=utc_now(),
                  protocol=decoder.protocol, work_mode=work_mode, last_error=None)
        self.log(f"{self.station} reader connected {transport}://{ip}:{port}")

        try:
            while True:
                with self.lock:
                    if version != self.target_version:
                        return
                ready, _, _ = select.select([sock], [], [], 0.2)
                now = time.monotonic()
                if ready:
                    data = sock.recv(4096)
                    if not data and transport == "tcp":
                        raise ConnectionError("Reader closed the connection")
                    last_data = now
                    frames = decoder.feed(data)
                    # The saved format does not match what arrives: learn it again.
                    undecoded = 0 if frames else undecoded + len(data)
                    if undecoded > 512 and decoder.protocol is not None:
                        self.log(f"{self.station} reader data does not match {decoder.protocol}; detecting the format again")
                        decoder.protocol = None
                        undecoded = 0
                    if captured < capture_limit:
                        self.capture.write(f"{self.station} {ip}:{port}", data, frames)
                        captured += 1
                    self._handle(frames, ip, target)
                    if frames and work_mode == "unknown":
                        work_mode = "answer" if last_poll else "active"
                    self._set(protocol=decoder.protocol, work_mode=work_mode)
                elif decoder.buffer and now - decoder.last_data_at > 0.3:
                    self._handle(decoder.flush(), ip, target)

                # Answer-mode readers are polled all the time; an unknown one
                # only after it stayed silent (it may push tags by itself).
                should_poll = work_mode == "answer" or (work_mode != "active" and now - last_data > poll_after)
                if should_poll and now - last_poll > poll_every:
                    command = self._poll_command(decoder.protocol, probe_cycle)
                    if command:
                        sock.send(command)
                        last_poll = now
        finally:
            sock.close()

    def _poll_command(self, protocol, cycle):
        if protocol and protocol != "text":
            commands = uhf.probe_commands(self.profiles, "inventory", protocol)
            return commands[0][2] if commands else None
        # Protocol not known yet: rotate through the inventory commands.
        if not cycle:
            cycle.extend(command for _p, _purpose, command in uhf.probe_commands(self.profiles, "inventory"))
        if not cycle:
            return None
        command = cycle.pop(0)
        cycle.append(command)
        return command

    def _handle(self, frames, ip, target):
        for frame in frames:
            if frame.kind != "tag" or not frame.epc:
                continue
            self._set(last_tag=frame.epc, last_tag_at=utc_now(), tags_read=self.snapshot().get("tags_read", 0) + 1)
            if not self.filter.should_send(frame.epc):
                continue
            self.poster.post({
                "tag_uid": frame.epc,
                "scan_location": self.station,
                "reader_name": target.get("reader_name") or f"{self.station.capitalize()} UHF Reader",
                "payload_json": {
                    "source": "uhf_ethernet",
                    "protocol": frame.protocol,
                    "rssi": frame.rssi,
                    "antenna": frame.antenna,
                    "reader_ip": ip,
                    "reader_mac": target.get("mac"),
                },
            })


def _target_key(target):
    if not target:
        return None
    return tuple(target.get(key) for key in ("mac", "ip", "port", "transport", "protocol", "work_mode"))


class ClientModeListener(threading.Thread):
    """
    Readers set to "TCP client" / "UDP push" mode connect to the PC. Listen on
    the ports from the profiles and hand the data to the station whose
    assigned reader has that MAC (or IP); remember every source for the scan.
    """

    def __init__(self, ports, route, capture, log):
        super().__init__(daemon=True, name="reader-client-listener")
        self.ports = [int(port) for port in ports]
        self.route = route  # (ip) -> ReaderLink or None
        self.capture = capture
        self.log = log
        self.bound = []
        self.seen = {}
        self.lock = threading.Lock()

    def snapshot(self):
        with self.lock:
            return {"bound": list(self.bound), "seen": dict(self.seen)}

    def run(self):
        servers = []
        udp_sockets = []
        for port in self.ports:
            for kind in ("tcp", "udp"):
                sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM if kind == "tcp" else socket.SOCK_DGRAM)
                try:
                    sock.bind(("", port))
                    if kind == "tcp":
                        sock.listen(4)
                        servers.append(sock)
                    else:
                        udp_sockets.append(sock)
                    sock.setblocking(False)
                    self.bound.append(f"{kind}/{port}")
                except OSError:
                    sock.close()
        if self.bound:
            self.log(f"Listening for client-mode readers on {', '.join(self.bound)}")

        clients = {}
        while True:
            watch = servers + udp_sockets + list(clients)
            if not watch:
                time.sleep(5)
                continue
            ready, _, _ = select.select(watch, [], [], 1.0)
            for sock in ready:
                if sock in servers:
                    try:
                        connection, (ip, _port) = sock.accept()
                        connection.setblocking(False)
                        clients[connection] = (ip, sock.getsockname()[1], uhf.StreamDecoder())
                        self.log(f"Reader connected to this PC from {ip} on port {sock.getsockname()[1]}")
                    except OSError:
                        pass
                    continue
                if sock in udp_sockets:
                    try:
                        data, (ip, _port) = sock.recvfrom(4096)
                    except OSError:
                        continue
                    self._data(ip, sock.getsockname()[1], "udp", data, uhf.StreamDecoder())
                    continue
                ip, port, decoder = clients[sock]
                try:
                    data = sock.recv(4096)
                except OSError:
                    data = b""
                if not data:
                    sock.close()
                    clients.pop(sock, None)
                    continue
                self._data(ip, port, "tcp", data, decoder)

    def _data(self, ip, port, transport, data, decoder):
        frames = decoder.feed(data)
        self.capture.write(f"client {ip}->{transport}/{port}", data, frames)
        with self.lock:
            self.seen[ip] = {
                "port": port, "transport": transport, "protocol": decoder.protocol,
                "tags": bool([frame for frame in frames if frame.kind == "tag"]), "at": utc_now(),
            }
        link = self.route(ip)
        if link is not None:
            target = link.target or {}
            link._set(state="connected", ip=ip, port=port, transport=f"{transport}-client", protocol=decoder.protocol,
                      work_mode="client", since=link.snapshot().get("since") or utc_now())
            link._handle(frames, ip, target)
