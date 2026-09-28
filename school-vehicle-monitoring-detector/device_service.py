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
    SCAN_RESULT_PATH,
    SERVICE_LOG_PATH,
    STATUS_PATH,
    load_profiles,
    load_runtime_config,
    write_json_atomic,
)
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
            result = scanner.run(trigger=trigger, known=self.devices_by_mac(), light=light)
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


def main():
    parser = argparse.ArgumentParser(description="PHILCST camera and UHF reader detection")
    parser.add_argument("--scan-once", action="store_true", help="scan once and exit")
    parser.add_argument("--verbose", "-v", action="store_true", help="print every step")
    parser.add_argument("--json", action="store_true", help="print the scan result as JSON")
    parser.add_argument("--post", action="store_true", help="send the scan result to Laravel")
    parser.add_argument("--target", action="append", default=[], help="also probe this IP (repeatable)")
    parser.add_argument("--allow-temp-ip", action="store_true", help="use a temporary IP for other subnets (admin)")
    parser.add_argument("--listen", help="IP:PORT of a reader to listen to")
    parser.add_argument("--transport", choices=["tcp", "udp"], default="tcp")
    parser.add_argument("--seconds", type=int, default=30)
    args = parser.parse_args()

    # Diagnostics print to the console only (they may run with sudo, and a
    # root-owned log file would block the background service).
    setup_logging(args.verbose or args.scan_once or bool(args.listen), to_file=not (args.scan_once or args.listen))
    profiles = load_profiles()

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
