"""A hijacked Docker stream and the broker's client, relayed both ways until the stream ends.

`Frames` splits the stream's 8-byte-headed frames onto stdout and stderr as they arrive. The client's stdin goes to the
stream only when the command reads it, and the client hanging up ends the relay.
"""

import selectors
import socket
import struct

from . import protocol
from .channel import ClientGone
from .protocol import CHUNK

SECONDS = 3600
FRAME = struct.Struct('>BxxxI')
STDOUT, STDERR = 1, 2


class Frames:
    """Docker's 8-byte-headed stream frames, split however the bytes arrive: each `(kind, payload)` as it comes."""

    def __init__(self):
        self.buffer = bytearray()
        self.left = 0
        self.kind = STDOUT

    def feed(self, data):
        """The payloads `data` brings, a frame it cuts short as far as it goes."""
        self.buffer += data
        while self.buffer:
            if self.left == 0:
                if len(self.buffer) < FRAME.size:
                    return
                self.kind, self.left = FRAME.unpack_from(self.buffer)
                del self.buffer[:FRAME.size]
                continue
            taken = bytes(self.buffer[:self.left])
            del self.buffer[:len(taken)]
            self.left -= len(taken)
            yield self.kind, taken

    def pulled(self, read):
        """The payloads `read(size)` gives to its end, asked for a header, then for at most a CHUNK of a payload."""
        while data := read(min(self.left, CHUNK) if self.left else FRAME.size - len(self.buffer)):
            yield from self.feed(data)


def written(output, frames):
    """Each payload on stderr when its frame is the stream's stderr, else on stdout."""
    for kind, payload in frames:
        (output.stderr if kind == STDERR else output.stdout)(payload)


class Stdin:
    """The client's stdin on its way to the stream, closed for writing at the client's end of input."""

    def __init__(self, sock):
        self.sock = sock
        self.pending = bytearray()
        self.ended = False
        self.closed = False

    def take(self, frames):
        for kind, payload in frames:
            if kind == protocol.STDIN and not self.ended:
                self.pending += payload
            elif kind == protocol.STDIN_END:
                self.ended = True
            elif kind != protocol.STDIN:
                return False
        self.close_when_sent()
        return True

    def write(self):
        try:
            del self.pending[:self.sock.send(self.pending[:CHUNK])]
        except BrokenPipeError:
            self.pending.clear()
            self.ended = True
        self.close_when_sent()

    def close_when_sent(self):
        if self.ended and not self.pending and not self.closed:
            self.closed = True
            try:
                self.sock.shutdown(socket.SHUT_WR)
            except OSError:
                pass


def relay(stream, output, stdin=False, seconds=SECONDS):
    """True once the stream has ended, False once the client has hung up; TimeoutError after `seconds` of silence.

    With `stdin` the client's input is written to the stream, else it is dropped."""
    frames = Frames()
    written(output, frames.feed(bytes(stream.pending)))
    stream.pending.clear()
    sending = Stdin(stream.sock) if stdin else None
    if sending is not None and not sending.take(output.take_early()):
        return False
    with selectors.DefaultSelector() as selector:
        selector.register(stream.sock, events(sending), 'daemon')
        if output.channel is not None:
            selector.register(output.channel, selectors.EVENT_READ, 'client')
        while True:
            ready = selector.select(seconds)
            if not ready:
                raise TimeoutError()
            for key, mask in ready:
                if key.data == 'client':
                    try:
                        taken = output.channel.receive()
                    except ClientGone:
                        return False
                    if sending is not None and not sending.take(taken):
                        return False
                elif mask & selectors.EVENT_READ:
                    data = stream.sock.recv(CHUNK)
                    if not data:
                        return True
                    written(output, frames.feed(data))
                elif mask & selectors.EVENT_WRITE:
                    sending.write()
            selector.modify(stream.sock, events(sending), 'daemon')


def events(sending):
    writing = sending is not None and bool(sending.pending)
    return selectors.EVENT_READ | (selectors.EVENT_WRITE if writing else 0)
