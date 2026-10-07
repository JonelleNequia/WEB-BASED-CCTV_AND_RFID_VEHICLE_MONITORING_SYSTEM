"""
One device scan: find hosts on every connected subnet, identify cameras and
UHF readers, and return a device list keyed by MAC address.

Order of work:
1. Read the PC's interfaces, masks and gateways (netinfo).
2. Nudge every host of each subnet so the OS learns its MAC (ARP), while
   ONVIF multicast and reader-module broadcasts run in parallel. These two
   also reach devices on a different subnet on the same cable.
3. Probe live hosts: RTSP, HTTP banner, UHF reader ports (TCP and UDP),
   confirming a reader by a valid protocol frame, real tag data, or a known
   signature (maker OUI + reader port, from the profiles).
   A reader the service is already connected to is not probed: many readers
   accept a single TCP client, so a probe would fail and mark it offline.
4. Optionally reach other-subnet devices through a temporary address.
"""

import asyncio
import ipaddress
import math
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone

from . import diagnostics, netinfo, probes, tempip
from .oui import is_randomized, mac_from_uuid, vendor_for


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def plan_hosts(interface, max_hosts):
    """
    Host addresses to sweep on one interface. Link-local /16 networks are too
    big to sweep; they rely on multicast/broadcast replies and the ARP cache.
    A big LAN is limited to the block around this PC's own address.
    """
    if interface["link_local"]:
        return []
    network = ipaddress.IPv4Network(interface["network"])
    if network.num_addresses - 2 > max_hosts:
        prefix = 32 - math.floor(math.log2(max_hosts + 2))
        network = ipaddress.IPv4Network(f"{interface['ip']}/{max(prefix, network.prefixlen)}", strict=False)
    return [str(host) for host in network.hosts() if str(host) != interface["ip"]]


def interface_for(ip, interfaces):
    try:
        address = ipaddress.IPv4Address(ip)
    except ValueError:
        return None
    for item in interfaces:
        if address in ipaddress.IPv4Network(item["network"]):
            return item
    return None


class Scanner:
    def __init__(self, profiles, log=None, progress=None):
        self.profiles = profiles
        self.scan_settings = profiles.get("scan", {})
        self.log = log or (lambda message: None)
        self.progress = progress or (lambda message: None)

    # ------------------------------------------------------------------
    def run(self, trigger="manual", targets=None, known=None, light=False, allow_temp_ip=None, busy=None):
        started = time.monotonic()
        started_at = utc_now()
        known = known or {}
        # {ip: mac} of readers the service holds a connection to right now.
        busy = dict(busy or {})
        targets = [target for target in (targets or []) if target]

        self.progress("Reading network interfaces")
        snap = netinfo.snapshot(self.profiles)
        interfaces = snap["interfaces"]
        gateway_ips = {item["gateway"] for item in interfaces if item.get("gateway")}
        own_ips = {item["ip"] for item in interfaces}

        for item in interfaces:
            self.log(
                f"Interface {item['name']} ({item['label']}, {item['kind']}): {item['ip']}/{item['prefix']}"
                f" gateway {item['gateway'] or 'none'}{' [link-local only]' if item['link_local'] else ''}"
            )
        if not interfaces:
            self.log("No connected network interface with an IPv4 address.")

        # 2. ARP nudge + multicast/broadcast discovery in parallel.
        max_hosts = int(self.scan_settings.get("max_hosts_per_interface", 1024))
        sweep = {}
        for item in interfaces:
            hosts = plan_hosts(item, max_hosts)
            sweep[item["name"]] = hosts
            self.log(f"Sweeping {len(hosts)} addresses on {item['name']}")

        self.progress("Looking for devices (ARP, ONVIF, reader broadcast)")
        probe_errors = []
        arp_stats = {"sent": 0, "errors": {}}
        with ThreadPoolExecutor(max_workers=3) as pool:
            onvif_future = pool.submit(probes.onvif_discovery, interfaces, self.profiles, probe_errors)
            module_future = pool.submit(probes.broadcast_discovery, interfaces, self.profiles, probe_errors)
            for hosts in sweep.values():
                stats = probes.trigger_arp(hosts + targets, self.scan_settings.get("arp_trigger_port"))
                arp_stats["sent"] += stats["sent"]
                for reason, count in stats["errors"].items():
                    arp_stats["errors"][reason] = arp_stats["errors"].get(reason, 0) + count
            time.sleep(float(self.scan_settings.get("arp_settle_seconds", 2.0)))
            onvif = onvif_future.result()
            modules = module_future.result()
        if arp_stats["errors"]:
            self.log(f"Send errors while sweeping: {arp_stats['errors']}")
        for message in probe_errors:
            self.log(f"Probe error: {message}")

        arp = netinfo.arp_table()
        live = {
            ip: mac for ip, mac in arp.items()
            if ip not in own_ips and (interface_for(ip, interfaces) or ip in targets)
        }
        self.log(f"ARP: {len(live)} live host(s) on connected subnets")
        for item in interfaces:
            seen = sorted(ip for ip in live if interface_for(ip, [item]))
            self.log(f"  {item['name']}: {len(seen)} host(s) seen by the OS {', '.join(seen)}".rstrip())

        # Why a scan finds nothing: checked every time and shown in Settings.
        diag = diagnostics.build(
            snap, sweep, live, arp, onvif, modules, arp_stats, probe_errors,
            diagnostics.local_network_access(interfaces), diagnostics.firewall_state(), netinfo.is_admin(),
        )
        for warning in diag["warnings"]:
            self.log(f"Warning ({warning['code']}): {warning['message']}")
        for ip, info in onvif.items():
            self.log(f"ONVIF reply: {ip} {info.get('manufacturer') or ''} {info.get('hardware') or ''} {info.get('xaddr') or ''}".rstrip())
        for ip, info in modules.items():
            self.log(f"Reader-module broadcast reply: {ip} ({info['module']}) {info.get('text') or ''}".rstrip())

        # 3. Which hosts to probe.
        candidates = set(live) | set(targets)
        candidates |= {ip for ip in onvif if interface_for(ip, interfaces)}
        candidates |= {ip for ip in modules if interface_for(ip, interfaces)}
        if light:
            candidates = {
                ip for ip in candidates
                if ip in targets or live.get(ip) not in known or (known.get(live.get(ip)) or {}).get("ip") != ip
            }
            self.log(f"Light scan: probing {len(candidates)} new or moved host(s)")
        if busy and candidates & set(busy):
            self.log(f"Not probing {sorted(candidates & set(busy))}: the reader link is connected there")
        candidates -= set(busy)

        self.progress(f"Probing {len(candidates)} host(s)")
        details = self._probe_hosts(sorted(candidates))

        # Known devices skipped by a light scan keep their details but get a
        # quick service-port check so a switched-off device reads as offline.
        carried = {}
        if light:
            carried = self._recheck_known(known, live, busy)
        for ip, mac in busy.items():
            mac = live.get(ip) or mac
            if mac in known and mac not in carried:
                carried[mac] = {**known[mac], "ip": ip, "online": True, "reachable": True}

        # 4. Other-subnet devices (multicast/broadcast replies we cannot reach).
        unreachable = set(
            ip for ip in set(onvif) | set(modules)
            if not interface_for(ip, interfaces) and ip not in own_ips
        )
        # Silent devices with a fixed IP on another subnet never answer a
        # search. When a temporary address is explicitly allowed, also try the
        # addresses given with --target (no address is built in).
        if allow_temp_ip:
            unreachable |= {
                ip for ip in targets
                if not interface_for(ip, interfaces) and ip not in own_ips
            }
        unreachable = sorted(unreachable)
        temp_results, suggestions = self._other_subnets(unreachable, snap, allow_temp_ip, known)

        devices = self._build_devices(
            live, onvif, modules, details, temp_results, interfaces, gateway_ips, targets
        )
        if carried:
            # Hosts a light scan did not probe keep their earlier details.
            devices = [
                item for item in devices
                if item["ip"] in candidates or not item.get("mac") or item["mac"] not in carried
            ]
            present = {item.get("mac") for item in devices}
            devices.extend(device for mac, device in carried.items() if mac not in present)

        finished_at = utc_now()
        result = {
            "scan": {
                "id": str(uuid.uuid4()),
                "trigger": trigger,
                "light": bool(light),
                "started_at": started_at,
                "finished_at": finished_at,
                "duration_seconds": round(time.monotonic() - started, 1),
                "subnets": [item["network"] for item in interfaces],
                "complete": not light,
            },
            "network": snap,
            "diagnostics": diag,
            "devices": sorted(devices, key=_device_order),
            "temporary_ip_suggestions": suggestions,
        }
        self.log(f"Scan finished in {result['scan']['duration_seconds']}s: {len(devices)} device(s)")
        return result

    # ------------------------------------------------------------------
    def _probe_hosts(self, hosts):
        if not hosts:
            return {}
        with ThreadPoolExecutor(max_workers=1) as pool:
            udp_future = pool.submit(probes.reader_udp_probe, hosts, self.profiles)
            tcp_details = asyncio.run(self._probe_tcp(hosts))
            udp = udp_future.result()
        for ip, reader in udp.items():
            entry = tcp_details.setdefault(ip, {"open_tcp": []})
            if not (entry.get("reader") or {}).get("confirmed"):
                entry["reader"] = reader
                self.log(f"{ip}: UHF reader answered on UDP {reader['port']} ({reader['protocol']})")
        return tcp_details

    async def _probe_tcp(self, hosts):
        camera = self.profiles.get("camera", {})
        reader = self.profiles.get("uhf_reader", {})
        rtsp_ports = [int(port) for port in camera.get("rtsp_ports", [])]
        http_ports = [int(port) for port in self.scan_settings.get("http_banner_ports", [])]
        reader_ports = [int(port) for port in reader.get("tcp_ports", [])]
        all_ports = sorted(set(rtsp_ports) | set(http_ports) | set(reader_ports))
        timeout = float(self.scan_settings.get("connect_timeout_seconds", 0.7))
        semaphore = asyncio.Semaphore(int(self.scan_settings.get("max_concurrency", 128)))

        async def one(host):
            ports = await probes.open_ports(host, all_ports, timeout, semaphore)
            info = {"open_tcp": ports}
            if ports:
                self.log(f"{host}: open TCP ports {ports}")
            for port in ports:
                if port in rtsp_ports and "rtsp" not in info:
                    rtsp = await probes.rtsp_options(host, port, timeout * 2)
                    if rtsp:
                        info["rtsp"] = rtsp
                        self.log(f"{host}: RTSP server on {port} ({rtsp.get('server') or 'no banner'})")
                if port in http_ports and "http" not in info:
                    banner = await probes.http_banner(host, port, timeout * 2)
                    if banner:
                        info["http"] = banner
            for port in ports:
                if port in reader_ports and port not in rtsp_ports and port not in http_ports:
                    result = await probes.reader_tcp_probe(host, port, self.profiles)
                    if result and (result.get("confirmed") or "reader" not in info):
                        info["reader"] = result
                        state = "confirmed" if result.get("confirmed") else "port open, no reader reply"
                        self.log(f"{host}: TCP {port} {state} {result.get('protocol') or ''} {result.get('sample_tags') or ''}".rstrip())
                    if result and result.get("confirmed"):
                        break
            return host, info

        results = await asyncio.gather(*(one(host) for host in hosts))
        return {host: info for host, info in results}

    def _recheck_known(self, known, live, busy=None):
        """
        For a light scan: known devices keep their classification; online only
        if they are in the ARP cache and (when known) their service port answers.
        """
        timeout = float(self.scan_settings.get("connect_timeout_seconds", 0.7))
        ip_by_mac = {mac: ip for ip, mac in live.items()}
        carried = {}

        async def check(mac, device):
            ip = ip_by_mac.get(mac)
            device = dict(device)
            if not ip:
                device["online"] = False
                return mac, device
            device["ip"] = ip
            if ip in (busy or {}):
                device["online"] = True  # the reader link is connected right now
                return mac, device
            port = (device.get("reader") or {}).get("port") if (device.get("reader") or {}).get("transport") == "tcp" else None
            port = port or (device.get("camera") or {}).get("rtsp_port")
            device["online"] = await probes.tcp_open(ip, int(port), timeout) if port else True
            return mac, device

        async def run_all():
            return await asyncio.gather(*(check(mac, device) for mac, device in known.items() if mac and device.get("reachable", True)))

        for mac, device in asyncio.run(run_all()):
            carried[mac] = device
        return carried

    def _other_subnets(self, unreachable, snap, allow_temp_ip, known):
        """
        Reach devices that answered from another subnet with a temporary
        address (admin only); otherwise return the commands for the UI.
        """
        wired = [item for item in snap["interfaces"] if item["kind"] == "ethernet"]
        interface = wired[0] if wired else (snap["interfaces"][0] if snap["interfaces"] else None)
        if not unreachable or interface is None:
            return {}, []

        enabled = self.profiles.get("temporary_ip", {}).get("enabled", False) if allow_temp_ip is None else allow_temp_ip
        known_ips = {device.get("ip") for device in known.values() if device.get("ip")}
        suggestions = []
        results = {}
        done_networks = set()

        for ip in unreachable:
            address = tempip.plan(ip, known_ips | set(unreachable))
            if not address or address["network"] in done_networks:
                continue
            done_networks.add(address["network"])
            suggestion = {
                "device_ip": ip,
                "network": address["network"],
                "pc_ip": address["ip"],
                "netmask": address["netmask"],
                "interface": interface["name"],
                "interface_label": interface.get("label"),
                "commands": tempip.commands(interface, address),
            }
            suggestions.append(suggestion)

            # Windows replaces the address instead of adding one, so only do
            # it on a cable that has no DHCP lease anyway.
            safe = netinfo.SYSTEM != "windows" or interface["link_local"]
            if not (enabled and safe and netinfo.is_admin()):
                self.log(f"{ip} is on {address['network']} (not this PC's subnet). Needs {address['ip']} on {interface['name']} to reach it.")
                continue

            with tempip.TemporaryAddress(interface, address, self.log) as added:
                if not added:
                    continue
                time.sleep(1.0)
                hosts = [str(host) for host in ipaddress.IPv4Network(address["network"]).hosts() if str(host) != address["ip"]]
                probes.trigger_arp(hosts, self.scan_settings.get("arp_trigger_port"))
                time.sleep(float(self.scan_settings.get("arp_settle_seconds", 2.0)))
                arp = netinfo.arp_table()
                found = {host: mac for host, mac in arp.items() if host in hosts}
                self.log(f"{address['network']}: {len(found)} host(s) answered " + ", ".join(f"{host} {mac}" for host, mac in sorted(found.items())))
                details = self._probe_hosts(sorted(set(found) | {ip}))
                for host, info in details.items():
                    results[host] = {**info, "mac": found.get(host), "via_temporary_ip": address["ip"]}
                suggestion["probed"] = True

        return results, suggestions

    # ------------------------------------------------------------------
    def _build_devices(self, live, onvif, modules, details, temp_results, interfaces, gateway_ips, targets):
        hosts = set(live) | set(onvif) | set(modules) | set(details) | set(temp_results) | set(targets)
        devices = []
        for ip in hosts:
            info = details.get(ip) or temp_results.get(ip) or {}
            onvif_info = onvif.get(ip)
            module = modules.get(ip)
            mac = live.get(ip) or info.get("mac") or (mac_from_uuid(onvif_info.get("endpoint")) if onvif_info else None)
            interface = interface_for(ip, interfaces)
            # A target on another network counts as reachable only if it answered directly.
            reachable = interface is not None or bool(info.get("open_tcp") and not info.get("via_temporary_ip"))
            if not (info.get("open_tcp") or onvif_info or module or mac):
                continue

            device = {
                "mac": mac,
                "ip": ip,
                "interface": interface["name"] if interface else None,
                "subnet": interface["network"] if interface else None,
                "reachable": reachable,
                "online": True,
                "vendor": vendor_for(mac),
                "randomized_mac": is_randomized(mac),
                "is_gateway": ip in gateway_ips,
                "open_ports": {"tcp": info.get("open_tcp", [])},
                "discovered_by": [
                    name for name, present in (
                        ("arp", ip in live), ("onvif", bool(onvif_info)), ("reader-broadcast", bool(module)),
                        ("temporary-ip", bool(info.get("via_temporary_ip"))), ("target", ip in targets),
                    ) if present
                ],
            }
            if info.get("via_temporary_ip"):
                device["via_temporary_ip"] = info["via_temporary_ip"]
            if info.get("http"):
                device["http"] = info["http"]
            if module:
                device["module"] = module

            device["network_warning"] = self._extra_address(interface, interfaces) if interface else None

            camera = self._camera_info(info, onvif_info, mac)
            reader = self._with_signature(info.get("reader"), mac, info.get("open_tcp", []))
            if camera:
                device.update({"kind": "camera", "confidence": "confirmed", "camera": camera})
            elif reader and reader.get("confirmed"):
                device.update({"kind": "rfid_reader", "confidence": "confirmed", "reader": reader})
            elif module or (reader and not reader.get("other_service") and not device["randomized_mac"]):
                device.update({"kind": "rfid_reader", "confidence": "possible", "reader": reader or {}})
            elif device["is_gateway"]:
                device.update({"kind": "router", "confidence": "confirmed"})
            else:
                device.update({"kind": "unknown", "confidence": "none"})

            device["brand"], device["model"], device["name"] = self._naming(device, onvif_info)
            device["key"] = mac or f"ip:{ip}"
            devices.append(device)
        return devices

    def _with_signature(self, reader, mac, open_tcp):
        """
        A reader in active mode is silent until a tag is near, so a probe
        cannot confirm it. A known maker OUI with its reader port open can.
        A real frame (tag data) always wins over the signature.
        """
        if reader and reader.get("confirmed") and reader.get("protocol"):
            reader.setdefault("confirmed_by", "frame")
            return reader
        prefix = (mac or "").upper()[:8]
        for signature in self.profiles.get("uhf_reader", {}).get("signatures", []):
            ouis = [str(item).upper() for item in signature.get("oui", [])]
            port = int(signature.get("tcp_port") or 0)
            if prefix and prefix in ouis and port in [int(item) for item in open_tcp]:
                self.log(f"{mac}: matches reader signature '{signature.get('name')}' (TCP {port} open)")
                return {
                    **(reader or {}),
                    "transport": signature.get("transport", "tcp"),
                    "port": port,
                    "protocol": signature.get("protocol"),
                    "work_mode": signature.get("work_mode", "unknown"),
                    "confirmed": True,
                    "confirmed_by": "signature",
                    "signature": signature.get("name"),
                }
        return reader

    @staticmethod
    def _extra_address(interface, interfaces):
        """
        The device is reached only through an extra address on this PC's
        network card (a second IP next to the router/DHCP one, or next to a
        169.254 address when there is no DHCP). That address is manual: it
        is gone after a restart and on another PC, so warn and explain.
        """
        others = [item for item in interfaces if item["name"] == interface["name"] and item["ip"] != interface["ip"]]
        if not others or interface.get("gateway"):
            return None
        lan = next((item for item in others if item.get("gateway")), None) \
            or next((item for item in others if not item.get("link_local")), None)
        return {
            "pc_ip": interface["ip"],
            "network": interface["network"],
            "interface": interface["name"],
            "interface_label": interface.get("label"),
            "lan_network": lan["network"] if lan else None,
            "lan_gateway": lan.get("gateway") if lan else None,
            "lan_ip": lan["ip"] if lan else None,
            "no_dhcp": lan is None,
        }

    def _camera_info(self, info, onvif_info, mac):
        rtsp = info.get("rtsp")
        if not rtsp and not onvif_info:
            return None
        vendor = self._camera_vendor(info, onvif_info, mac)
        return {
            "rtsp_port": rtsp["port"] if rtsp else None,
            "rtsp_server": rtsp.get("server") if rtsp else None,
            "onvif_xaddr": onvif_info.get("xaddr") if onvif_info else None,
            "vendor_profile": vendor.get("name") if vendor else None,
            "rtsp_paths": vendor.get("rtsp_paths", {}) if vendor else {},
        }

    def _camera_vendor(self, info, onvif_info, mac):
        text = " ".join(filter(None, [
            (onvif_info or {}).get("manufacturer"), (onvif_info or {}).get("hardware"), (onvif_info or {}).get("name"),
            (info.get("rtsp") or {}).get("server"),
            (info.get("http") or {}).get("server"), (info.get("http") or {}).get("realm"), (info.get("http") or {}).get("title"),
            vendor_for(mac),
        ]))
        fallback = None
        for vendor in self.profiles.get("camera", {}).get("vendors", []):
            if not vendor.get("match"):
                fallback = fallback or vendor
                continue
            if any(word.lower() in text.lower() for word in vendor["match"]):
                return vendor
        return fallback

    def _naming(self, device, onvif_info):
        vendor = device.get("vendor") or ""
        if device["kind"] == "camera":
            brand = device["camera"].get("vendor_profile") or (onvif_info or {}).get("manufacturer") or vendor or "Camera"
            model = (onvif_info or {}).get("hardware")
            name = (onvif_info or {}).get("name") or " ".join(filter(None, [brand, model]))
            if brand == "Camera" and vendor:
                brand = vendor
            return brand, model, name or "IP camera"
        if device["kind"] == "rfid_reader":
            module = (device.get("module") or {}).get("module")
            brand = vendor or module or "UHF reader"
            return brand, None, "UHF RFID reader"
        if device["kind"] == "router":
            return vendor or "Router", None, "Router (gateway)"
        http = device.get("http") or {}
        if device.get("randomized_mac"):
            return None, None, "Phone or laptop (private address)"
        return vendor or None, None, http.get("title") or http.get("realm") or (f"{vendor} device" if vendor else "Unknown device")


def _device_order(device):
    order = {"camera": 0, "rfid_reader": 1, "unknown": 2, "router": 3}
    return order.get(device.get("kind"), 9), device.get("ip") or ""


def scan_lock():
    return _LOCK


_LOCK = threading.Lock()
