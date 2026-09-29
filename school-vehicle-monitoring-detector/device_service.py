"""
Plug-and-detect device service (cameras and UHF RFID readers).

Service mode (started by Laravel, like the detector):
    python device_service.py
  - watches the network (link up/down, new IP, subnet or gateway) and scans
    automatically when it changes; "Waiting for LAN connection" when no cable
  - scans again when Settings asks ("Scan again") or an assigned reader is lost
  - keeps the station readers connected and sends their tags to Laravel
  - writes storage/app/devices/device_service_status.json every 2 seconds

Diagnostics:
    python device_service.py --scan-once --verbose [--target IP] [--post]
    python device_service.py --listen IP:PORT [--transport udp] [--seconds 30]
    python device_service.py --dump [entrance|exit|IP:PORT] [--seconds 30]
"""

import os
import sys


def close_inherited_descriptors():
    """
    Same as camera_service.py: drop descriptors inherited from the PHP server
    that launched us, so its listening socket is not kept open.
    """
    if os.name != "posix":
        return
    try:
        import resource

        soft_limit, _ = resource.getrlimit(resource.RLIMIT_NOFILE)
    except (ImportError, OSError, ValueError):
        soft_limit = 1024
    upper = 4096 if soft_limit in (-1, 0) else min(int(soft_limit), 4096)
    os.closerange(3, upper)


if __name__ == "__main__":
    close_inherited_descriptors()

import argparse  # noqa: E402
import atexit
import json
import logging
import signal
import socket
import threading
import time
from logging.handlers import RotatingFileHandler

import psutil
import requests

from devices import netinfo, uhf
from devices.paths import (
    LOCK_PATH,
    RAW_TAP_LOG_PATH,
    RAW_TAP_REQUEST_PATH,
    SCAN_RESULT_PATH,
    SERVICE_LOG_PATH,
    STATUS_PATH,
    load_profiles,
    load_runtime_config,
    write_json_atomic,
)
from devices.find import FindReaderWizard
from devices.identify import ReaderIdentifier
from devices.reader_link import CaptureLog, ClientModeListener, ReaderLink, TagPoster, utc_now
from devices.scanner import Scanner

STATIONS = ("entrance", "exit")
STATUS_EVERY_SECONDS = 2.0

logger = logging.getLogger("devices")


def setup_logging(verbose, to_file=True):
    logger.setLevel(logging.INFO)
    formatter = logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S")
    try:
        if not to_file:
            raise OSError("console only")
        SERVICE_LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
        handler = RotatingFileHandler(SERVICE_LOG_PATH, maxBytes=1_000_000, backupCount=3, encoding="utf-8")
        handler.setFormatter(formatter)
        logger.addHandler(handler)
    except OSError:
        pass
    if verbose:
        console = logging.StreamHandler(sys.stdout)
        console.setFormatter(logging.Formatter("%(message)s"))
        logger.addHandler(console)


def log(message):
    logger.info(message)


def post_scan(result, runtime):
    app = runtime.get("app") or {}
    url = app.get("devices_url")
    if not url:
        return False, "Laravel devices URL is not configured yet."
    headers = {"Accept": "application/json", "X-Source-Name": "philcst-device-service"}
    if app.get("api_key"):
        headers["X-Api-Key"] = app["api_key"]
    try:
        response = requests.post(url, json=result, headers=headers, timeout=15)
    except requests.RequestException as error:
        return False, str(error)
    if response.status_code >= 300:
        return False, f"HTTP {response.status_code}: {response.text[:200]}"
    return True, None


def known_devices(result):
    return {device["mac"]: device for device in (result or {}).get("devices", []) if device.get("mac")}


def load_last_scan():
    try:
        return json.loads(SCAN_RESULT_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None


# ---------------------------------------------------------------------------
# Service
# ---------------------------------------------------------------------------

class DeviceService:
    def __init__(self, profiles):
        self.profiles = profiles
        self.scan_settings = profiles.get("scan", {})
        self.lock = threading.Lock()
        self.runtime = load_runtime_config()
        self.runtime_mtime = None
        self.last_result = load_last_scan()
        self.scan_thread = None
        self.scan_progress = None
        self.scan_error = None
        self.scan_trigger = None
        self.scan_started_at = None
        self.last_scan_at = 0.0
        self.last_lost_scan_at = 0.0
        self.pending_post = False
        self.last_post_error = None
        self.handled_request = (self.runtime.get("scan_request") or {}).get("id")
        self.handled_identify = (self.runtime.get("identify_request") or {}).get("id")
        self.identifier = None
        self.identify_last = None
        self.handled_find = (self.runtime.get("find_request") or {}).get("id")
        self.finder = None
        self.network = netinfo.snapshot(profiles)
        self.pending_network = None
        self.pending_network_at = 0.0

        self.capture = CaptureLog(profiles.get("reader_link", {}).get("capture_log_max_bytes", 1048576))
        self.poster = TagPoster(log)
        self.poster.configure(self.runtime.get("app"))
        self.links = {
            station: ReaderLink(station, self.poster, profiles, self.capture, self.resolve_ip, log)
            for station in STATIONS
        }
        self.listener = ClientModeListener(
            profiles.get("uhf_reader", {}).get("client_mode_listen_ports", []),
            self.route_client, self.capture, log,
        )

    # -- helpers --------------------------------------------------------
    def devices_by_mac(self):
        return known_devices(self.last_result)

    def resolve_ip(self, target):
        """Latest IP for the reader's MAC (the reader may have a new DHCP address)."""
        mac = (target or {}).get("mac")
        device = self.devices_by_mac().get(mac) if mac else None
        return device.get("ip") if device and device.get("reachable", True) else None

    def busy_readers(self):
        """{ip: mac} of readers a link is connected to (not probed by scans)."""
        busy = {}
        for link in self.links.values():
            state = link.snapshot()
            if state.get("state") == "connected" and state.get("ip"):
                busy[state["ip"]] = (link.target or {}).get("mac")
        return busy

    def route_client(self, ip):
        mac = netinfo.arp_table().get(ip)
        for link in self.links.values():
            target = link.target or {}
            if target and (target.get("ip") == ip or (mac and target.get("mac") == mac)):
                return link
        return None

    def reload_runtime(self):
        try:
            from devices.paths import RUNTIME_CONFIG_PATH

            mtime = RUNTIME_CONFIG_PATH.stat().st_mtime
        except OSError:
            mtime = None
        if mtime == self.runtime_mtime:
            return
        self.runtime_mtime = mtime
        self.runtime = load_runtime_config()
        self.poster.configure(self.runtime.get("app"))
        stations = self.runtime.get("stations") or {}
        for station, link in self.links.items():
            link.set_target((stations.get(station) or {}).get("reader"))

    # -- scanning -------------------------------------------------------
    def request_scan(self, trigger, light=False):
        with self.lock:
            if self.scan_thread and self.scan_thread.is_alive():
                return False
            self.scan_trigger = trigger
            self.scan_started_at = utc_now()
            self.scan_progress = "Starting scan"
            self.scan_error = None
            self.scan_thread = threading.Thread(target=self._scan, args=(trigger, light), daemon=True)
            self.scan_thread.start()
        log(f"Scan started ({trigger}{', light' if light else ''})")
        return True

    def _scan(self, trigger, light):
        try:
            scanner = Scanner(self.profiles, log=log, progress=self._progress)
            result = scanner.run(trigger=trigger, known=self.devices_by_mac(), light=light, busy=self.busy_readers())
            write_json_atomic(SCAN_RESULT_PATH, result)
            with self.lock:
                self.last_result = result
                self.pending_post = True
            self.post_pending()
        except Exception as error:  # never let a scan kill the service
            logger.exception("Scan failed")
            with self.lock:
                self.scan_error = str(error)
        finally:
            with self.lock:
                self.last_scan_at = time.monotonic()
                self.scan_progress = None

    def _progress(self, message):
        with self.lock:
            self.scan_progress = message

    def post_pending(self):
        with self.lock:
            if not self.pending_post or not self.last_result:
                return
            result = self.last_result
        ok, error = post_scan(result, self.runtime)
        with self.lock:
            self.pending_post = not ok
            self.last_post_error = error
        log("Scan results sent to Laravel" if ok else f"Could not send scan results: {error}")

    # -- identify reader --------------------------------------------------
    def request_identify(self, request):
        if self.identifier and self.identifier.snapshot().get("running"):
            return
        interfaces = [item for item in self.network.get("interfaces", [])]
        import ipaddress

        def connected(ip):
            try:
                address = ipaddress.IPv4Address(ip)
                return any(address in ipaddress.IPv4Network(item["network"]) for item in interfaces)
            except ValueError:
                return False

        devices = [
            device for device in (self.last_result or {}).get("devices", [])
            if device.get("reachable", True) and device.get("online", True) and not device.get("is_gateway")
            and device.get("kind") != "camera" and connected(device.get("ip"))
        ]
        # Readers never use a private (randomized) MAC; phones and laptops do.
        candidates = [{"ip": d["ip"], "mac": d.get("mac")} for d in devices if not d.get("randomized_mac")]
        seconds = int(request.get("seconds") or self.profiles.get("uhf_reader", {}).get("identify_seconds", 45))
        log(f"Identify reader: {len(candidates)} candidate(s) {[c['ip'] for c in candidates]} for {seconds}s")
        self.identifier = ReaderIdentifier(
            self.profiles, candidates, seconds, log,
            client_seen=lambda: self.listener.snapshot().get("seen", {}),
        )
        self.identify_last = None
        threading.Thread(target=self._identify, args=(request.get("id"),), daemon=True).start()

    def _identify(self, request_id):
        result = self.identifier.run()
        result["request_id"] = request_id
        self.identify_last = result
        if not result.get("found"):
            return
        # Record the reader so Settings shows it as a confirmed RFID reader.
        by_ip = {device.get("ip"): device for device in (self.last_result or {}).get("devices", [])}
        devices = []
        for found in result["found"]:
            device = dict(by_ip.get(found["ip"]) or {"ip": found["ip"], "mac": found.get("mac"), "reachable": True, "online": True})
            device.update({
                "kind": "rfid_reader", "confidence": "confirmed", "name": "UHF RFID reader",
                "reader": {
                    "transport": found["transport"].split("-")[0], "port": found.get("port"), "protocol": found.get("protocol"),
                    "work_mode": "client" if found["transport"].endswith("client") else "unknown",
                    "confirmed": True, "sample_tags": found.get("tags", []), "raw_sample_hex": found.get("raw_hex"),
                },
            })
            device["key"] = device.get("mac") or f"ip:{device['ip']}"
            devices.append(device)
        ok, error = post_scan({"scan": {"trigger": "identify", "complete": False}, "devices": devices}, self.runtime)
        log("Identified reader sent to Laravel" if ok else f"Could not send identified reader: {error}")

    # -- find my reader (before/after plug-in) ----------------------------
    def request_find(self, request):
        if self.finder and self.finder.snapshot().get("running"):
            return
        seconds = int(request.get("seconds") or 90)
        log(f"Find my reader: started for {seconds}s")
        self.finder = FindReaderWizard(self.profiles, seconds, log,
                                       client_seen=lambda: self.listener.snapshot().get("seen", {}))
        threading.Thread(target=self._find, daemon=True).start()

    def _find(self):
        result = self.finder.run()
        payload = find_payload(result)
        if payload["devices"]:
            ok, error = post_scan(payload, self.runtime)
            log("Find my reader: new device(s) sent to Laravel" if ok else f"Find my reader: could not send results: {error}")

    def identify_status(self):
        if self.identifier and self.identifier.snapshot().get("running"):
            return self.identifier.snapshot()
        return self.identify_last

    def scanning(self):
        return bool(self.scan_thread and self.scan_thread.is_alive())

    # -- status ---------------------------------------------------------
    def write_status(self):
        network = self.network
        result = self.last_result or {}
        wired = network.get("wired_connected")
        if not network.get("interfaces"):
            state, message = "waiting_for_lan", "Waiting for LAN connection. No network is connected."
        elif self.scanning():
            state, message = "scanning", self.scan_progress or "Scanning the network"
        elif not wired:
            state, message = "waiting_for_lan", "Waiting for LAN connection. Only Wi-Fi is connected; devices on the Wi-Fi network are still scanned."
        elif network.get("wired_link_local_only"):
            state, message = "link_local", "LAN cable connected without DHCP (169.254.x.x). Direct cable or no router."
        else:
            state, message = "ready", "Watching the network."

        readers = {}
        stations = self.runtime.get("stations") or {}
        for station, link in self.links.items():
            readers[station] = {**link.snapshot(), "target": (stations.get(station) or {}).get("reader")}

        with self.lock:
            scan = {
                "running": self.scanning(),
                "trigger": self.scan_trigger,
                "started_at": self.scan_started_at,
                "progress": self.scan_progress,
                "error": self.scan_error,
                "last": result.get("scan"),
                "device_count": len(result.get("devices", [])),
                "posted": not self.pending_post,
                "post_error": self.last_post_error,
            }

        write_json_atomic(STATUS_PATH, {
            "service_running": True,
            "updated_at": utc_now(),
            "pid": os.getpid(),
            "platform": netinfo.SYSTEM,
            "admin": netinfo.is_admin(),
            "state": state,
            "message": message,
            "network": network,
            "scan": scan,
            "readers": readers,
            "client_listener": self.listener.snapshot(),
            "handled_scan_request": self.handled_request,
            "temporary_ip_suggestions": result.get("temporary_ip_suggestions", []),
            "diagnostics": result.get("diagnostics"),
            "identify": self.identify_status(),
            "find": self.finder.snapshot() if self.finder else None,
        })

    # -- main loop ------------------------------------------------------
    def run(self):
        self.poster.start()
        for link in self.links.values():
            link.start()
        self.listener.start()
        self.reload_runtime()
        self.request_scan("startup")

        poll_every = float(self.scan_settings.get("network_poll_seconds", 3))
        settle = float(self.scan_settings.get("network_settle_seconds", 4))
        periodic = float(self.scan_settings.get("periodic_scan_seconds", 120))
        lost_gap = float(self.scan_settings.get("device_lost_rescan_min_seconds", 30))
        last_network_check = last_status = last_post_retry = 0.0

        while True:
            now = time.monotonic()
            self.reload_runtime()

            # A: network watcher (debounced so DHCP can finish first).
            if now - last_network_check >= poll_every:
                last_network_check = now
                current = netinfo.snapshot(self.profiles)
                if current["signature"] != self.network["signature"]:
                    if self.pending_network is None or self.pending_network["signature"] != current["signature"]:
                        self.pending_network = current
                        self.pending_network_at = now
                        netinfo.refresh_hardware_ports()
                        log(f"Network changed: {current['signature'] or 'no connection'}")
                    elif now - self.pending_network_at >= settle:
                        self.network = current
                        self.pending_network = None
                        if current["interfaces"]:
                            self.request_scan("network_change")
                        else:
                            log("No network connected; waiting for LAN connection")
                else:
                    self.pending_network = None

            # Scan again requested from Settings.
            find = self.runtime.get("find_request") or {}
            if find.get("id") and find["id"] != self.handled_find:
                self.handled_find = find["id"]
                self.request_find(find)

            identify = self.runtime.get("identify_request") or {}
            if identify.get("id") and identify["id"] != self.handled_identify:
                self.handled_identify = identify["id"]
                self.request_identify(identify)

            request = (self.runtime.get("scan_request") or {}).get("id")
            if request and request != self.handled_request and self.request_scan("manual"):
                self.handled_request = request

            # An assigned reader stopped answering: rescan to find it by MAC.
            if any(link.take_lost() for link in self.links.values()) and now - self.last_lost_scan_at > lost_gap:
                self.last_lost_scan_at = now
                self.request_scan("device_lost")

            if self.network["interfaces"] and not self.scanning() and now - self.last_scan_at > periodic:
                self.request_scan("periodic", light=True)

            if self.pending_post and not self.scanning() and now - last_post_retry > 10:
                last_post_retry = now
                threading.Thread(target=self.post_pending, daemon=True).start()

            if now - last_status >= STATUS_EVERY_SECONDS:
                last_status = now
                try:
                    self.write_status()
                except OSError as error:
                    log(f"Could not write status: {error}")

            time.sleep(0.5)


def find_payload(result):
    """New devices from Find my reader as a (partial) scan for Laravel."""
    devices = []
    for item in result.get("new_devices", []):
        if not item.get("ip") or item.get("randomized_mac"):
            continue
        reader = (item.get("probe") or {}).get("reader") or {}
        found = (reader.get("found") or reader.get("replies") or [None])[0]
        device = {
            "key": item["mac"], "mac": item["mac"], "ip": item["ip"], "reachable": bool(item.get("reachable")),
            "online": True, "vendor": item.get("vendor"), "randomized_mac": False,
            "open_ports": {"tcp": (item.get("probe") or {}).get("open_tcp", [])},
            "discovered_by": ["find-my-reader"] + item.get("seen_by", []),
            "kind": "unknown", "confidence": "none", "name": "New device (Find my reader)",
        }
        if found:
            device.update({
                "kind": "rfid_reader", "confidence": "confirmed", "name": "UHF RFID reader",
                "reader": {"transport": found["transport"].split("-")[0], "port": found.get("port"), "protocol": found.get("protocol"),
                           "work_mode": "unknown", "confirmed": True, "sample_tags": found.get("tags", []),
                           "raw_sample_hex": found.get("raw_hex")},
            })
        elif item.get("reachable") is False:
            device["kind"] = "rfid_reader"
            device["confidence"] = "possible"
            device["name"] = "New device on another network"
        devices.append(device)
    return {"scan": {"trigger": "find-my-reader", "complete": False}, "devices": devices}


def claim_single_instance():
    """Exit if another device service is already running (pid file)."""
    try:
        pid = int(LOCK_PATH.read_text().strip())
        if pid != os.getpid() and psutil.pid_exists(pid):
            cmdline = " ".join(psutil.Process(pid).cmdline())
            if "device_service.py" in cmdline:
                return False
    except (OSError, ValueError, psutil.Error):
        pass
    LOCK_PATH.parent.mkdir(parents=True, exist_ok=True)
    LOCK_PATH.write_text(str(os.getpid()))
    atexit.register(lambda: LOCK_PATH.unlink(missing_ok=True))
    return True


# ---------------------------------------------------------------------------
# Diagnostics
# ---------------------------------------------------------------------------

def scan_once(args, profiles):
    scanner = Scanner(profiles, log=log, progress=lambda message: log(f"... {message}"))
    previous = load_last_scan()
    result = scanner.run(
        trigger="command",
        targets=args.target,
        known=known_devices(previous),
        allow_temp_ip=True if args.allow_temp_ip else None,
    )
    write_json_atomic(SCAN_RESULT_PATH, result)
    if args.post:
        ok, error = post_scan(result, load_runtime_config())
        log("Results sent to Laravel." if ok else f"Could not send results to Laravel: {error}")
    if args.json:
        print(json.dumps(result, indent=2, default=str))
    return 0


def find(args, profiles):
    """Find my reader in the terminal (passive listening works here with sudo)."""
    wizard = FindReaderWizard(profiles, args.seconds, log, passive=not args.no_passive)
    thread = threading.Thread(target=wizard.run, daemon=True)
    thread.start()
    announced = False
    while thread.is_alive():
        state = wizard.snapshot()
        if state["phase"] == "waiting" and not announced:
            print("")
            print(">>> PLUG IN or POWER ON the UHF reader now. Listening for %ss..." % args.seconds, flush=True)
            print("")
            announced = True
        time.sleep(0.5)
    result = wizard.snapshot()
    print("")
    print("RESULT: " + result.get("message", ""))
    for device in result.get("new_devices", []):
        probe = device.get("probe") or {}
        print(f"  new device {device['mac']} ({device.get('vendor') or 'unknown maker'}) ips={device.get('ips')} "
              f"reachable={device.get('reachable')} seen_by={device.get('seen_by')} tcp={probe.get('open_tcp')}")
        if device.get("passive"):
            print(f"    passive: {device['passive']}")
        for key in ("found", "replies", "unknown_data"):
            for entry in (probe.get("reader") or {}).get(key, []):
                print(f"    {key}: {entry}")
    if result.get("other_subnet", {}).get("commands"):
        commands = result["other_subnet"]["commands"].get(netinfo.SYSTEM) or {}
        print(f"  To reach it now: {commands.get('add')}   (remove later: {commands.get('remove')})")
    if args.post:
        payload = find_payload(result)
        if payload["devices"]:
            ok, error = post_scan(payload, load_runtime_config())
            print("Saved to the system." if ok else f"Could not save to the system: {error}")
    return 0


def listen(args, profiles):
    """
    Connect to one reader and print every byte it sends, decoded when possible.
    Sends inventory commands when the reader stays silent.
    """
    host, _, port = args.listen.rpartition(":")
    if not host or not port.isdigit():
        print("Use --listen IP:PORT", file=sys.stderr)
        return 2
    port = int(port)
    transport = args.transport
    if transport == "udp":
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.bind(("", 0))
        sock.connect((host, port))
    else:
        sock = socket.create_connection((host, port), timeout=5)
    sock.settimeout(0.3)
    print(f"Connected to {transport}://{host}:{port}. Hold a tag near the reader. Listening {args.seconds}s...")

    decoder = uhf.StreamDecoder()
    commands = [command for _p, _purpose, command in uhf.probe_commands(profiles, "inventory")]
    info = [command for _p, _purpose, command in uhf.probe_commands(profiles, "info")]
    end = time.monotonic() + args.seconds
    last_data = time.monotonic()
    sent_info = False
    tags = {}
    while time.monotonic() < end:
        try:
            data = sock.recv(4096)
        except (socket.timeout, TimeoutError):
            data = None
        except OSError as error:
            print(f"Connection error: {error}")
            break
        now = time.monotonic()
        if data:
            last_data = now
            frames = decoder.feed(data)
            print(f"RAW {len(data):>4}B {data.hex(' ').upper()}")
            try:
                text = data.decode("ascii")
                if text.strip() and all(31 < ord(c) < 127 or c in "\r\n\t" for c in text):
                    print(f"     text: {text.strip()!r}")
            except UnicodeDecodeError:
                pass
            for frame in frames:
                print(f"     -> {frame.protocol} {frame.kind} cmd={frame.command} epc={frame.epc} rssi={frame.rssi}")
                if frame.epc:
                    tags[frame.epc] = tags.get(frame.epc, 0) + 1
        elif data == b"" and transport == "tcp":
            print("Reader closed the connection.")
            break
        elif now - last_data > 2.0:
            if not sent_info and info:
                for command in info:
                    print(f"SEND {command.hex(' ').upper()} (info)")
                    sock.send(command)
                sent_info = True
            elif commands:
                command = commands[0] if decoder.protocol is None else next(
                    (c for p, _u, c in uhf.probe_commands(profiles, "inventory", decoder.protocol)), commands[0]
                )
                if decoder.protocol is None:
                    commands.append(commands.pop(0))
                print(f"SEND {command.hex(' ').upper()} (inventory)")
                sock.send(command)
            last_data = now
    sock.close()
    print(f"Protocol: {decoder.protocol or 'unknown'}")
    print(f"Tags: {tags or 'none'}")
    return 0


def _print_chunk(stamp, data, frames, discarded=b""):
    clock = time.strftime("%H:%M:%S", time.localtime(stamp)) + f".{int(stamp * 1000) % 1000:03d}"
    print(f"{clock} {len(data):>4}B  {data.hex(' ').upper()}", flush=True)
    for frame in frames:
        if frame.kind == "tag":
            print(f"             -> {frame.protocol} tag  EPC {frame.epc}  RSSI {frame.rssi} dBm", flush=True)
        else:
            print(f"             -> {frame.protocol} frame, unknown command 0x{frame.command:02X}", flush=True)
    if discarded:
        print(f"             !! {len(discarded)} byte(s) not in a valid frame: {discarded[:64].hex(' ').upper()}", flush=True)


def _service_status():
    try:
        status = json.loads(STATUS_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}
    from datetime import datetime

    try:
        age = time.time() - datetime.fromisoformat(status.get("updated_at")).timestamp()
    except (TypeError, ValueError):
        return {}
    return status if status.get("service_running") and age < 15 else {}


def dump(args, profiles):
    """
    Raw hex dump of what a reader sends (troubleshooting). Sends nothing.

    Many readers accept one TCP client only, so when the background service is
    connected to the reader, it copies what it receives (raw tap) instead of
    this command opening a second connection that the reader would refuse.
    """
    target = args.dump
    runtime = load_runtime_config()
    status = _service_status()
    stations = [target] if target in STATIONS else ([] if ":" in target else list(STATIONS))

    connected = [station for station in stations
                 if (status.get("readers", {}).get(station) or {}).get("state") == "connected"]
    if connected:
        station = connected[0] if target in STATIONS else None
        link = status["readers"][connected[0]]
        print(f"The device service is connected to the {connected[0]} reader ({link.get('ip')}:{link.get('port')}); "
              f"showing what it receives for {args.seconds}s. Hold a tag near the reader. Ctrl+C stops.", flush=True)
        start = RAW_TAP_LOG_PATH.stat().st_size if RAW_TAP_LOG_PATH.exists() else 0
        write_json_atomic(RAW_TAP_REQUEST_PATH, {"station": station, "until": time.time() + args.seconds})
        lines = 0
        try:
            with open(RAW_TAP_LOG_PATH, "a+", encoding="utf-8") as handle:
                handle.seek(start)
                end = time.monotonic() + args.seconds
                while time.monotonic() < end:
                    line = handle.readline()
                    if not line:
                        time.sleep(0.2)
                        continue
                    lines += 1
                    stamp, station_name, source, size, rest = (line.rstrip("\n").split(" ", 4) + [""] * 5)[:5]
                    hex_part, _, decoded = rest.partition(" | ")
                    print(f"{time.strftime('%H:%M:%S', time.localtime(float(stamp)))} {station_name} {source} {size}  {hex_part}", flush=True)
                    for item in filter(None, decoded.split(", ")):
                        print(f"             -> {item}", flush=True)
        except KeyboardInterrupt:
            pass
        finally:
            RAW_TAP_REQUEST_PATH.unlink(missing_ok=True)
        print(f"Done: {lines} chunk(s) received." if lines else "Nothing received. No tag in range, or the reader is not in active mode.")
        return 0

    # Direct connection: an address was given, or the service is not connected.
    if ":" in target:
        host, _, port = target.rpartition(":")
        address = (host, int(port)) if port.isdigit() else None
    else:
        reader = next(((runtime.get("stations") or {}).get(station, {}).get("reader") for station in stations
                       if (runtime.get("stations") or {}).get(station, {}).get("reader")), None)
        device = known_devices(load_last_scan()).get((reader or {}).get("mac")) if reader else None
        host = (device or {}).get("ip") or (reader or {}).get("ip")
        address = (host, int(reader["port"])) if host and (reader or {}).get("port") else None
    if not address:
        print("No reader address. Use: --dump IP:PORT, or assign a reader to a station first.", file=sys.stderr)
        return 2

    print(f"Connecting to {address[0]}:{address[1]} (TCP)...", flush=True)
    try:
        sock = socket.create_connection(address, timeout=5)
    except OSError as error:
        print(f"Could not connect: {error or error.__class__.__name__}.")
        print("This reader accepts one TCP client at a time: close its vendor tool or any other program connected to it.")
        return 1
    sock.settimeout(0.3)
    print(f"Connected. Hold a tag near the reader. Listening {args.seconds}s (nothing is sent)...", flush=True)
    decoder = uhf.StreamDecoder()
    tags = {}
    end = time.monotonic() + args.seconds
    try:
        while time.monotonic() < end:
            try:
                data = sock.recv(4096)
            except (socket.timeout, TimeoutError):
                continue
            if not data:
                print("Reader closed the connection.")
                break
            frames = decoder.feed(data)
            _print_chunk(time.time(), data, frames, decoder.take_discarded())
            for frame in frames:
                if frame.epc:
                    tags[frame.epc] = tags.get(frame.epc, 0) + 1
    except KeyboardInterrupt:
        pass
    finally:
        sock.close()
    print(f"Format: {decoder.protocol or 'unknown'}")
    print("Tags: " + (", ".join(f"{epc} x{count}" for epc, count in tags.items()) or "none"))
    return 0


def main():
    parser = argparse.ArgumentParser(description="PHILCST camera and UHF reader detection")
    parser.add_argument("--scan-once", action="store_true", help="scan once and exit")
    parser.add_argument("--verbose", "-v", action="store_true", help="print every step")
    parser.add_argument("--json", action="store_true", help="print the scan result as JSON")
    parser.add_argument("--post", action="store_true", help="send the scan result to Laravel")
    parser.add_argument("--target", action="append", default=[], help="also probe this IP (repeatable)")
    parser.add_argument("--allow-temp-ip", action="store_true", help="use a temporary IP for other subnets (admin)")
    parser.add_argument("--find", action="store_true", help="Find my reader: baseline, plug in, watch for new devices")
    parser.add_argument("--no-passive", action="store_true", help="do not capture raw packets")
    parser.add_argument("--listen", help="IP:PORT of a reader to listen to")
    parser.add_argument("--dump", nargs="?", const="auto", help="raw hex dump: entrance, exit or IP:PORT")
    parser.add_argument("--transport", choices=["tcp", "udp"], default="tcp")
    parser.add_argument("--seconds", type=int, default=30)
    args = parser.parse_args()

    # Diagnostics print to the console only (they may run with sudo, and a
    # root-owned log file would block the background service).
    setup_logging(args.verbose or args.scan_once or bool(args.listen) or args.find,
                  to_file=not (args.scan_once or args.listen or args.find or args.dump))
    profiles = load_profiles()

    if args.dump:
        return dump(args, profiles)
    if args.find:
        return find(args, profiles)
    if args.listen:
        return listen(args, profiles)
    if args.scan_once:
        return scan_once(args, profiles)

    if not claim_single_instance():
        print("Device service already running.", flush=True)
        return 0
    log(f"Device service started (pid {os.getpid()}, {netinfo.SYSTEM}, admin={netinfo.is_admin()})")
    # Stop cleanly on "kill" too, so the status says stopped and Laravel restarts it at once.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    try:
        DeviceService(profiles).run()
    except KeyboardInterrupt:
        pass
    finally:
        try:
            status = json.loads(STATUS_PATH.read_text(encoding="utf-8"))
            status.update({"service_running": False, "updated_at": utc_now(), "message": "Device service stopped."})
            write_json_atomic(STATUS_PATH, status)
        except (OSError, ValueError):
            pass
    return 0


if __name__ == "__main__":
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    sys.exit(main())
