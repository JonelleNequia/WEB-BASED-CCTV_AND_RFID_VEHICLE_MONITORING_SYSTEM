"""
UHF reader protocols: build probe/inventory commands and decode reader output.

Generic long-range UHF readers speak one of a few protocols. Each one is
recognised by its own framing and checksum, so a frame is only accepted when
the checksum is right (random bytes do not pass):

- r2000:  Impinj R2000 style. A0 Len Addr Cmd Data.. Check (two's complement sum)
- chafon: Chafon / "UHFReader18/288" style. Len Addr Cmd [Status] Data.. CRC16 (LSB first)
- bb7e:   Magic RF M100 / QM100 style. BB Type Cmd PL(2) Payload.. Sum 7E
- text:   plain ASCII lines with the EPC in hex (readers in "active" output mode)

Which protocol a reader uses is learned from the first valid frame and
remembered in the device record (never assumed from a fixed port).
"""

import re
import time

PROTOCOLS = ("r2000", "chafon", "bb7e", "text")

# Command bytes that carry tag data in each protocol.
R2000_TAG_COMMANDS = {0x80, 0x89, 0x8A, 0x8B, 0x90, 0xB0}
CHAFON_TAG_COMMANDS = {0x01, 0xEE}
BB7E_TAG_COMMANDS = {0x22, 0x27}

MIN_EPC_BYTES = 4
MAX_EPC_BYTES = 62
MAX_BUFFER_BYTES = 8192
HEX_TOKEN = re.compile(r"(?:[0-9A-Fa-f]{2}[ :-]?){4,62}")


# ---------------------------------------------------------------------------
# Checksums
# ---------------------------------------------------------------------------

def r2000_checksum(data):
    return (~sum(data) + 1) & 0xFF


def chafon_crc(data):
    crc = 0xFFFF
    for byte in data:
        crc ^= byte
        for _ in range(8):
            crc = (crc >> 1) ^ 0x8408 if crc & 1 else crc >> 1
    return crc & 0xFFFF


def bb7e_checksum(data):
    return sum(data) & 0xFF


# ---------------------------------------------------------------------------
# Command builders (used by discovery probes and by the reader link polling)
# ---------------------------------------------------------------------------

def build_command(protocol, command, data=b"", address=0xFF):
    command = int(command, 16) if isinstance(command, str) else int(command)
    data = bytes.fromhex(data) if isinstance(data, str) else bytes(data or b"")

    if protocol == "r2000":
        body = bytes([0xA0, len(data) + 3, address & 0xFF, command]) + data
        return body + bytes([r2000_checksum(body)])

    if protocol == "chafon":
        body = bytes([len(data) + 4, address & 0xFF, command]) + data
        crc = chafon_crc(body)
        return body + bytes([crc & 0xFF, crc >> 8])

    if protocol == "bb7e":
        body = bytes([0x00, command, (len(data) >> 8) & 0xFF, len(data) & 0xFF]) + data
        return bytes([0xBB]) + body + bytes([bb7e_checksum(body), 0x7E])

    raise ValueError(f"Unknown protocol {protocol}")


def probe_commands(profiles, purpose=None, protocol=None):
    """
    Commands listed in discovery_profiles.json, built into bytes.
    """
    commands = []
    for entry in profiles.get("uhf_reader", {}).get("probe_commands", []):
        if purpose and entry.get("purpose") != purpose:
            continue
        if protocol and entry.get("protocol") != protocol:
            continue
        try:
            payload = build_command(
                entry["protocol"],
                entry["command"],
                entry.get("data", ""),
                int(entry.get("address", 255)),
            )
        except (KeyError, ValueError):
            continue
        commands.append((entry["protocol"], entry.get("purpose", ""), payload))
    return commands


# ---------------------------------------------------------------------------
# Frames
# ---------------------------------------------------------------------------

class Frame:
    __slots__ = ("protocol", "kind", "command", "epc", "rssi", "antenna", "raw")

    def __init__(self, protocol, kind, command=None, epc=None, rssi=None, antenna=None, raw=b""):
        self.protocol = protocol
        self.kind = kind  # tag | reply | text
        self.command = command
        self.epc = epc
        self.rssi = rssi
        self.antenna = antenna
        self.raw = bytes(raw)

    def as_dict(self):
        return {
            "protocol": self.protocol,
            "kind": self.kind,
            "command": self.command,
            "epc": self.epc,
            "rssi": self.rssi,
            "antenna": self.antenna,
            "raw_hex": self.raw.hex(" ").upper(),
        }


def _valid_epc(epc_bytes):
    return MIN_EPC_BYTES <= len(epc_bytes) <= MAX_EPC_BYTES


def _r2000(buffer, start):
    if buffer[start] != 0xA0:
        return None
    if len(buffer) - start < 2:
        return "more"
    length = buffer[start + 1]
    if length < 3:
        return None
    end = start + 2 + length
    if end > len(buffer):
        return "more"
    frame = buffer[start:end]
    if r2000_checksum(frame[:-1]) != frame[-1]:
        return None

    command = frame[3]
    data = frame[4:-1]
    if command in R2000_TAG_COMMANDS and len(data) >= 1 + 2 + MIN_EPC_BYTES + 1:
        pc = (data[1] << 8) | data[2]
        epc_len = (pc >> 11) * 2
        epc = data[3:-1]
        if epc_len and epc_len <= len(epc):
            epc = epc[:epc_len]
        if _valid_epc(epc):
            return end, Frame("r2000", "tag", command, epc.hex().upper(), rssi=data[-1], antenna=(data[0] & 0x03) + 1, raw=frame)
    return end, Frame("r2000", "reply", command, raw=frame)


def _chafon(buffer, start):
    if len(buffer) - start < 1:
        return "more"
    length = buffer[start]
    if length < 4 or length > 250:
        return None
    end = start + 1 + length
    if end > len(buffer):
        return "more"
    frame = buffer[start:end]
    crc = chafon_crc(frame[:-2])
    if frame[-2] != (crc & 0xFF) or frame[-1] != (crc >> 8):
        return None

    command = frame[2]
    body = frame[3:-2]

    # Active (auto-read) output: Len Adr EE Status EPC CRC
    if command == 0xEE and len(body) >= 1 + MIN_EPC_BYTES:
        epc = body[1:]
        if _valid_epc(epc):
            return end, Frame("chafon", "tag", command, epc.hex().upper(), raw=frame)

    # Inventory reply: Len Adr 01 Status Num [EPCLen EPC]...
    if command == 0x01 and len(body) >= 2:
        tags = []
        index = 2
        count = body[1]
        for _ in range(count):
            if index >= len(body):
                break
            epc_len = body[index]
            epc = body[index + 1:index + 1 + epc_len]
            index += 1 + epc_len
            if _valid_epc(epc) and len(epc) == epc_len:
                tags.append(epc.hex().upper())
        if tags:
            frames = [Frame("chafon", "tag", command, tag, raw=frame) for tag in tags]
            return end, frames

    return end, Frame("chafon", "reply", command, raw=frame)


def _bb7e(buffer, start):
    if buffer[start] != 0xBB:
        return None
    if len(buffer) - start < 5:
        return "more"
    payload_len = (buffer[start + 3] << 8) | buffer[start + 4]
    end = start + 7 + payload_len
    if end > len(buffer):
        return "more"
    frame = buffer[start:end]
    if frame[-1] != 0x7E or bb7e_checksum(frame[1:-2]) != frame[-2]:
        return None
    frame_type = frame[1]
    command = frame[2]
    payload = frame[5:-2]
    if frame_type in (0x01, 0x02) and command in BB7E_TAG_COMMANDS and len(payload) >= 1 + 2 + MIN_EPC_BYTES + 2:
        pc = (payload[1] << 8) | payload[2]
        epc_len = (pc >> 11) * 2
        epc = payload[3:-2]
        if epc_len and epc_len <= len(epc):
            epc = epc[:epc_len]
        if _valid_epc(epc):
            rssi = payload[0] - 256 if payload[0] > 127 else payload[0]
            return end, Frame("bb7e", "tag", command, epc.hex().upper(), rssi=rssi, raw=frame)
    return end, Frame("bb7e", "reply", command, raw=frame)


def _printable(chunk):
    return all(32 <= byte < 127 or byte in (9, 10, 13) for byte in chunk)


def epcs_from_text(line):
    """
    Pull EPC-looking hex tokens out of one text line, e.g.
    "E2 00 00 17 22 11 01 44" or "EPC:E2000017221101441890ABCD,RSSI:-55".
    """
    found = []
    for match in HEX_TOKEN.finditer(line):
        token = re.sub(r"[ :-]", "", match.group(0))
        if len(token) % 2:
            token = token[:-1]
        # Skip plain decimal numbers (counters, RSSI) unless they are long.
        if not token or (token.isdigit() and len(token) < 16):
            continue
        if MIN_EPC_BYTES * 2 <= len(token) <= MAX_EPC_BYTES * 2:
            found.append(token.upper())
    return found


BINARY_PARSERS = (("r2000", _r2000), ("bb7e", _bb7e), ("chafon", _chafon))


class StreamDecoder:
    """
    Feed raw bytes from a socket; get complete frames back.

    When `protocol` is known only that parser is used. Otherwise every parser
    is tried and the first valid frame fixes the protocol.
    """

    def __init__(self, protocol=None):
        self.protocol = protocol if protocol in PROTOCOLS else None
        self.buffer = bytearray()
        self.last_data_at = 0.0

    def feed(self, data):
        self.buffer.extend(data)
        self.last_data_at = time.monotonic()
        if len(self.buffer) > MAX_BUFFER_BYTES:
            del self.buffer[:-MAX_BUFFER_BYTES]
        return self._drain(final=False)

    def flush(self):
        """Decode whatever is left (e.g. a text line without a newline)."""
        return self._drain(final=True)

    def _parsers(self):
        if self.protocol in (None, "text"):
            return BINARY_PARSERS if self.protocol is None else ()
        return tuple(item for item in BINARY_PARSERS if item[0] == self.protocol)

    def _drain(self, final):
        frames = []
        index = 0
        buffer = self.buffer

        while index < len(buffer):
            # Text is tried first only for printable bytes, so a binary length
            # byte that happens to be CR/LF (0x0D/0x0A) still reaches the
            # binary parsers.
            if 32 <= buffer[index] < 127:
                text_frames, text_end = self._text_at(buffer, index, final)
                if text_end:
                    frames.extend(text_frames)
                    index = text_end
                    continue

            waiting = False
            matched = False
            for name, parser in self._parsers():
                result = parser(buffer, index)
                if result is None:
                    continue
                if result == "more":
                    waiting = True
                    continue
                end, parsed = result
                parsed = parsed if isinstance(parsed, list) else [parsed]
                frames.extend(parsed)
                if self.protocol is None:
                    self.protocol = name
                index = end
                matched = True
                break

            if matched:
                continue
            if buffer[index] in (10, 13) and not waiting:
                index += 1  # line break between text lines
                continue
            if waiting and not final:
                # A headerless (chafon) length byte may just be noise: if a
                # complete headed frame follows, skip ahead to it.
                ahead = self._complete_headed_frame_after(buffer, index)
                if ahead is None:
                    break
                index = ahead
                continue
            index += 1

        del buffer[:index]
        return frames

    def _complete_headed_frame_after(self, buffer, index):
        if self.protocol not in (None, "r2000", "bb7e"):
            return None
        for position in range(index + 1, len(buffer)):
            parser = {0xA0: _r2000, 0xBB: _bb7e}.get(buffer[position])
            if parser and self.protocol in (None, "r2000" if parser is _r2000 else "bb7e"):
                if isinstance(parser(buffer, position), tuple):
                    return position
        return None

    def _text_at(self, buffer, index, final):
        if self.protocol not in (None, "text"):
            return [], 0
        newline = -1
        for position in range(index, len(buffer)):
            if buffer[position] in (10, 13):
                newline = position
                break
        end = newline if newline >= 0 else (len(buffer) if final else -1)
        if end <= index:
            return [], 0
        chunk = bytes(buffer[index:end])
        if not _printable(chunk):
            return [], 0
        epcs = epcs_from_text(chunk.decode("ascii", "ignore"))
        if not epcs:
            # A printable line without an EPC (banner, prompt). Skip it.
            return [], end + (1 if newline >= 0 else 0)
        if self.protocol is None:
            self.protocol = "text"
        next_index = end + (1 if newline >= 0 else 0)
        return [Frame("text", "tag", None, epc, raw=chunk) for epc in epcs], next_index


def decode_once(data, protocol=None):
    decoder = StreamDecoder(protocol)
    frames = decoder.feed(data)
    frames.extend(decoder.flush())
    return frames, decoder.protocol
