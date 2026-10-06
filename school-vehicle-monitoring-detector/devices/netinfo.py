"""
Read this PC's network state at runtime (macOS and Windows; Linux best effort).

- interfaces(): every usable IPv4 interface with its mask, kind (ethernet /
  wifi / other) and whether it only has a link-local (169.254/16) address.
- gateways(): default gateway per interface, from the OS routing table.
- arp_table(): IP -> MAC entries the OS already knows.

No address is assumed; everything comes from the OS.
"""

import ipaddress
import platform
import re
import socket
import subprocess

import psutil

from .oui import normalize_mac

SYSTEM = platform.system().lower()
_HARDWARE_PORTS = None


def run(command, timeout=6):
    """
    Run a system command quietly (no console window on Windows) and return stdout.
    """
    kwargs = {"capture_output": True, "text": True, "timeout": timeout}
    if SYSTEM == "windows":
        kwargs["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
    try:
        return subprocess.run(command, **kwargs).stdout or ""
    except (OSError, subprocess.SubprocessError):
        return ""


def _mac_hardware_ports():
    """macOS: device name -> hardware port name ("en0" -> "Wi-Fi")."""
    global _HARDWARE_PORTS
    if _HARDWARE_PORTS is None:
        ports = {}
        current = None
        for line in run(["networksetup", "-listallhardwareports"]).splitlines():
            if line.startswith("Hardware Port:"):
                current = line.split(":", 1)[1].strip()
            elif line.startswith("Device:") and current:
                ports[line.split(":", 1)[1].strip()] = current
        _HARDWARE_PORTS = ports
    return _HARDWARE_PORTS


def refresh_hardware_ports():
    global _HARDWARE_PORTS
    _HARDWARE_PORTS = None


def interface_label(name):
    if SYSTEM == "darwin":
        ports = _mac_hardware_ports()
        if name not in ports and name.startswith("en"):
            # A USB/Thunderbolt LAN adapter plugged in after start: reload the
            # list, or it is taken for "other" instead of Ethernet.
            refresh_hardware_ports()
            ports = _mac_hardware_ports()
        return ports.get(name) or name
    return name


def interface_kind(name, profiles):
    scan = profiles.get("scan", {})
    label = interface_label(name)
    text = f"{name} {label}"
    for pattern in scan.get("wifi_interface_patterns", []):
        if re.search(pattern, text, re.IGNORECASE):
            return "wifi"
    for pattern in scan.get("wired_interface_patterns", []):
        if re.search(pattern, text, re.IGNORECASE):
            return "ethernet"
    return "other"


def _ignored(name, profiles):
    label = interface_label(name)
    for pattern in profiles.get("scan", {}).get("ignore_interface_patterns", []):
        if re.search(pattern, name, re.IGNORECASE) or re.search(pattern, label, re.IGNORECASE):
            return True
    return False


def _assumed_netmask(ip):
    """
    B2: an extra address added without a netmask (`ifconfig en7 alias
    192.168.2.1 255.255.255.0` stores 255.255.255.0 as the broadcast and no
    netmask) was skipped, so its network was never scanned. A private
    address gets the usual /24 instead.
    """
    try:
        address = ipaddress.IPv4Address(ip)
    except ValueError:
        return None
    return "255.255.255.0" if address.is_private and not address.is_loopback and not address.is_link_local else None


def interfaces(profiles):
    """
    Usable IPv4 interfaces: up, not loopback/virtual, with an address.
    """
    stats = psutil.net_if_stats()
    result = []
    for name, addresses in psutil.net_if_addrs().items():
        stat = stats.get(name)
        if stat is None or not stat.isup or _ignored(name, profiles):
            continue
        mac = None
        for address in addresses:
            if address.family == getattr(psutil, "AF_LINK", None):
                mac = normalize_mac(address.address)
        for address in addresses:
            if address.family != socket.AF_INET or not address.address:
                continue
            netmask = address.netmask or _assumed_netmask(address.address)
            if not netmask:
                continue
            try:
                network = ipaddress.IPv4Network(f"{address.address}/{netmask}", strict=False)
                ip = ipaddress.IPv4Address(address.address)
            except ValueError:
                continue
            if ip.is_loopback:
                continue
            result.append({
                "name": name,
                "label": interface_label(name),
                "kind": interface_kind(name, profiles),
                "ip": str(ip),
                "netmask": str(netmask),
                "network": str(network),
                "prefix": network.prefixlen,
                "broadcast": str(network.broadcast_address),
                "link_local": ip.is_link_local,
                "mac": mac,
            })
    return sorted(result, key=lambda item: (item["kind"] != "ethernet", item["name"], item["ip"]))


def gateways():
    """
    Default gateway per interface name (or per local IP on Windows).
    """
    found = {}
    if SYSTEM == "windows":
        in_active = False
        for line in run(["route", "print", "-4"]).splitlines():
            if "Active Routes" in line:
                in_active = True
                continue
            if in_active and line.strip().startswith("0.0.0.0"):
                parts = line.split()
                if len(parts) >= 4 and _is_ip(parts[2]):
                    found[parts[3]] = parts[2]  # keyed by the interface IP
            elif in_active and "Persistent Routes" in line:
                break
        return found

    if SYSTEM == "darwin":
        for line in run(["netstat", "-rn", "-f", "inet"]).splitlines():
            parts = line.split()
            if len(parts) >= 4 and parts[0] == "default" and _is_ip(parts[1]):
                found.setdefault(parts[3], parts[1])
        return found

    for line in run(["ip", "-4", "route", "show", "default"]).splitlines():
        match = re.search(r"default via (\S+) dev (\S+)", line)
        if match and _is_ip(match.group(1)):
            found.setdefault(match.group(2), match.group(1))
    return found


def gateway_for(interface, gateway_map):
    return gateway_map.get(interface["name"]) or gateway_map.get(interface["ip"])


def _is_ip(value):
    try:
        ipaddress.IPv4Address(value)
        return True
    except ValueError:
        return False


def arp_table():
    """
    IP -> MAC from the OS neighbour cache. Broadcast/multicast/incomplete rows are dropped.
    """
    entries = {}
    if SYSTEM == "windows":
        output = run(["arp", "-a"])
        pattern = re.compile(r"^\s*(\d+\.\d+\.\d+\.\d+)\s+([0-9a-fA-F]{2}(?:-[0-9a-fA-F]{2}){5})\s+(\w+)", re.M)
        for ip, mac, _kind in pattern.findall(output):
            mac = normalize_mac(mac)
            if mac:
                entries[ip] = mac
        return entries

    if SYSTEM == "linux":
        try:
            with open("/proc/net/arp", "r", encoding="utf-8") as handle:
                for line in handle.readlines()[1:]:
                    parts = line.split()
                    if len(parts) >= 4 and parts[2] != "0x0":
                        mac = normalize_mac(parts[3])
                        if mac:
                            entries[parts[0]] = mac
        except OSError:
            pass
        return entries

    output = run(["arp", "-an"])
    pattern = re.compile(r"\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-fA-F:]+) on (\S+)")
    for ip, mac, _interface in pattern.findall(output):
        mac = normalize_mac(mac)
        if mac:
            entries[ip] = mac
    return entries


def snapshot(profiles):
    """
    Network state + a signature that changes when a link, address or gateway changes.
    """
    items = interfaces(profiles)
    gateway_map = gateways()
    for item in items:
        item["gateway"] = gateway_for(item, gateway_map)
    wired = [item for item in items if item["kind"] == "ethernet"]
    signature = "|".join(
        f"{item['name']}={item['ip']}/{item['prefix']}@{item['gateway'] or '-'}" for item in items
    )
    return {
        "interfaces": items,
        "wired_connected": bool(wired),
        "wired_link_local_only": bool(wired) and all(item["link_local"] for item in wired),
        "signature": signature,
    }


def is_admin():
    try:
        if SYSTEM == "windows":
            import ctypes

            return bool(ctypes.windll.shell32.IsUserAnAdmin())
        import os

        return os.geteuid() == 0
    except Exception:
        return False
