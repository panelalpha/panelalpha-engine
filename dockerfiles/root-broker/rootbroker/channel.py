"""The broker's end of a client's connection: the request and its stdin frames in, the output and the exit code out."""

import socket

from . import protocol
from .protocol import CHUNK, ProtocolError
from .refused import Refused


class ClientGone(Exception):
    """The client hung up, or sent a frame its request does not take."""


class Channel:
    def __init__(self, conn):
        self.conn = conn
        self.reader = protocol.Reader()
        self.early = []

    def fileno(self):
        return self.conn.fileno()

    def request(self, seconds):
        """The request frame's payload; the frames that came with it are kept for `take_early`."""
        self.conn.settimeout(seconds)
        try:
            frames = []
            while not frames:
                frames = self.receive()
        except ClientGone:
            raise ProtocolError('the client closed before its request') from None
        except socket.timeout as e:
            raise ProtocolError('no request in time') from e
        finally:
            self.conn.settimeout(None)
        kind, payload = frames[0]
        if kind != protocol.REQUEST:
            raise ProtocolError('the first frame must be the request')
        self.early = frames[1:]
        return payload

    def receive(self):
        """The frames one read completes; ClientGone once the client has hung up."""
        data = self.conn.recv(CHUNK)
        if not data:
            raise ClientGone()
        return self.reader.feed(data)

    def hung_up(self):
        """Reads what the client sent and drops it unread: True once it has hung up."""
        return not self.conn.recv(CHUNK)

    def take_early(self):
        """The frames that came with the request, once."""
        early, self.early = self.early, []
        return early

    def read_stdin(self, limit):
        """The client's stdin to its end, refusing more than `limit` bytes."""
        taken = bytearray()
        frames = self.take_early()
        while True:
            ended = False
            for kind, payload in frames:
                if kind == protocol.STDIN:
                    taken += payload
                    if len(taken) > limit:
                        raise Refused(f'the client sent more than {limit} bytes on stdin')
                elif kind == protocol.STDIN_END:
                    ended = True
                else:
                    raise ProtocolError('the request was cut off')
            if ended:
                return bytes(taken)
            try:
                frames = self.receive()
            except ClientGone:
                return bytes(taken)

    def stdout(self, data):
        self.send(protocol.STDOUT, data)

    def stderr(self, data):
        self.send(protocol.STDERR, data)

    def exit(self, code):
        self.write(protocol.exit_frame(code))

    def send(self, kind, data):
        """`data` as frames of `kind`, at most CHUNK bytes each."""
        for start in range(0, len(data), CHUNK):
            self.write(protocol.frame(kind, data[start:start + CHUNK]))

    def write(self, frames):
        try:
            self.conn.sendall(frames)
        except OSError as e:
            raise ClientGone() from e

    def answer(self, message, code):
        """`message` on stderr and the exit code in one write; an OSError is the caller's."""
        self.conn.sendall(protocol.frame(protocol.STDERR, message.encode()) + protocol.exit_frame(code))

    def close(self):
        self.conn.close()
