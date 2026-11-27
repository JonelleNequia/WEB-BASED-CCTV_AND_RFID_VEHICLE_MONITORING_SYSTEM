"""
Passive listening on the LAN (Find my reader).

A device with a fixed IP on another network never answers our scan, but it
still talks on the cable: ARP (often a gratuitous ARP when it boots), DHCP
requests, UDP broadcasts. Capturing raw packets shows its MAC and the IP it
thinks it has.

macOS/Linux: tcpdump (needs admin rights, or read access to /dev/bpf*).
Windows: scapy with Npcap, when installed.
"""

import os
import re
import shutil
import subprocess
import threading
import time

from .netinfo import SYSTEM, is_admin
from .oui import normalize_mac

LINE = re.compile(r"^\S+ ([0-9a-fA-F:]{17}) > ([0-9a-fA-F:]{17}|\S+), ethertype (\S+) \([^)]*\), length \d+: (.*)$")
IPV4_SRC = re.compile(r"^(\d+\.\d+\.\d+\.\d+)(?:\.(\d+))? > (\d+\.\d+\.\d+\.\d+)(?:\.(\d+))?:")


def availability():
    """(available, reason)"""
    if SYSTEM in ("darwin", "linux"):
        if not shutil.which("tcpdump"):
            return False, "tcpdump is not installed."
        readable = SYSTEM == "darwin" and os.access("/dev/bpf0", os.R_OK)
        if not (is_admin() or readable):
            return False, "Passive listening needs admin rights. Run: php artisan devices:find (it asks for your password)."
        return True, "tcpdump"
    if SYSTEM == "windows":
        try:
            import scapy.all  # noqa: F401
        except Exception:
            return False, "Passive listening on Windows needs Npcap (npcap.com) and the Python package scapy."
        if not is_admin():
            return False, "Passive listening needs an administrator window. Run: php artisan devices:find as administrator."
        return True, "scapy"
    return False, "Passive listening is not supported on this system."


class PassiveListener(threading.Thread):
    def __init__(self, interface, own_macs, log):
        super().__init__(daemon=True, name="passive-listener")
        self.interface = interface
        self.own_macs = {mac for mac in own_macs if mac}
        self.log = log
        self.lock = threading.Lock()
        self.devices = {}
        self.process = None
        self.stopped = threading.Event()
        self.error = None

    # -- records ---------------------------------------------------------
    def _record(self, mac, **event):
        mac = normalize_mac(mac)
        if not mac or mac in self.own_macs:
            return
        now = time.time()
        with self.lock:
            device = self.devices.setdefault(mac, {
                "mac": mac, "packets": 0, "first_seen": now, "last_seen": now, "ips": [], "arp_announces": 0,
                "dhcp_requests": 0, "dhcp_replies": 0, "broadcast_ports": [], "sample": None,
            })
            device["packets"] += 1
            device["last_seen"] = now
            ip = event.get("ip")
            if ip and ip != "0.0.0.0" and ip not in device["ips"]:
                device["ips"].append(ip)
            if event.get("arp_announce"):
                device["arp_announces"] += 1
            if event.get("dhcp_request"):
                device["dhcp_requests"] += 1
            port = event.get("broadcast_port")
            if port and port not in device["broadcast_ports"] and len(device["broadcast_ports"]) < 20:
                device["broadcast_ports"].append(port)
            if device["sample"] is None and event.get("sample"):
                device["sample"] = event["sample"][:200]

    def _dhcp_reply_to(self, mac):
        mac = normalize_mac(mac)
        with self.lock:
            if mac in self.devices:
                self.devices[mac]["dhcp_replies"] += 1

    def snapshot(self):
        with self.lock:
            return {mac: dict(device, ips=list(device["ips"]), broadcast_ports=list(device["broadcast_ports"]))
                    for mac, device in self.devices.items()}

    # -- capture ---------------------------------------------------------
    def run(self):
        available, how = availability()
        if not available:
            self.error = how
            return
        try:
            if how == "tcpdump":
                self._run_tcpdump()
            else:
                self._run_scapy()
        except Exception as error:
            self.error = str(error)
            self.log(f"Passive listening stopped: {error}")

    def stop(self):
        self.stopped.set()
        if self.process and self.process.poll() is None:
            self.process.terminate()

    def _run_tcpdump(self):
        self.process = subprocess.Popen(
            ["tcpdump", "-i", self.interface, "-n", "-e", "-l", "-tt"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1,
        )
        self.log(f"Passive listening on {self.interface} (tcpdump)")
        for line in self.process.stdout:
            if self.stopped.is_set():
                break
            self.parse(line.rstrip("\n"))
        if self.process.poll() not in (None, 0) and not self.stopped.is_set():
            self.error = (self.process.stderr.read() or "tcpdump stopped").strip()[:200]

    def parse(self, line):
        match = LINE.match(line)
        if not match:
            return
        src, dst, ethertype, rest = match.groups()
        if ethertype == "ARP":
            who = re.search(r"who-has (\S+) tell (\S+)", rest)
            if who:
                self._record(src, ip=who.group(2).rstrip(","), arp_announce=who.group(1).rstrip(",") == who.group(2).rstrip(","), sample=line)
                return
            reply = re.search(r"Reply (\S+) is-at", rest)
            self._record(src, ip=reply.group(1) if reply else None, arp_announce=bool(reply), sample=line)
            return
        if ethertype == "IPv4":
            ip = IPV4_SRC.match(rest)
            src_ip = ip.group(1) if ip else None
            dhcp_request = "BOOTP/DHCP, Request" in rest
            if "BOOTP/DHCP, Reply" in rest:
                self._dhcp_reply_to(dst)
            broadcast_port = None
            if ip and ip.group(4) and (ip.group(3).endswith(".255") or ip.group(3) == "255.255.255.255"):
                broadcast_port = int(ip.group(4))
            self._record(src, ip=None if dhcp_request else src_ip, dhcp_request=dhcp_request, broadcast_port=broadcast_port, sample=line)
            return
        self._record(src, sample=line)

    def _run_scapy(self):
        from scapy.all import ARP, BOOTP, IP, UDP, Ether, sniff

        self.log(f"Passive listening on {self.interface} (scapy)")

        def handle(packet):
            if Ether not in packet:
                return
            src = packet[Ether].src
            if ARP in packet:
                arp = packet[ARP]
                self._record(src, ip=arp.psrc, arp_announce=arp.psrc == arp.pdst or arp.op == 2, sample=packet.summary())
            elif BOOTP in packet:
                if packet[BOOTP].op == 1:
                    self._record(src, dhcp_request=True, sample=packet.summary())
                else:
                    self._dhcp_reply_to(packet[Ether].dst)
            elif IP in packet:
                port = packet[UDP].dport if UDP in packet and packet[IP].dst.endswith(".255") else None
                self._record(src, ip=packet[IP].src, broadcast_port=port, sample=packet.summary())
            else:
                self._record(src, sample=packet.summary())

        sniff(iface=self.interface, prn=handle, store=False, stop_filter=lambda _packet: self.stopped.is_set())
