"""
"Identify reader" mode: find the UHF reader by its tag data.

While the user holds a UHF tag near the reader, listen to every candidate
device on the connected networks for a short window:

1. Scan a wide range of TCP ports on each candidate (readers use many
   vendor-specific ports that a normal scan does not try).
2. Keep a connection open to every open port and listen; send the inventory
   command of each known protocol every second (readers in "answer" mode).
3. Send the same commands over UDP to the usual reader ports.
4. Also watch readers that connect to this PC (client mode).

The first device that sends a valid tag frame is the reader. A port that
sends data in an unknown format is reported with a hex sample so the parser
can be taught the format.
"""

import asyncio
import socket
import threading
import time
from datetime import datetime, timezone

from . import uhf
from .probes import _other_service


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def identify_ports(profiles):
    ports = set(int(port) for port in profiles.get("uhf_reader", {}).get("tcp_ports", []))
    for start, end in profiles.get("uhf_reader", {}).get("identify_port_ranges", [[1, 10100]]):
        ports.update(range(int(start), int(end) + 1))
    skip = set(int(port) for port in profiles.get("camera", {}).get("rtsp_ports", []))
    return sorted(ports - skip)


class ReaderIdentifier:
    def __init__(self, profiles, candidates, seconds, log, client_seen=None):
        self.profiles = profiles
        self.candidates = candidates  # list of {"ip", "mac"}
        self.seconds = float(seconds)
        self.log = log
        self.client_seen = client_seen or (lambda: {})
        self.lock = threading.Lock()
        self.state = {
            "running": True,
            "started_at": utc_now(),
            "seconds": int(self.seconds),
            "candidates": [item["ip"] for item in candidates],
            "open_ports": {},
            "phase": "Scanning ports",
            "found": [],
            "unknown_data": [],
            "message": "Listening. Hold a UHF tag near the reader.",
        }

    def snapshot(self):
        with self.lock:
            return {**self.state, "open_ports": dict(self.state["open_ports"]), "found": list(self.state["found"]),
                    "unknown_data": list(self.state["unknown_data"])}

    def _set(self, **values):
        with self.lock:
            self.state.update(values)

    def run(self):
        started = time.monotonic()
        try:
            asyncio.run(self._run(started))
        except Exception as error:  # never break the service
            self._set(message=f"Identify stopped: {error}")
            self.log(f"Identify error: {error}")
        finally:
            found = self.snapshot()["found"]
            self._set(
                running=False,
                finished_at=utc_now(),
                phase="Done",
                message=(f"Reader found at {found[0]['ip']} ({found[0]['transport'].upper()} {found[0]['port']}, {found[0]['protocol']})."
                         if found else (
                             "No reader sent tag data. Check that it is powered and on this network, then try again."
                             if self.candidates else
                             "No possible reader on this network: only the router, cameras and phones/laptops answered. "
                             "The reader is not connected here (check its power and LAN cable), or it has a fixed IP on another network."
                         )),
            )
        return self.snapshot()

    async def _run(self, started):
        ports = identify_ports(self.profiles)
        semaphore = asyncio.Semaphore(256)
        timeout = 0.4

        async def probe(ip, port):
            async with semaphore:
                try:
                    _, writer = await asyncio.wait_for(asyncio.open_connection(ip, port), timeout)
                except (OSError, asyncio.TimeoutError):
                    return None
                writer.close()
                return port

        async def scan(item):
            results = await asyncio.gather(*(probe(item["ip"], port) for port in ports))
            found_ports = sorted(port for port in results if port)
            self.log(f"Identify: {item['ip']} open TCP ports {found_ports}")
            with self.lock:
                self.state["open_ports"][item["ip"]] = found_ports
            return item["ip"], found_ports

        # All candidates at once, and never more than a third of the window,
        # so there is time left to listen for the tag.
        open_ports = {}
        budget = max(5.0, self.seconds / 3)
        tasks = [asyncio.create_task(scan(item)) for item in self.candidates]
        done, pending = await asyncio.wait(tasks, timeout=budget) if tasks else (set(), set())
        for task in pending:
            task.cancel()
        for task in done:
            ip, found_ports = task.result()
            open_ports[ip] = found_ports

        self._set(phase="Listening for tag data")
        deadline = started + self.seconds
        listeners = [
            asyncio.create_task(self._listen_tcp(item, port, deadline))
            for item in self.candidates for port in open_ports.get(item["ip"], [])
        ]
        listeners.append(asyncio.create_task(self._listen_udp(deadline)))
        listeners.append(asyncio.create_task(self._watch_clients(deadline)))
        await asyncio.gather(*listeners, return_exceptions=True)

    def _found(self, ip, transport, port, frames, raw):
        tags = sorted({frame.epc for frame in frames if frame.epc})
        if not tags:
            return False
        mac = next((item.get("mac") for item in self.candidates if item["ip"] == ip), None)
        entry = {
            "ip": ip, "mac": mac, "transport": transport, "port": port,
            "protocol": frames[0].protocol, "tags": tags[:5], "raw_hex": raw[:64].hex(" ").upper(),
        }
        with self.lock:
            if any(item["ip"] == ip and item["port"] == port for item in self.state["found"]):
                return True
            self.state["found"].append(entry)
        self.log(f"Identify: READER FOUND {ip} {transport}/{port} {entry['protocol']} tags {tags[:3]}")
        return True

    def _unknown(self, ip, transport, port, data):
        with self.lock:
            if any(item["ip"] == ip and item["port"] == port for item in self.state["unknown_data"]):
                return
            self.state["unknown_data"].append({
                "ip": ip, "transport": transport, "port": port, "bytes": len(data), "raw_hex": data[:64].hex(" ").upper(),
            })
        self.log(f"Identify: {ip} {transport}/{port} sent {len(data)} bytes in an unknown format: {data[:32].hex(' ').upper()}")

    def _done(self):
        with self.lock:
            return bool(self.state["found"])

    async def _listen_tcp(self, item, port, deadline):
        ip = item["ip"]
        try:
            reader, writer = await asyncio.wait_for(asyncio.open_connection(ip, port), 2)
        except (OSError, asyncio.TimeoutError):
            return
        decoder = uhf.StreamDecoder()
        commands = [command for _p, _u, command in uhf.probe_commands(self.profiles, "inventory")]
        seen_bytes = b""
        next_poll = time.monotonic() + 1.0
        try:
            while time.monotonic() < deadline and not self._done():
                try:
                    data = await asyncio.wait_for(reader.read(4096), 0.3)
                except asyncio.TimeoutError:
                    data = None
                if data == b"":
                    return
                if data:
                    seen_bytes += data
                    frames = decoder.feed(data)
                    if self._found(ip, "tcp", port, [f for f in frames if f.kind == "tag"], data):
                        return
                    if len(seen_bytes) >= 8 and not frames and not _other_service(seen_bytes):
                        self._unknown(ip, "tcp", port, seen_bytes)
                if time.monotonic() >= next_poll and commands:
                    next_poll = time.monotonic() + 1.0
                    try:
                        writer.write(b"".join(commands))
                        await writer.drain()
                    except OSError:
                        return
        finally:
            writer.close()

    async def _listen_udp(self, deadline):
        ports = [int(port) for port in self.profiles.get("uhf_reader", {}).get("udp_ports", [])]
        commands = [command for _p, _u, command in uhf.probe_commands(self.profiles, "inventory")]
        if not ports or not commands or not self.candidates:
            return
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setblocking(False)
        sock.bind(("", 0))
        next_poll = 0.0
        try:
            while time.monotonic() < deadline and not self._done():
                if time.monotonic() >= next_poll:
                    next_poll = time.monotonic() + 1.0
                    for item in self.candidates:
                        for port in ports:
                            for command in commands:
                                try:
                                    sock.sendto(command, (item["ip"], port))
                                except OSError:
                                    pass
                try:
                    data, (ip, port) = sock.recvfrom(4096)
                except (BlockingIOError, OSError):
                    await asyncio.sleep(0.1)
                    continue
                frames, _protocol = uhf.decode_once(data)
                if not self._found(ip, "udp", port, [f for f in frames if f.kind == "tag"], data) and data:
                    self._unknown(ip, "udp", port, data)
        finally:
            sock.close()

    async def _watch_clients(self, deadline):
        while time.monotonic() < deadline and not self._done():
            for ip, info in (self.client_seen() or {}).items():
                if info.get("tags"):
                    with self.lock:
                        if not any(item["ip"] == ip for item in self.state["found"]):
                            self.state["found"].append({
                                "ip": ip, "mac": None, "transport": f"{info.get('transport', 'tcp')}-client",
                                "port": info.get("port"), "protocol": info.get("protocol"), "tags": [], "raw_hex": None,
                            })
                    self.log(f"Identify: reader connected to this PC from {ip} with tag data")
            await asyncio.sleep(0.5)
