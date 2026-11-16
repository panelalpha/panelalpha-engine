"""pa-root: runs one action through the root broker and exits with its exit code.

    pa-root [--host] [--pty] [--env NAME]... AREA.ACTION -- VALUE...
"""

import errno
import os
import selectors
import signal
import socket
import sys
import termios
import time
import tty

from . import protocol, terminal
from .protocol import CHUNK

CONNECT_SECONDS = 10
EXIT_USAGE = 64
WAITED_FOR = (errno.ENOENT, errno.ECONNREFUSED)
DEFAULT_SIZE = (24, 80)


class Usage(Exception):
    pass


def parse(argv):
    host = bool(argv) and argv[0] == '--host'
    rest = argv[1:] if host else argv
    pty = bool(rest) and rest[0] == '--pty'
    rest = rest[1:] if pty else rest
    passing = []
    while len(rest) >= 2 and rest[0] == '--env':
        passing.append(rest[1])
        rest = rest[2:]
    if len(rest) < 2 or rest[1] != '--' or rest[0].startswith('-'):
        raise Usage('usage: pa-root [--host] [--pty] [--env NAME]... AREA.ACTION -- VALUE...')
    return host, pty, passing, rest[0], rest[2:]


def connect(path, seconds=CONNECT_SECONDS):
    deadline = time.monotonic() + seconds
    while True:
        conn = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        try:
            conn.connect(path)
            return conn
        except OSError as e:
            conn.close()
            if e.errno not in WAITED_FOR or time.monotonic() >= deadline:
                raise
        time.sleep(0.1)


def write_all(fd, data):
    try:
        while data:
            data = data[os.write(fd, data):]
    except BrokenPipeError:
        pass


class Session:
    def __init__(self, conn, stdin=0, resized=None):
        self.conn = conn
        self.stdin = stdin
        self.resized = resized
        self.reader = protocol.Reader()
        self.selector = selectors.PollSelector()

    def run(self):
        self.selector.register(self.conn, selectors.EVENT_READ, self.from_broker)
        if self.resized is not None:
            self.selector.register(self.resized, selectors.EVENT_READ, self.on_resize)
        if not self.watch_stdin():
            self.conn.sendall(protocol.frame(protocol.STDIN_END))
        while True:
            for key, _ in self.selector.select():
                code = key.data()
                if code is not None:
                    return code

    def watch_stdin(self):
        try:
            self.selector.register(self.stdin, selectors.EVENT_READ, self.from_stdin)
            return True
        except (OSError, ValueError):
            return False

    def from_stdin(self):
        try:
            data = os.read(self.stdin, CHUNK)
        except OSError:
            data = b''
        if data:
            self.conn.sendall(protocol.frame(protocol.STDIN, data))
            return None
        self.selector.unregister(self.stdin)
        self.conn.sendall(protocol.frame(protocol.STDIN_END))
        return None

    def on_resize(self):
        try:
            os.read(self.resized, CHUNK)
        except BlockingIOError:
            pass
        self.conn.sendall(protocol.resize(*terminal.size_of(self.stdin)))
        return None

    def from_broker(self):
        data = self.conn.recv(CHUNK)
        if not data:
            raise protocol.ProtocolError('the root broker closed the connection')
        for kind, payload in self.reader.feed(data):
            if kind == protocol.STDOUT:
                write_all(1, payload)
            elif kind == protocol.STDERR:
                write_all(2, payload)
            elif kind == protocol.EXIT:
                return protocol.parse_exit(payload)
        return None


class Terminal:
    """pa-root's terminal in raw mode for the run, resized on SIGWINCH."""

    def __init__(self, fd=0):
        self.fd = fd
        self.saved = None
        self.wakeup = None

    def __enter__(self):
        if not os.isatty(self.fd):
            return self
        self.saved = termios.tcgetattr(self.fd)
        tty.setraw(self.fd)
        self.wakeup = os.pipe()
        for end in self.wakeup:
            os.set_blocking(end, False)
        signal.set_wakeup_fd(self.wakeup[1], warn_on_full_buffer=False)
        signal.signal(signal.SIGWINCH, lambda *_: None)
        return self

    def __exit__(self, *_):
        if self.saved is None:
            return
        signal.signal(signal.SIGWINCH, signal.SIG_DFL)
        signal.set_wakeup_fd(-1)
        for end in self.wakeup:
            os.close(end)
        termios.tcsetattr(self.fd, termios.TCSAFLUSH, self.saved)

    def size(self):
        return terminal.size_of(self.fd) if self.saved is not None else DEFAULT_SIZE

    def resized(self):
        return self.wakeup[0] if self.wakeup else None


def ask(conn, action, values, host, passing, pty):
    env = {name: os.environ[name] for name in passing if name in os.environ}
    if not pty:
        conn.sendall(protocol.request(action, values, host, env))
        return Session(conn).run()
    with Terminal() as own:
        conn.sendall(protocol.request(action, values, host, env, own.size()))
        return Session(conn, resized=own.resized()).run()


def main(argv):
    try:
        host, pty, passing, action, values = parse(argv)
    except Usage as e:
        print(e, file=sys.stderr)
        return EXIT_USAGE
    path = os.environ.get('PA_ROOT_SOCKET', protocol.SOCKET)
    try:
        conn = connect(path)
    except OSError as e:
        print(f'pa-root: the root broker is not reachable at {path}: {e.strerror}', file=sys.stderr)
        return protocol.EXIT_UNAVAILABLE
    try:
        return ask(conn, action, values, host, passing, pty)
    except (protocol.ProtocolError, OSError) as e:
        print(f'pa-root: {e}', file=sys.stderr)
        return protocol.EXIT_PROTOCOL
