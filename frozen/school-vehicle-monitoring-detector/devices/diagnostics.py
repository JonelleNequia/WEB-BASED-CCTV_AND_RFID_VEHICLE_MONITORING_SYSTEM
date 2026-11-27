"""
Why a scan found (or did not find) devices, in plain words for the Devices
panel: which interfaces were scanned, how many hosts, what the OS itself
sees, and warnings such as "only the router answered on the LAN" or
"this process is blocked from the local network".
"""

import errno
import os
import socket
import sys

from .netinfo import SYSTEM, run

# connect() errors that mean the OS refused to let this process reach the LAN
# (macOS Local Network privacy, or no route at all). A refused or timed-out
# connection still proves access works.
BLOCKED_ERRNOS = {errno.EHOSTUNREACH, errno.ENETUNREACH, errno.EPERM, errno.EACCES}


def local_network_access(interfaces, ports=(80, 443, 53)):
    """
    Try a TCP connection to each gateway. Returns ok / blocked / unknown.
    """
    results = []
    for item in interfaces:
        gateway = item.get("gateway")
        if not gateway:
            continue
        verdict = "unknown"
        for port in ports:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(0.6)
            try:
                code = sock.connect_ex((gateway, port))
            except OSError as error:
                code = error.errno or -1
            finally:
                sock.close()
            if code in BLOCKED_ERRNOS:
                verdict = "blocked"
                break
            # 0 = open, ECONNREFUSED = closed, timeout/EAGAIN = filtered: all reached the network.
            verdict = "ok"
            break
        results.append({"interface": item["name"], "gateway": gateway, "result": verdict})
    if any(entry["result"] == "blocked" for entry in results):
        overall = "blocked"
    elif any(entry["result"] == "ok" for entry in results):
        overall = "ok"
    else:
        overall = "unknown"
    return {"result": overall, "checks": results}


def firewall_state():
    """
    Best-effort OS firewall state and whether this Python may receive replies.
    """
    try:
        if SYSTEM == "darwin":
            tool = "/usr/libexec/ApplicationFirewall/socketfilterfw"
            state = run([tool, "--getglobalstate"])
            block_all = run([tool, "--getblockall"])
            listing = run([tool, "--listapps"])
            executable = os.path.realpath(sys.executable)
            # A framework Python runs as .../Python.app inside its base prefix.
            roots = {os.path.realpath(sys.base_prefix), os.path.dirname(executable)}
            allowed = None
            lines = listing.splitlines()
            for index, line in enumerate(lines):
                path = os.path.realpath(line.split(":", 1)[-1].strip()) if ":" in line else ""
                if path and (path == executable or any(path.startswith(root + os.sep) for root in roots)):
                    allowed = "Allow" in (lines[index + 1] if index + 1 < len(lines) else "")
                    break
            return {
                "enabled": "enabled" in state.lower() or "state = 1" in state.lower() or "state = 2" in state.lower(),
                "block_all": "enabled" in block_all.lower() and "disabled" not in block_all.lower(),
                "python_allowed": allowed,
                "python": executable,
            }
        if SYSTEM == "windows":
            output = run(["netsh", "advfirewall", "show", "currentprofile", "state"])
            return {"enabled": " ON" in output.upper(), "block_all": None, "python_allowed": None, "python": sys.executable}
    except Exception:
        pass
    return {"enabled": None, "block_all": None, "python_allowed": None, "python": sys.executable}


def build(snap, sweep, live, arp, onvif, modules, arp_stats, errors, access, firewall, admin):
    """
    The Diagnostics block stored with every scan.
    """
    interfaces = []
    for item in snap["interfaces"]:
        os_hosts = sorted(
            ({"ip": ip, "mac": mac} for ip, mac in arp.items() if _in(ip, item) and ip != item["ip"]),
            key=lambda entry: tuple(int(part) for part in entry["ip"].split(".")),
        )
        interfaces.append({
            "name": item["name"],
            "label": item.get("label"),
            "kind": item["kind"],
            "ip": item["ip"],
            "network": item["network"],
            "gateway": item.get("gateway"),
            "link_local": item["link_local"],
            "hosts_swept": len(sweep.get(item["name"], [])),
            "os_hosts": os_hosts,
            "only_gateway": bool(os_hosts) and all(entry["ip"] == item.get("gateway") for entry in os_hosts),
        })

    warnings = []
    wired = [item for item in interfaces if item["kind"] == "ethernet"]

    if not snap["interfaces"]:
        warnings.append(_warn("no_network", "critical", "This PC has no network connection."))
    elif not wired:
        warnings.append(_warn(
            "no_ethernet", "warning",
            "No LAN (Ethernet) connection on this PC. Only Wi-Fi was scanned. Plug the LAN cable into this PC or its USB LAN adapter.",
        ))
    for item in wired:
        if item["link_local"]:
            warnings.append(_warn(
                "link_local", "warning",
                f"{item['label'] or item['name']} has only {item['ip']} (no DHCP). This is a direct cable or the router gives no addresses. "
                "Devices with a fixed IP on another network are reached only with a temporary IP (admin rights).",
            ))
        elif not item["os_hosts"]:
            warnings.append(_warn(
                "lan_empty", "warning",
                f"Nothing answered on {item['label'] or item['name']} ({item['network']}), not even the router.",
            ))
        elif item["only_gateway"]:
            warnings.append(_warn(
                "lan_only_router", "warning",
                f"Only the router ({item['gateway']}) answered on {item['label'] or item['name']} ({item['network']}). "
                "The camera and reader are not on this network: check their power and that they use the router's LAN ports. "
                "Some routers (for example ZTE fiber modems) isolate their LAN ports. A device with a fixed IP on another "
                "network also stays hidden: run php artisan devices:scan --allow-temp-ip with admin rights, or set the device to DHCP.",
            ))

    if access["result"] == "blocked":
        warnings.append(_warn(
            "local_network_blocked", "critical",
            "The OS blocks this program from the local network. On macOS: System Settings › Privacy & Security › "
            "Local Network, then turn on the app that runs the system (Terminal, iTerm or Visual Studio Code) and restart it.",
        ))
    blocked_sends = sum(count for reason, count in (arp_stats or {}).get("errors", {}).items() if "route" in reason.lower() or "permitted" in reason.lower())
    if blocked_sends and blocked_sends >= max(1, int((arp_stats or {}).get("sent", 0) * 0.9)) and access["result"] != "blocked":
        warnings.append(_warn(
            "sends_failed", "critical",
            f"Sending to the network failed ({', '.join(f'{k}: {v}' for k, v in arp_stats['errors'].items())}). "
            "The OS or a security app is blocking this program from the local network.",
        ))
    elif blocked_sends and access["result"] != "blocked" and blocked_sends < int((arp_stats or {}).get("sent", 0) * 0.9):
        warnings.append(_warn(
            "sends_skipped", "info",
            f"{blocked_sends} address(es) were skipped because this PC found nothing there a moment ago "
            "(normal right after another scan). The next scan tries them again.",
        ))
    if firewall.get("enabled") and firewall.get("block_all"):
        warnings.append(_warn(
            "firewall_block_all", "critical",
            "The firewall blocks all incoming connections, so camera and reader replies are dropped. Turn off \"Block all incoming connections\".",
        ))
    elif firewall.get("enabled") and firewall.get("python_allowed") is False:
        warnings.append(_warn(
            "firewall_python", "warning",
            f"The firewall does not allow Python ({firewall.get('python')}) to receive replies. Allow it in the firewall settings.",
        ))
    for message in errors:
        warnings.append(_warn("probe_error", "warning", message))

    return {
        "interfaces": interfaces,
        "onvif_replies": len(onvif),
        "module_replies": len(modules),
        "live_hosts": len(live),
        "arp_sends": arp_stats,
        "local_network": access,
        "firewall": firewall,
        "admin": admin,
        "platform": SYSTEM,
        "warnings": warnings,
    }


def _warn(code, level, message):
    return {"code": code, "level": level, "message": message}


def _in(ip, interface):
    import ipaddress

    try:
        return ipaddress.IPv4Address(ip) in ipaddress.IPv4Network(interface["network"])
    except ValueError:
        return False
