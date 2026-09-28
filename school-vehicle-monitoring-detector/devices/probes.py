"""
Network probes used by the scanner. Every probe has a timeout; nothing blocks
for long and nothing is retried forever.
"""

import asyncio
import re
import select
import socket
import time
import uuid
import xml.etree.ElementTree as ElementTree

from . import uhf


# ---------------------------------------------------------------------------
# ARP trigger
# ---------------------------------------------------------------------------

def trigger_arp(hosts, port, pause_every=64):
    """
    Send one empty UDP datagram to every host. The OS resolves each address
    with ARP first, so live hosts appear in the ARP table even when they have
    no open port and ignore ping.
    """
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setblocking(False)
    errors = {}
    try:
        for index, host in enumerate(hosts):
            try:
                sock.sendto(b"", (host, int(port)))
            except OSError as error:
                # Counted, not hidden: "No route to host" for every host means
                # the OS blocks this process from the local network.
                key = error.strerror or error.__class__.__name__
                errors[key] = errors.get(key, 0) + 1
            if index and index % pause_every == 0:
                time.sleep(0.01)
    finally:
        sock.close()
    return {"sent": len(hosts), "errors": errors}


# ---------------------------------------------------------------------------
# TCP
# ---------------------------------------------------------------------------

async def tcp_open(host, port, timeout):
    try:
        reader, writer = await asyncio.wait_for(asyncio.open_connection(host, port), timeout)
    except (OSError, asyncio.TimeoutError):
        return False
    writer.close()
    try:
        await asyncio.wait_for(writer.wait_closed(), 0.5)
    except (OSError, asyncio.TimeoutError):
        pass
    return True


async def open_ports(host, ports, timeout, semaphore):
    async def check(port):
        async with semaphore:
            return port if await tcp_open(host, port, timeout) else None

    results = await asyncio.gather(*(check(port) for port in ports))
    return sorted(port for port in results if port)


async def _exchange(host, port, payload, timeout, read_limit=4096, passive_seconds=0.0):
    """
    Connect, optionally listen first, send payload, read what comes back.
    Returns (data_before_sending, data_after_sending) or None when unreachable.
    """
    try:
        reader, writer = await asyncio.wait_for(asyncio.open_connection(host, port), timeout)
    except (OSError, asyncio.TimeoutError):
        return None

    before = b""
    after = b""
    try:
        if passive_seconds > 0:
            before = await _read_for(reader, passive_seconds, read_limit)
        if payload:
            writer.write(payload)
            await writer.drain()
            after = await _read_for(reader, timeout, read_limit)
    except OSError:
        pass
    finally:
        writer.close()
        try:
            await asyncio.wait_for(writer.wait_closed(), 0.5)
        except (OSError, asyncio.TimeoutError):
            pass
    return before, after


async def _read_for(reader, seconds, limit):
    data = b""
    deadline = time.monotonic() + seconds
    while len(data) < limit:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            break
        try:
            chunk = await asyncio.wait_for(reader.read(limit - len(data)), remaining)
        except asyncio.TimeoutError:
            break
        if not chunk:
            break
        data += chunk
    return data


async def rtsp_options(host, port, timeout):
    """
    RTSP OPTIONS without credentials. Any "RTSP/1.0" answer (even 401) proves
    an RTSP server.
    """
    request = (
        f"OPTIONS rtsp://{host}:{port}/ RTSP/1.0\r\n"
        "CSeq: 1\r\n"
        "User-Agent: philcst-device-scan\r\n\r\n"
    ).encode()
    result = await _exchange(host, port, request, timeout, 2048)
    if not result or not result[1].startswith(b"RTSP/"):
        return None
    text = result[1].decode("latin-1", "ignore")
    server = re.search(r"^Server:\s*(.+)$", text, re.M | re.I)
    return {"port": port, "server": server.group(1).strip() if server else None}


async def http_banner(host, port, timeout):
    request = (
        f"GET / HTTP/1.0\r\nHost: {host}\r\nUser-Agent: philcst-device-scan\r\nConnection: close\r\n\r\n"
    ).encode()
    result = await _exchange(host, port, request, timeout, 6144)
    if not result or not result[1].startswith(b"HTTP/"):
        return None
    text = result[1].decode("latin-1", "ignore")
    server = re.search(r"^Server:\s*(.+)$", text, re.M | re.I)
    realm = re.search(r'realm="([^"]+)"', text, re.I)
    title = re.search(r"<title>\s*([^<]{1,120})</title>", text, re.I)
    return {
        "port": port,
        "server": server.group(1).strip() if server else None,
        "realm": realm.group(1).strip() if realm else None,
        "title": title.group(1).strip() if title else None,
    }


async def reader_tcp_probe(host, port, profiles):
    """
    Decide whether host:port is a UHF reader.

    1. Listen first: readers in "active" mode push tag data by themselves.
    2. Otherwise send the info/inventory commands from the profiles and
       accept only a reply with a valid frame + checksum.
    """
    scan = profiles.get("scan", {})
    timeout = float(scan.get("reader_reply_timeout_seconds", 0.8))
    passive = float(scan.get("reader_passive_listen_seconds", 1.5))
    connect_timeout = float(scan.get("connect_timeout_seconds", 0.7))

    # One connection for the whole check: many readers accept a single client.
    try:
        reader, writer = await asyncio.wait_for(asyncio.open_connection(host, port), connect_timeout)
    except (OSError, asyncio.TimeoutError):
        return None

    unsolicited = b""
    try:
        unsolicited = await _read_for(reader, passive, 4096)
        if unsolicited:
            frames, protocol = uhf.decode_once(unsolicited)
            if frames:
                return _reader_result("tcp", port, protocol, "active", frames, unsolicited)

        for protocol, _purpose, command in uhf.probe_commands(profiles):
            writer.write(command)
            await writer.drain()
            reply = await _read_for(reader, timeout, 4096)
            if not reply:
                continue
            frames, detected = uhf.decode_once(reply, protocol)
            if not frames:
                frames, detected = uhf.decode_once(reply)
            if frames:
                return _reader_result("tcp", port, detected or protocol, "answer", frames, reply)
    except OSError:
        pass
    finally:
        writer.close()
        try:
            await asyncio.wait_for(writer.wait_closed(), 0.5)
        except (OSError, asyncio.TimeoutError):
            pass

    return {
        "transport": "tcp",
        "port": port,
        "protocol": None,
        "work_mode": "unknown",
        "confirmed": False,
        "raw_sample_hex": (unsolicited or b"")[:64].hex(" ").upper() or None,
    }


OTHER_SERVICE_MARKERS = (b"HTTP/", b"RTSP/", b"SSH-", b"<html", b"<HTML", b"<?xml", b"220 ", b"+OK")


def _other_service(data):
    """Banners of web/ssh/mail servers etc. are never reader output."""
    head = data[:64]
    return any(marker in head for marker in OTHER_SERVICE_MARKERS)


def _reader_result(transport, port, protocol, mode, frames, raw):
    if protocol == "text" and _other_service(raw):
        return {
            "transport": transport,
            "port": port,
            "protocol": None,
            "work_mode": "unknown",
            "confirmed": False,
            "raw_sample_hex": raw[:64].hex(" ").upper(),
            "other_service": True,
        }
    tags = sorted({frame.epc for frame in frames if frame.epc})
    return {
        "transport": transport,
        "port": port,
        "protocol": protocol,
        "work_mode": mode,
        "confirmed": True,
        "sample_tags": tags[:5],
        "raw_sample_hex": raw[:64].hex(" ").upper(),
    }


# ---------------------------------------------------------------------------
# UDP
# ---------------------------------------------------------------------------

def reader_udp_probe(hosts, profiles):
    """
    Send the probe commands to every host's UDP reader ports at once and keep
    replies that decode as a valid reader frame.
    """
    ports = profiles.get("uhf_reader", {}).get("udp_ports", [])
    commands = uhf.probe_commands(profiles)
    wait = float(profiles.get("scan", {}).get("udp_reply_seconds", 2.5))
    if not hosts or not ports or not commands:
        return {}

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setblocking(False)
    results = {}
    try:
        sock.bind(("", 0))
        for host in hosts:
            for port in ports:
                for _protocol, _purpose, command in commands:
                    try:
                        sock.sendto(command, (host, int(port)))
                    except OSError:
                        pass
        deadline = time.monotonic() + wait
        while time.monotonic() < deadline:
            ready, _, _ = select.select([sock], [], [], 0.2)
            if not ready:
                continue
            try:
                data, (host, port) = sock.recvfrom(4096)
            except OSError:
                continue
            frames, protocol = uhf.decode_once(data)
            if frames and host not in results:
                results[host] = _reader_result("udp", port, protocol, "answer", frames, data)
    finally:
        sock.close()
    return results


def broadcast_discovery(interfaces, profiles, errors=None):
    """
    Search packets used by the serial-to-Ethernet modules inside many generic
    readers. Any reply means "a network module lives at this address".
    """
    probes = profiles.get("uhf_reader", {}).get("broadcast_discovery", [])
    wait = float(profiles.get("scan", {}).get("udp_reply_seconds", 2.5))
    own_ips = {item["ip"] for item in interfaces}
    destinations = {"255.255.255.255"} | {item["broadcast"] for item in interfaces}
    sockets = []
    replies = {}

    try:
        for probe in probes:
            payload = bytes.fromhex(probe["payload_hex"]) if probe.get("payload_hex") else str(probe.get("payload_text", "")).encode()
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
            sock.setblocking(False)
            try:
                sock.bind(("", 0))
            except OSError as error:
                _note(errors, f"Reader broadcast ({probe['name']}): could not open a socket: {error}")
                sock.close()
                continue
            for destination in destinations:
                try:
                    sock.sendto(payload, (destination, int(probe["port"])))
                except OSError as error:
                    _note(errors, f"Reader broadcast to {destination}: {error}")
            sockets.append((sock, probe["name"]))

        deadline = time.monotonic() + wait
        while sockets and time.monotonic() < deadline:
            ready, _, _ = select.select([sock for sock, _ in sockets], [], [], 0.2)
            for sock in ready:
                try:
                    data, (host, _port) = sock.recvfrom(4096)
                except OSError:
                    continue
                if host in own_ips:
                    continue
                name = next(label for candidate, label in sockets if candidate is sock)
                replies.setdefault(host, {"module": name, "raw_hex": data[:64].hex(" ").upper(), "text": _ascii(data)})
    finally:
        for sock, _ in sockets:
            sock.close()
    return replies


def _ascii(data):
    text = data.decode("latin-1", "ignore")
    printable = re.sub(r"[^\x20-\x7e]+", " ", text).strip()
    return printable[:120] or None


# ---------------------------------------------------------------------------
# ONVIF WS-Discovery (cameras)
# ---------------------------------------------------------------------------

WS_PROBE = (
    '<?xml version="1.0" encoding="UTF-8"?>'
    '<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope" '
    'xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing" '
    'xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" '
    'xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
    "<e:Header><w:MessageID>uuid:{message_id}</w:MessageID>"
    '<w:To e:mustUnderstand="true">urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
    '<w:Action e:mustUnderstand="true">http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>'
    "</e:Header><e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body></e:Envelope>"
)


def _note(errors, message):
    if errors is not None and message not in errors:
        errors.append(message)


def onvif_discovery(interfaces, profiles, errors=None):
    """
    Multicast a WS-Discovery probe out of every interface and parse ProbeMatches.
    Works across subnets on the same cable because it is multicast.
    """
    onvif = profiles.get("camera", {}).get("onvif", {})
    group = onvif.get("multicast_group")
    port = onvif.get("port")
    wait = float(onvif.get("listen_seconds", 3))
    if not group or not port:
        return {}

    sockets = []
    for item in interfaces:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
        sock.setblocking(False)
        try:
            sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 2)
            sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF, socket.inet_aton(item["ip"]))
            sock.bind((item["ip"], 0))
            sock.sendto(WS_PROBE.format(message_id=uuid.uuid4()).encode(), (group, int(port)))
            sockets.append(sock)
        except OSError as error:
            _note(errors, f"ONVIF multicast on {item['name']} ({item['ip']}): {error}")
            sock.close()

    found = {}
    deadline = time.monotonic() + wait
    try:
        while sockets and time.monotonic() < deadline:
            ready, _, _ = select.select(sockets, [], [], 0.2)
            for sock in ready:
                try:
                    data, (host, _port) = sock.recvfrom(65535)
                except OSError:
                    continue
                parsed = parse_probe_match(data)
                for match in parsed:
                    ip = match.get("ip") or host
                    found.setdefault(ip, {**match, "reply_from": host})
    finally:
        for sock in sockets:
            sock.close()
    return found


def parse_probe_match(data):
    try:
        root = ElementTree.fromstring(data)
    except ElementTree.ParseError:
        return []
    matches = []
    for node in root.iter():
        if not node.tag.endswith("ProbeMatch"):
            continue
        xaddrs = scopes = address = ""
        for child in node.iter():
            tag = child.tag.rsplit("}", 1)[-1]
            if tag == "XAddrs":
                xaddrs = (child.text or "").strip()
            elif tag == "Scopes":
                scopes = (child.text or "").strip()
            elif tag == "Address" and not address:
                address = (child.text or "").strip()
        urls = xaddrs.split()
        ip = None
        for url in urls:
            host = re.match(r"https?://\[?([0-9.]+)\]?(?::\d+)?/", url)
            if host:
                ip = host.group(1)
                break
        matches.append({
            "ip": ip,
            "xaddr": urls[0] if urls else None,
            "endpoint": address or None,
            "name": _scope(scopes, "name"),
            "hardware": _scope(scopes, "hardware"),
            "manufacturer": _scope(scopes, "mfr") or _scope(scopes, "manufacturer"),
        })
    return matches


def _scope(scopes, key):
    from urllib.parse import unquote

    for scope in scopes.split():
        match = re.search(rf"onvif://www\.onvif\.org/{key}/(.+)$", scope, re.I)
        if match:
            return unquote(match.group(1)).replace("_", " ").strip()
    return None
