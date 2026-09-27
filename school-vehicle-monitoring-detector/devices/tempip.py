"""
Temporary second IP address on a wired interface, to reach a device that
sits on another subnet (e.g. a reader still on its factory address, or a
direct cable where the PC only has 169.254.x.x).

Only possible with admin rights. Without them the scanner returns the exact
commands so the UI can show them.
"""

import ipaddress

from .netinfo import SYSTEM, is_admin, run


def plan(device_ip, known_ips, prefix=24):
    """
    Pick a free address in the device's subnet for this PC. The subnet comes
    from the device's own address (its mask is unknown, so the common /24 is
    assumed); the highest address not used by a known device is chosen.
    """
    network = ipaddress.IPv4Network(f"{device_ip}/{prefix}", strict=False)
    taken = {str(ip) for ip in known_ips} | {device_ip}
    for host in reversed(list(network.hosts())):
        if str(host) not in taken:
            return {
                "network": str(network),
                "ip": str(host),
                "netmask": str(network.netmask),
                "prefix": network.prefixlen,
            }
    return None


def commands(interface, address):
    """
    Add/remove commands for the OS, shown in the UI when the service cannot run them.
    """
    name = interface.get("name")
    label = interface.get("label") or name
    ip = address["ip"]
    mask = address["netmask"]
    return {
        "darwin": {
            "add": f"sudo ifconfig {name} alias {ip} {mask}",
            "remove": f"sudo ifconfig {name} -alias {ip}",
        },
        "windows": {
            # Windows refuses a second address while DHCP is on, so switch to
            # static for the check and back to DHCP afterwards.
            "add": f'netsh interface ipv4 set address name="{label}" static {ip} {mask}',
            "remove": f'netsh interface ipv4 set address name="{label}" source=dhcp',
        },
        "linux": {
            "add": f"sudo ip addr add {ip}/{address['prefix']} dev {name}",
            "remove": f"sudo ip addr del {ip}/{address['prefix']} dev {name}",
        },
    }


class TemporaryAddress:
    """
    Context manager: add the address, yield, always remove it.
    """

    def __init__(self, interface, address, log):
        self.interface = interface
        self.address = address
        self.log = log
        self.added = False

    def __enter__(self):
        if not is_admin():
            return False
        command = self._command("add")
        if not command:
            return False
        run(command, timeout=15)
        self.added = True
        self.log(f"Temporary address {self.address['ip']} added on {self.interface['name']}")
        return True

    def __exit__(self, *exc):
        if self.added:
            run(self._command("remove"), timeout=15)
            self.log(f"Temporary address {self.address['ip']} removed from {self.interface['name']}")
        return False

    def _command(self, action):
        name = self.interface["name"]
        label = self.interface.get("label") or name
        ip = self.address["ip"]
        mask = self.address["netmask"]
        if SYSTEM == "darwin":
            return ["ifconfig", name, "alias", ip, mask] if action == "add" else ["ifconfig", name, "-alias", ip]
        if SYSTEM == "windows":
            if action == "add":
                return ["netsh", "interface", "ipv4", "set", "address", f"name={label}", "static", ip, mask]
            return ["netsh", "interface", "ipv4", "set", "address", f"name={label}", "source=dhcp"]
        if SYSTEM == "linux":
            verb = "add" if action == "add" else "del"
            return ["ip", "addr", verb, f"{ip}/{self.address['prefix']}", "dev", name]
        return None
