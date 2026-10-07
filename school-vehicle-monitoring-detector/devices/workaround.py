"""
Live view work, Phase 2: TEMPORARY WORKAROUND (development only).

While a reader still has an address on another subnet, the PC reaches it
only with a second address in that subnet on the network card it is
plugged into. This adds that address by itself at start-up, if the
permission was set up once:

- macOS: `tools/start/allow-reader-workaround-mac.sh` (a sudoers rule for
  `ifconfig <card> alias`); the address is gone after a restart and is
  added again;
- Windows: the system runs elevated through the startup task
  (install-windows-autostart.ps1); the address is added for this session
  only (PolicyStore ActiveStore);
- Linux: a sudoers rule for `ip addr add`.

The real fix is moving the reader into the PC's network (module_config.py)
or with NetModuleConfig; then this is not needed and the address it added
is removed.
"""

import ipaddress
import subprocess

from . import tempip
from .netinfo import SYSTEM, is_admin


def _run(command, timeout=15):
    kwargs = {"capture_output": True, "text": True, "timeout": timeout}
    if SYSTEM == "windows":
        kwargs["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
    try:
        done = subprocess.run(command, **kwargs)
        return done.returncode, (done.stdout or "") + (done.stderr or "")
    except (OSError, subprocess.SubprocessError) as error:
        return 1, str(error)


def commands(interface, address, admin=None):
    """add / remove commands for this system (sudo -n: never asks for a password)."""
    admin = is_admin() if admin is None else admin
    name, ip, mask, prefix = interface["name"], address["ip"], address["netmask"], address["prefix"]
    sudo = [] if admin else ["sudo", "-n"]
    if SYSTEM == "darwin":
        return {"add": sudo + ["/sbin/ifconfig", name, "alias", ip, mask], "remove": sudo + ["/sbin/ifconfig", name, "-alias", ip]}
    if SYSTEM == "windows":
        label = (interface.get("label") or name).replace("'", "''")
        return {
            "add": ["powershell", "-NoProfile", "-NonInteractive", "-Command",
                    f"New-NetIPAddress -InterfaceAlias '{label}' -IPAddress {ip} -PrefixLength {prefix} -SkipAsSource $true -PolicyStore ActiveStore | Out-Null"],
            "remove": ["powershell", "-NoProfile", "-NonInteractive", "-Command",
                       f"Remove-NetIPAddress -IPAddress {ip} -Confirm:$false"],
        }
    return {"add": sudo + ["ip", "addr", "add", f"{ip}/{prefix}", "dev", name], "remove": sudo + ["ip", "addr", "del", f"{ip}/{prefix}", "dev", name]}


def _needs_permission(output):
    text = output.lower()
    return any(word in text for word in ("password is required", "a terminal is required", "access is denied", "permission denied", "operation not permitted", "administrator"))


class ReaderWorkaround:
    """
    check(): per gate reader, whether the extra address is needed, added,
    or waiting for permission. `search(mac)` -> (interface, module) finds
    the reader's module by MAC (UDP broadcast, works across subnets).
    """

    def __init__(self, log, run=_run):
        self.log = log
        self.run = run
        self.added = {}       # station -> {"interface", "address"}
        self.status = {}
        self.failed_at = {}

    def check(self, enabled, targets, interfaces, known_ips, search, now):
        for station, target in (targets or {}).items():
            self.status[station] = self._check_one(station, enabled, target or {}, interfaces, known_ips, search, now)
        for station in [item for item in self.status if item not in (targets or {})]:
            self.status.pop(station, None)
        return self.status

    def _check_one(self, station, enabled, target, interfaces, known_ips, search, now):
        ip, mac = target.get("ip"), target.get("mac")
        if not ip or not mac:
            return {"state": "not_needed"}
        if self._reachable(ip, interfaces, station):
            if station in self.added and not self._uses_added(ip, station):
                self._remove(station)
            return {"state": "active", **self._describe(station)} if station in self.added else {"state": "not_needed"}
        if not enabled:
            return {"state": "off"}

        interface, module = search(mac)
        if not module:
            return {"state": "not_found"}
        reader_ip = module["ip"]
        if self._reachable(reader_ip, interfaces, station):
            return {"state": "active", **self._describe(station)} if station in self.added else {"state": "not_needed"}
        if now - self.failed_at.get(station, -1e9) < 300:
            return self.status.get(station) or {"state": "needs_permission"}

        address = tempip.plan(reader_ip, set(known_ips) | {item["ip"] for item in interfaces})
        if not address:
            return {"state": "failed", "message": "No free address in the reader's network."}
        code, output = self.run(commands(interface, address)["add"])
        if code != 0:
            self.failed_at[station] = now
            state = "needs_permission" if _needs_permission(output) else "failed"
            self.log(f"Reader workaround for {station}: could not add {address['ip']} on {interface['name']} ({state}): {output.strip()[:200]}")
            return {"state": state, "interface": interface.get("label") or interface["name"], "ip": address["ip"], "reader_ip": reader_ip,
                    "message": output.strip()[:200]}
        self.added[station] = {"interface": interface, "address": address, "reader_ip": reader_ip}
        self.log(f"Reader workaround for {station}: added {address['ip']}/{address['prefix']} on {interface['name']} (temporary)")
        return {"state": "active", **self._describe(station)}

    def _reachable(self, ip, interfaces, station):
        try:
            address = ipaddress.IPv4Address(ip)
        except ValueError:
            return False
        return any(address in ipaddress.IPv4Network(item["network"], strict=False) for item in interfaces)

    def _uses_added(self, ip, station):
        added = self.added.get(station)
        return bool(added) and ipaddress.IPv4Address(ip) in ipaddress.IPv4Network(f"{added['address']['ip']}/{added['address']['prefix']}", strict=False)

    def _describe(self, station):
        added = self.added.get(station) or {}
        return {
            "interface": (added.get("interface") or {}).get("label") or (added.get("interface") or {}).get("name"),
            "ip": (added.get("address") or {}).get("ip"),
            "reader_ip": added.get("reader_ip"),
        }

    def _remove(self, station):
        added = self.added.pop(station)
        code, output = self.run(commands(added["interface"], added["address"])["remove"])
        self.log(f"Reader workaround for {station}: {'removed' if code == 0 else 'could not remove'} {added['address']['ip']} (reader moved)")
