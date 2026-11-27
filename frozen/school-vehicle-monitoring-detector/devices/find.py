"""
"Find my reader" wizard: find a device by what CHANGES on the network when
it is plugged in or powered on.

1. Baseline: every device (MAC, IP) on every connected network, from an ARP
   sweep, plus the MACs heard by passive listening.
2. The user plugs in / powers on the reader.
3. For ~90 s: repeat the sweep and keep listening; any MAC not in the
   baseline is new. Passive listening also sees a reader with a fixed IP on
   another network (ARP/DHCP/broadcasts), which a sweep never can.
4. A new device on this network: scan all 65535 TCP ports, send the UHF
   inventory/info commands on TCP and UDP, and listen for tag data.
5. A verdict in plain words, including the "nothing at all" cases.
"""

import asyncio
import ipaddress
import threading
import time
from datetime import datetime, timezone

from . import netinfo, probes, tempip
from .identify import ReaderIdentifier
from .oui import is_randomized, vendor_for
from .passive import PassiveListener, availability
from .scanner import plan_hosts


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def in_networks(ip, interfaces):
    try:
        address = ipaddress.IPv4Address(ip)
    except ValueError:
        return None
    for item in interfaces:
        if address in ipaddress.IPv4Network(item["network"]):
            return item
    return None


async def full_tcp_scan(ip, timeout=0.4, concurrency=800):
    """All 65535 TCP ports. On a LAN closed ports answer at once, so this takes seconds."""
    semaphore = asyncio.Semaphore(concurrency)

    async def probe(port):
        async with semaphore:
            try:
                _reader, writer = await asyncio.wait_for(asyncio.open_connection(ip, port), timeout)
            except (OSError, asyncio.TimeoutError):
                return None
            writer.close()
            return port

    results = await asyncio.gather(*(probe(port) for port in range(1, 65536)))
    return sorted(port for port in results if port)


class FindReaderWizard:
    def __init__(self, profiles, seconds, log, passive=True, client_seen=None, listen_seconds=45):
        self.profiles = profiles
        self.seconds = int(seconds)
        self.listen_seconds = int(listen_seconds)
        self.log = log
        self.client_seen = client_seen
        self.lock = threading.Lock()
        self.listener = None
        self.baseline = set()
        self.probe_threads = []
        self.passive_wanted = passive
        self.state = {
            "running": True,
            "started_at": utc_now(),
            "seconds": self.seconds,
            "phase": "baseline",
            "phase_label": "Recording the devices already on the network",
            "baseline_count": 0,
            "interfaces": [],
            "passive": {"available": False, "reason": None, "interface": None},
            "new_devices": [],
            "events": [],
            "result": None,
            "message": "Recording what is on the network before the reader is plugged in…",
            "waiting_until": None,
        }

    # -- state -------------------------------------------------------------
    def snapshot(self):
        with self.lock:
            return {**self.state, "new_devices": [dict(item) for item in self.state["new_devices"]],
                    "events": list(self.state["events"])}

    def _set(self, **values):
        with self.lock:
            self.state.update(values)

    def _event(self, text):
        self.log(f"Find reader: {text}")
        with self.lock:
            self.state["events"].append(f"{datetime.now().strftime('%H:%M:%S')} {text}")
            del self.state["events"][:-60]

    def _device(self, mac):
        for item in self.state["new_devices"]:
            if item["mac"] == mac:
                return item
        item = {
            "mac": mac, "vendor": vendor_for(mac), "randomized_mac": is_randomized(mac), "ip": None, "ips": [],
            "reachable": False, "seen_by": [], "first_seen": utc_now(), "passive": None,
            "probe": {"state": "waiting"},
        }
        self.state["new_devices"].append(item)
        return item

    # -- network ---------------------------------------------------------------
    def _sweep(self, interfaces):
        for item in interfaces:
            probes.trigger_arp(plan_hosts(item, int(self.profiles.get("scan", {}).get("max_hosts_per_interface", 1024))),
                               self.profiles.get("scan", {}).get("arp_trigger_port"))
        time.sleep(float(self.profiles.get("scan", {}).get("arp_settle_seconds", 2.0)))
        own = {item["ip"] for item in interfaces}
        return {ip: mac for ip, mac in netinfo.arp_table().items() if ip not in own and in_networks(ip, interfaces)}

    # -- run -------------------------------------------------------------------
    def run(self):
        try:
            self._run()
        except Exception as error:
            self._event(f"stopped: {error}")
            self._set(message=f"The wizard stopped: {error}")
        finally:
            if self.listener:
                self.listener.stop()
            for thread in self.probe_threads:
                thread.join(timeout=self.listen_seconds + 60)
            verdict = self._verdict()
            self._set(running=False, phase="done", phase_label="Finished", finished_at=utc_now(), **verdict)
        return self.snapshot()

    def _run(self):
        interfaces = netinfo.interfaces(self.profiles)
        self._set(interfaces=[{k: item.get(k) for k in ("name", "label", "kind", "ip", "network", "gateway", "link_local")}
                              for item in interfaces])
        own_macs = {item.get("mac") for item in interfaces}

        # Passive listening on the LAN (wired first).
        target = next((item for item in interfaces if item["kind"] == "ethernet"), interfaces[0] if interfaces else None)
        available, reason = availability()
        if self.passive_wanted and available and target:
            self.listener = PassiveListener(target["name"], own_macs, self.log)
            self.listener.start()
            self._set(passive={"available": True, "reason": None, "interface": target["name"]})
            self._event(f"passive listening on {target['name']}")
        else:
            self._set(passive={"available": False, "reason": reason if self.passive_wanted else "Turned off.", "interface": target["name"] if target else None})
            self._event(f"passive listening not available: {reason}")

        arp = self._sweep(interfaces)
        time.sleep(1.0)
        self.baseline = set(arp.values()) | own_macs
        if self.listener:
            self.baseline |= set(self.listener.snapshot())
        self._set(baseline_count=len(self.baseline - own_macs))
        self._event(f"baseline: {len(arp)} device(s) answered: " + ", ".join(f"{ip} {mac}" for ip, mac in sorted(arp.items())))

        deadline = time.monotonic() + self.seconds
        self._set(phase="waiting", phase_label="Plug in or power on the reader now",
                  message="Plug the reader's LAN cable in, or switch its power on, now.",
                  waiting_until=datetime.fromtimestamp(time.time() + self.seconds, timezone.utc).isoformat())

        while time.monotonic() < deadline:
            arp = self._sweep(interfaces)
            with self.lock:
                for ip, mac in arp.items():
                    if mac in self.baseline:
                        continue
                    device = self._device(mac)
                    if "arp" not in device["seen_by"]:
                        device["seen_by"].append("arp")
                    device["ip"] = ip
                    if ip not in device["ips"]:
                        device["ips"].append(ip)
                    device["reachable"] = True
                if self.listener:
                    for mac, heard in self.listener.snapshot().items():
                        if mac in self.baseline:
                            continue
                        device = self._device(mac)
                        if "passive" not in device["seen_by"]:
                            device["seen_by"].append("passive")
                        device["passive"] = {key: heard[key] for key in ("packets", "arp_announces", "dhcp_requests", "dhcp_replies", "broadcast_ports", "sample")}
                        for ip in heard["ips"]:
                            if ip not in device["ips"]:
                                device["ips"].append(ip)
                        if not device["ip"] and heard["ips"]:
                            device["ip"] = heard["ips"][-1]
                        device["reachable"] = bool(device["ip"] and in_networks(device["ip"], interfaces))
                pending = [item for item in self.state["new_devices"]
                           if item["probe"]["state"] == "waiting" and item["reachable"] and item["ip"]]
            for device in pending:
                self._event(f"NEW device {device['mac']} ({device['vendor'] or 'unknown maker'}) at {device['ip']}; probing")
                device["probe"] = {"state": "scanning ports"}
                thread = threading.Thread(target=self._probe, args=(device,), daemon=True)
                thread.start()
                self.probe_threads.append(thread)
            if any((item["probe"].get("reader") or {}).get("found") for item in self.state["new_devices"]):
                break
            time.sleep(1.0)

        self._set(phase="finishing", phase_label="Checking the new device(s)")

    def _probe(self, device):
        ip = device["ip"]
        try:
            ports = asyncio.run(full_tcp_scan(ip))
        except Exception as error:
            ports = []
            self._event(f"{ip}: port scan failed: {error}")
        with self.lock:
            device["probe"] = {"state": "listening for tag data", "open_tcp": ports}
        self._event(f"{ip}: open TCP ports {ports or 'none'}; listening for tag data (hold a UHF tag near the reader)")
        udp_ports = sorted(set(int(port) for port in self.profiles.get("uhf_reader", {}).get("udp_ports", [])) | set(ports))
        identifier = ReaderIdentifier(
            self.profiles, [{"ip": ip, "mac": device["mac"]}], self.listen_seconds, self.log,
            client_seen=self.client_seen, known_ports={ip: ports}, udp_ports=udp_ports,
        )
        result = identifier.run()
        with self.lock:
            device["probe"] = {
                "state": "done", "open_tcp": ports, "udp_ports_tried": udp_ports,
                "reader": {
                    "found": result["found"], "replies": result["replies"], "unknown_data": result["unknown_data"],
                    "message": result["message"],
                },
            }
        self._event(f"{ip}: {result['message']}")

    # -- verdict -----------------------------------------------------------------
    def _verdict(self):
        state = self.snapshot()
        devices = state["new_devices"]
        interfaces = state["interfaces"]
        networks = ", ".join(item["network"] for item in interfaces) or "none"
        passive = state["passive"]["available"]

        for device in devices:
            reader = (device["probe"].get("reader") or {})
            if reader.get("found"):
                first = reader["found"][0]
                return {"result": "reader_found", "message":
                        f"Reader found: {device['ip']} (MAC {device['mac']}), {first['transport'].upper()} port {first['port']}, "
                        f"data format {first['protocol']}, tag {', '.join(first['tags']) or '—'}. It is saved; assign it to a station."}
        for device in devices:
            reader = (device["probe"].get("reader") or {})
            if reader.get("replies"):
                first = reader["replies"][0]
                return {"result": "reader_answered", "message":
                        f"Reader found at {device['ip']} (MAC {device['mac']}): it answers on {first['transport'].upper()} port {first['port']} "
                        f"({first['protocol']}) but no tag was read. Hold a tag close to the reader and run Identify reader."}

        real = [item for item in devices if not item["randomized_mac"]]
        other_subnet = [item for item in real if item["ips"] and not item["reachable"]]
        if other_subnet:
            device = other_subnet[0]
            ip = device["ips"][-1]
            plan = tempip.plan(ip, set())
            wired = next((item for item in interfaces if item["kind"] == "ethernet"), interfaces[0] if interfaces else None)
            commands = tempip.commands(wired, plan) if (plan and wired) else None
            return {"result": "other_subnet", "other_subnet": {"ip": ip, "network": plan["network"] if plan else None,
                                                                "pc_ip": plan["ip"] if plan else None, "commands": commands},
                    "message":
                    f"The new device {device['mac']} ({device['vendor'] or 'unknown maker'}) uses the fixed IP {ip}, which is not on this "
                    f"PC's network ({networks}). Give this PC a temporary address on {plan['network'] if plan else 'that network'} to reach it, "
                    "then open the reader's settings (its web page at that IP, or its config tool) and set it to DHCP or to a free address on "
                    "this network."}
        asking = [item for item in real if (item["passive"] or {}).get("dhcp_requests") and not item["reachable"]]
        if asking:
            device = asking[0]
            got_reply = (device["passive"] or {}).get("dhcp_replies", 0) > 0
            return {"result": "dhcp_no_address", "message":
                    f"The new device {device['mac']} asks for an IP address (DHCP) "
                    + ("and the router answered, but it never became reachable. Restart the reader." if got_reply else
                       "but the router gives none. Check the router's DHCP (and that the cable goes to a LAN port, not WAN).")}
        probed = [item for item in real if item["reachable"]]
        if probed:
            device = probed[0]
            probe = device["probe"]
            unknown = ((probe.get("reader") or {}).get("unknown_data") or [])
            return {"result": "not_a_reader", "message":
                    f"A new device appeared at {device['ip']} (MAC {device['mac']}, {device['vendor'] or 'unknown maker'}) with open TCP "
                    f"ports {probe.get('open_tcp') or 'none'}, but it did not answer as a UHF reader"
                    + (f"; it sent data in an unknown format (shown below)." if unknown else ". Hold a tag near it and run Identify reader.")}
        if devices and not real:
            return {"result": "only_phones", "message": "Only phones or laptops (private MAC addresses) joined the network. The reader did not appear."}
        if passive:
            return {"result": "nothing", "message":
                    "No packet at all came from a new device. The reader most likely has no power, the cable is broken or not plugged "
                    "into a LAN port, or its connector is not Ethernet (for example RS-232/RS-485 or USB)."}
        return {"result": "nothing_no_passive", "message":
                "No new device answered on this network. Passive listening was not available, so a reader with a fixed IP on another "
                "network cannot be ruled out. Run the wizard with admin rights: php artisan devices:find"}
