"""
Live view work, Phase 2: change the reader's network settings without a
terminal, through the setup protocol of its Ethernet module.

The protocol is the USR-TCP232-T2 one (manual section 4.6): UDP broadcast to
port 1500, so it reaches the module even when its address is on another
subnet (same cable or switch). Measured 2026-10-06 on the gate reader:

- search  FF 01 01 02 -> FF 24 01 00 00 | IP (big-endian) | MAC | 00 00 |
  version | name (16) | checksum;
- read    FF 13 03 | MAC | user (6) | password (6) | sum -> the settings
  block without a frame: 67 bytes "basic" (flags, IP, gateway, mask, name,
  login, ID, MAC, DNS) + 63 bytes "port" (baud rate, serial format, local
  port, work mode...), all little-endian;
- write   FF 56 05 | MAC | user | password | 67 basic bytes | sum -> FF 01 05 'K';
- restart FF 13 02 | MAC | user | password | sum -> FF 01 02 'K'.

Checksum: the sum of every byte after FF, lowest byte. A wrong login is
answered with 'P'.

Only the basic block is written (address, gateway, mask, DHCP flag); it is
the block just read, with those bytes changed. The port block (work mode TCP
server, port, serial settings) is never sent. Before writing, the block must
match what is known about the reader (its MAC, its address from the search,
its TCP port from the assignment); otherwise nothing is written.
"""

import ipaddress
import select
import struct
import time

BASIC_SIZE = 67
PORT_SIZE = 63

CMD_SEARCH = 0x01
CMD_RESTART = 0x02
CMD_READ = 0x03
CMD_WRITE_BASIC = 0x05

FLAG_STATIC_IP = 0x80

WORK_MODES = {0: "UDP client", 1: "TCP client", 2: "UDP server", 3: "TCP server", 4: "HTTPD client"}
PARITY = {1: "none", 2: "odd", 3: "even", 4: "mark", 5: "space"}


class ModuleError(Exception):
    """code: no_answer, wrong_login, rejected, layout, not_found."""

    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def checksum(data):
    return sum(data) & 0xFF


def frame(command, payload=b""):
    body = bytes([(1 + len(payload)) & 0xFF, command]) + payload
    return b"\xff" + body + bytes([checksum(body)])


def mac_bytes(mac):
    return bytes.fromhex(mac.replace(":", "").replace("-", ""))


def login(mac, username, password):
    return mac_bytes(mac) + str(username).encode()[:5].ljust(6, b"\0") + str(password).encode()[:5].ljust(6, b"\0")


def _ip_le(data, offset):
    return str(ipaddress.IPv4Address(bytes(reversed(data[offset:offset + 4]))))


def _le_ip(ip):
    return bytes(reversed(ipaddress.IPv4Address(ip).packed))


def parse_search_reply(data):
    """{ip, mac, version, name} or None."""
    if len(data) < 35 or data[0] != 0xFF or data[2] != CMD_SEARCH or checksum(data[1:-1]) != data[-1]:
        return None
    return {
        "ip": str(ipaddress.IPv4Address(data[5:9])),
        "mac": ":".join(f"{byte:02X}" for byte in data[9:15]),
        "version": data[17:19].hex(),
        "name": data[19:35].split(b"\0")[0].decode("latin-1", "ignore"),
    }


def parse_settings(block):
    """The read answer (first packet): basic + port block, decoded."""
    if len(block) < BASIC_SIZE + PORT_SIZE or block[0] == 0xFF:
        raise ModuleError("layout", "The reader's settings answer has an unexpected size.")
    port = BASIC_SIZE
    return {
        "dhcp": not bool(block[3] & FLAG_STATIC_IP),
        "ip": _ip_le(block, 9),
        "gateway": _ip_le(block, 13),
        "netmask": _ip_le(block, 17),
        "name": block[21:35].split(b"\0")[0].decode("latin-1", "ignore"),
        "mac": ":".join(f"{byte:02X}" for byte in block[53:59]),
        "dns": _ip_le(block, 59),
        "baud_rate": struct.unpack_from("<I", block, port)[0],
        "data_bits": block[port + 4],
        "parity": PARITY.get(block[port + 5], str(block[port + 5])),
        "stop_bits": block[port + 6],
        "local_port": struct.unpack_from("<H", block, port + 12)[0],
        "remote_port": struct.unpack_from("<H", block, port + 14)[0],
        "work_mode": WORK_MODES.get(block[port + 51], str(block[port + 51])),
        "max_clients": block[port + 53],
        "basic_hex": block[:BASIC_SIZE].hex(),
    }


def check_layout(settings, mac, ip=None, port=None):
    """The block belongs to this reader and reads as expected (else: no write)."""
    problems = []
    if settings["mac"].upper() != mac.upper():
        problems.append(f"MAC {settings['mac']} instead of {mac}")
    if ip and not settings["dhcp"] and settings["ip"] != ip:
        problems.append(f"address {settings['ip']} instead of {ip}")
    if port and int(settings["local_port"]) != int(port):
        problems.append(f"port {settings['local_port']} instead of {port}")
    try:
        ipaddress.IPv4Network(f"0.0.0.0/{settings['netmask']}")
    except ValueError:
        problems.append(f"subnet mask {settings['netmask']}")
    if problems:
        raise ModuleError("layout", "The reader's settings do not read as expected (" + "; ".join(problems) + "). Nothing was changed.")


def new_basic_block(settings, mode, ip=None, netmask=None, gateway=None):
    """The basic block just read, with only the address fields changed."""
    block = bytearray(bytes.fromhex(settings["basic_hex"]))
    if mode == "dhcp":
        block[3] &= ~FLAG_STATIC_IP & 0xFF
    else:
        block[3] |= FLAG_STATIC_IP
        block[9:13] = _le_ip(ip)
        block[13:17] = _le_ip(gateway or "0.0.0.0")
        block[17:21] = _le_ip(netmask)
    return bytes(block)


def masked_hex(block):
    """Settings bytes for logs, without the module login (offsets 37-48)."""
    data = bytearray(block)
    data[37:49] = b"\0" * 12
    return data.hex(" ").upper()


class ModuleSetup:
    """
    Talks to one reader module through one network card. `transport` is
    (packet, wait) -> [(host, data)], replaced in tests.
    """

    def __init__(self, profile, interface=None, transport=None, log=print):
        self.profile = profile or {}
        self.interface = interface
        self.transport = transport or self._udp
        self.log = log
        self.port = int(self.profile.get("port", 1500))
        self.wait = float(self.profile.get("reply_seconds", 2.5))

    def _udp(self, packet, wait):
        from .probes import _socket_for_interface

        sock = _socket_for_interface(self.interface or {"name": None, "ip": ""})
        replies = []
        try:
            for destination in {"255.255.255.255", (self.interface or {}).get("broadcast") or "255.255.255.255"}:
                sock.sendto(packet, (destination, self.port))
            deadline = time.monotonic() + wait
            own = (self.interface or {}).get("ip")
            while time.monotonic() < deadline:
                ready, _, _ = select.select([sock], [], [], 0.2)
                if ready:
                    data, (host, _port) = sock.recvfrom(4096)
                    if host != own:
                        replies.append((host, data))
        finally:
            sock.close()
        return replies

    def login(self, mac, username=None, password=None):
        return login(mac, username or self.profile.get("username", "admin"), password or self.profile.get("password", "admin"))

    # -- commands -------------------------------------------------------
    def search(self, mac=None, wait=None):
        found = {}
        for _host, data in self.transport(frame(CMD_SEARCH), wait or self.wait):
            reply = parse_search_reply(data)
            if reply and (mac is None or reply["mac"].upper() == mac.upper()):
                found[reply["mac"]] = reply
        return found

    def read(self, mac, username=None, password=None):
        for _host, data in self.transport(frame(CMD_READ, self.login(mac, username, password)), self.wait):
            if data[:4] == bytes([0xFF, 0x01, CMD_READ, ord("P")]):
                raise ModuleError("wrong_login", "The reader's module did not accept its login (username / password).")
            if data[:1] != b"\xff" and len(data) >= BASIC_SIZE + PORT_SIZE and data[53:59] == mac_bytes(mac):
                return parse_settings(data)
        raise ModuleError("no_answer", "The reader did not answer the settings request.")

    def _command(self, command, payload, what):
        replies = self.transport(frame(command, payload), self.wait)
        for _host, data in replies:
            if data[:3] == bytes([0xFF, 0x01, command]) and len(data) >= 4:
                if data[3] == ord("K"):
                    return True
                if data[3] == ord("P"):
                    raise ModuleError("wrong_login", "The reader's module did not accept its login (username / password).")
                raise ModuleError("rejected", f"The reader refused the {what}.")
        raise ModuleError("no_answer", f"The reader did not confirm the {what}.")

    def write_basic(self, mac, block, username=None, password=None):
        if len(block) != BASIC_SIZE:
            raise ModuleError("layout", "Wrong settings size; nothing was sent.")
        return self._command(CMD_WRITE_BASIC, self.login(mac, username, password) + block, "new settings")

    def restart(self, mac, username=None, password=None):
        return self._command(CMD_RESTART, self.login(mac, username, password), "restart")

    # -- the whole move ---------------------------------------------------
    def move(self, mac, expected_ip, expected_port, mode, target, username=None, password=None, progress=None):
        """
        Read, check, write the new address, restart, find it again by MAC.
        target: {ip, netmask, gateway, network} (static) or {network} (DHCP).
        Returns {before, after_ip}.
        """
        say = progress or (lambda message: None)
        say("Reading the reader's settings...")
        before = self.read(mac, username, password)
        check_layout(before, mac, expected_ip, expected_port)
        block = new_basic_block(before, mode, target.get("ip"), target.get("netmask"), target.get("gateway"))
        say("Saving the new address in the reader...")
        self.write_basic(mac, block, username, password)
        say("Restarting the reader...")
        self.restart(mac, username, password)

        say("Waiting for the reader to come back...")
        network = ipaddress.IPv4Network(target["network"], strict=False)
        deadline = time.monotonic() + float(self.profile.get("restart_wait_seconds", 45))
        while time.monotonic() < deadline:
            found = self.search(mac, wait=2.0).get(mac.upper())
            if found and ipaddress.IPv4Address(found["ip"]) in network and (mode == "dhcp" or found["ip"] == target["ip"]):
                return {"before": before, "after_ip": found["ip"]}
        raise ModuleError("not_found", "The reader did not answer at its new address after the restart.")


def free_address(interface, taken, answers):
    """
    A free address for the reader in this card's network: from the top of
    the range down, skipping this PC, the router, known devices and any
    address that answers (`answers(ip)` -> bool, e.g. a ping).
    """
    network = ipaddress.IPv4Network(interface["network"], strict=False)
    skip = {str(item) for item in taken} | {interface.get("ip"), interface.get("gateway")}
    checked = 0
    for host in reversed(list(network.hosts())):
        candidate = str(host)
        if candidate in skip:
            continue
        checked += 1
        if checked > 20:
            break
        if not answers(candidate):
            return candidate
    return None
