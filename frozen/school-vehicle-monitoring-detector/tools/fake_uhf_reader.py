"""
Pretend UHF reader for testing detection and the reader link without hardware.

    python tools/fake_uhf_reader.py --port 6000 --protocol r2000 --mode active
    python tools/fake_uhf_reader.py --port 6000 --protocol chafon --mode answer --transport udp

active: pushes a tag frame every --every seconds to each connected client.
answer: replies only to the protocol's info and inventory commands.
"""

import argparse
import select
import socket
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from devices import uhf  # noqa: E402


def tag_frame(protocol, epc_hex):
    epc = bytes.fromhex(epc_hex)
    pc = (((len(epc) + 1) // 2) << 11).to_bytes(2, "big")
    if protocol == "r2000":
        data = bytes([0x00]) + pc + epc + bytes([0x50])
        body = bytes([0xA0, len(data) + 3, 0x01, 0x89]) + data
        return body + bytes([uhf.r2000_checksum(body)])
    if protocol == "chafon":
        body = bytes([len(epc) + 5, 0x00, 0xEE, 0x00]) + epc
        crc = uhf.chafon_crc(body)
        return body + bytes([crc & 0xFF, crc >> 8])
    if protocol == "bb7e":
        payload = bytes([0xC9]) + pc + epc + b"\x00\x00"
        body = bytes([0x02, 0x22, 0x00, len(payload)]) + payload
        return bytes([0xBB]) + body + bytes([uhf.bb7e_checksum(body), 0x7E])
    return (epc_hex + "\r\n").encode()


def info_reply(protocol):
    if protocol == "r2000":
        body = bytes([0xA0, 0x05, 0x01, 0x72, 0x01, 0x09])
        return body + bytes([uhf.r2000_checksum(body)])
    if protocol == "chafon":
        body = bytes([0x0D, 0x00, 0x21, 0x00, 0x03, 0x01, 0x09, 0x02, 0x4E, 0x00, 0x1E, 0x0A])
        crc = uhf.chafon_crc(body)
        return body + bytes([crc & 0xFF, crc >> 8])
    if protocol == "bb7e":
        payload = b"M100 26dBm V1.0"
        body = bytes([0x01, 0x03, 0x00, len(payload)]) + payload
        return bytes([0xBB]) + body + bytes([uhf.bb7e_checksum(body), 0x7E])
    return None


def answer(protocol, data, epcs):
    replies = []
    for purpose in ("info", "inventory"):
        for _p, _u, command in uhf.probe_commands(PROFILES, purpose, protocol):
            if command in data:
                if purpose == "info":
                    replies.append(info_reply(protocol))
                else:
                    replies.extend(tag_frame(protocol, epc) for epc in epcs)
    return b"".join(reply for reply in replies if reply)


PROFILES = {}


def main():
    global PROFILES
    from devices.paths import load_profiles

    PROFILES = load_profiles()
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--protocol", choices=["r2000", "chafon", "bb7e", "text"], default="r2000")
    parser.add_argument("--mode", choices=["active", "answer"], default="active")
    parser.add_argument("--transport", choices=["tcp", "udp"], default="tcp")
    parser.add_argument("--epc", action="append", default=[])
    parser.add_argument("--every", type=float, default=3.0)
    args = parser.parse_args()
    epcs = args.epc or ["E2000017221101441890ABCD"]

    if args.transport == "udp":
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.bind(("", args.port))
        print(f"Fake {args.protocol} reader ({args.mode}) on udp/{args.port}", flush=True)
        while True:
            data, address = sock.recvfrom(4096)
            reply = answer(args.protocol, data, epcs)
            if reply:
                sock.sendto(reply, address)

    server = socket.socket()
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("", args.port))
    server.listen(4)
    print(f"Fake {args.protocol} reader ({args.mode}) on tcp/{args.port}", flush=True)
    clients = []
    last_push = 0.0
    while True:
        ready, _, _ = select.select([server] + clients, [], [], 0.2)
        for sock in ready:
            if sock is server:
                connection, address = server.accept()
                clients.append(connection)
                print(f"client {address}", flush=True)
                continue
            try:
                data = sock.recv(4096)
            except OSError:
                data = b""
            if not data:
                clients.remove(sock)
                sock.close()
                continue
            if args.mode == "answer":
                reply = answer(args.protocol, data, epcs)
                if reply:
                    sock.sendall(reply)
        if args.mode == "active" and time.monotonic() - last_push > args.every:
            last_push = time.monotonic()
            for client in list(clients):
                try:
                    client.sendall(b"".join(tag_frame(args.protocol, epc) for epc in epcs))
                except OSError:
                    clients.remove(client)


if __name__ == "__main__":
    main()
