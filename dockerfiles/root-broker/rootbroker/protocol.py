"""Frames between pa-root and the broker: one type byte, a 4-byte big-endian length, the payload.

The client sends REQUEST (JSON), STDIN frames, STDIN_END and, with a pty, RESIZE frames. The broker answers with
STDOUT, STDERR and one EXIT. The client never half-closes, so a closed socket means the caller died.
"""

import json
import struct
from typing import NamedTuple

SOCKET = '/run/panelalpha/root.sock'

REQUEST = b'R'
STDIN = b'I'
STDIN_END = b'E'
STDOUT = b'O'
STDERR = b'e'
EXIT = b'X'
RESIZE = b'W'

MAX_PAYLOAD = 1 << 20
CHUNK = 1 << 16
MAX_SIDE = 0xffff
OPTIONAL = {'env', 'pty'}
HEADER = struct.Struct('>cI')

EXIT_UNAVAILABLE = 69
EXIT_PROTOCOL = 76
EXIT_REFUSED = 77


class ProtocolError(Exception):
    pass


class Request(NamedTuple):
    action: str
    values: list
    host: bool
    env: dict
    size: tuple | None


def frame(kind, payload=b''):
    if len(payload) > MAX_PAYLOAD:
        raise ProtocolError('frame too large')
    return HEADER.pack(kind, len(payload)) + payload


def request(action, values, host, env=None, size=None):
    body = {
        'action': action, 'values': list(values), 'host': bool(host),
        **({'env': dict(env)} if env else {}),
        **({'pty': {'rows': size[0], 'cols': size[1]}} if size else {}),
    }
    return frame(REQUEST, json.dumps(body, ensure_ascii=True).encode('ascii'))


def parse_request(payload):
    try:
        body = json.loads(payload.decode('ascii'))
    except (UnicodeDecodeError, ValueError) as e:
        raise ProtocolError('the request is not JSON') from e
    if not isinstance(body, dict) or set(body) - OPTIONAL != {'action', 'values', 'host'}:
        raise ProtocolError('the request must hold exactly action, values and host, and maybe env and pty')
    action, values, host, env = body['action'], body['values'], body['host'], body.get('env', {})
    if not isinstance(action, str) or not isinstance(host, bool):
        raise ProtocolError('action must be text and host true or false')
    if not isinstance(values, list) or not all(isinstance(value, str) for value in values):
        raise ProtocolError('values must be a list of text')
    if not isinstance(env, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in env.items()):
        raise ProtocolError('env must map names to text')
    return Request(action, values, host, env, terminal_size(body.get('pty')))


def terminal_size(pty):
    if pty is None:
        return None
    if not isinstance(pty, dict) or set(pty) != {'rows', 'cols'} or not all(side(pty[key]) for key in ('rows', 'cols')):
        raise ProtocolError('pty must hold rows and cols, each a whole number of characters')
    return pty['rows'], pty['cols']


def side(value):
    return isinstance(value, int) and not isinstance(value, bool) and 0 <= value <= MAX_SIDE


def resize(rows, cols):
    return frame(RESIZE, f'{rows} {cols}'.encode('ascii'))


def parse_resize(payload):
    try:
        rows, cols = (int(part) for part in payload.decode('ascii').split(' '))
    except (UnicodeDecodeError, ValueError) as e:
        raise ProtocolError('a resize is two numbers') from e
    if not side(rows) or not side(cols):
        raise ProtocolError('a resize is two numbers')
    return rows, cols


def exit_code(returncode):
    """A process's return code as the client's exit code: 128 and the signal for a process a signal ended."""
    return 128 - returncode if returncode < 0 else returncode


def exit_frame(code):
    return frame(EXIT, str(code).encode('ascii'))


def parse_exit(payload):
    try:
        return int(payload.decode('ascii'))
    except (UnicodeDecodeError, ValueError) as e:
        raise ProtocolError('the exit code is not a number') from e


class Reader:
    """Splits what arrives on the socket into frames, however the bytes were chunked."""

    def __init__(self):
        self.buffer = bytearray()

    def feed(self, data):
        self.buffer += data
        frames = []
        while len(self.buffer) >= HEADER.size:
            kind, length = HEADER.unpack_from(self.buffer)
            if length > MAX_PAYLOAD:
                raise ProtocolError('frame too large')
            if len(self.buffer) < HEADER.size + length:
                break
            frames.append((kind, bytes(self.buffer[HEADER.size:HEADER.size + length])))
            del self.buffer[:HEADER.size + length]
        return frames
