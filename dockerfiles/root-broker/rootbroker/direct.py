"""pa-broker: one action run as root in the broker's own container, with no socket. A request process of its own
checks and runs it as the broker runs a socket request, and audits it in the broker's log.

    pa-broker [--host] [--pty] [--env NAME]... AREA.ACTION -- VALUE...
"""

import errno
import os
import socket
import sys
import traceback

from . import client, peer, protocol
from .refused import Refused
from .server import Broker

USAGE = 'usage: pa-broker [--host] [--pty] [--env NAME]... AREA.ACTION -- VALUE...'
ROOT = 0


class Direct(Broker):
    """The broker for a caller in its own container, which only the host's root reaches, through `docker exec`."""

    program = 'pa-broker'
    via = 'pa-broker'

    def __init__(self, allowed_uids=(ROOT,), **rest):
        super().__init__(allowed_uids, **rest)

    def admit(self, conn, pid, uid):
        if uid not in self.allowed_uids:
            raise Refused(f'uid {uid} may not run pa-broker')


def container_log(first=peer.own_pid):
    """The stdout and stderr of the first process of the broker's container, which `docker logs` reads, for writing."""
    pid = first()
    if pid is None:
        raise OSError(errno.ESRCH, "Docker does not say which process runs the broker's container")
    out = os.open(f'/proc/{pid}/fd/1', os.O_WRONLY | os.O_CLOEXEC)
    try:
        return out, os.open(f'/proc/{pid}/fd/2', os.O_WRONLY | os.O_CLOEXEC)
    except OSError:
        os.close(out)
        raise


def request_process(conn, log, broker):
    """Serves the request on `conn` with stdin empty and stdout and stderr the broker's log, as the broker's own request
    processes have them, then ends."""
    code = 1
    try:
        for fd, standard in ((os.open(os.devnull, os.O_RDONLY), 0), (log[0], 1), (log[1], 2)):
            os.dup2(fd, standard)
        os.umask(0o022)
        broker().serve(conn)
        code = 0
    except BaseException:
        traceback.print_exc()
    finally:
        os._exit(code)


def ask(conn, request):
    try:
        return client.ask(conn, *request)
    except (protocol.ProtocolError, OSError) as e:
        print(f'pa-broker: {e}', file=sys.stderr)
        return protocol.EXIT_PROTOCOL
    finally:
        conn.close()


def main(argv, broker=Direct, log=container_log):
    try:
        host, pty, passing, action, values = client.parse(argv)
    except client.Usage:
        print(USAGE, file=sys.stderr)
        return client.EXIT_USAGE
    try:
        written = log()
    except OSError as e:
        print(f"pa-broker: the broker's log cannot be written, so nothing runs: {e.strerror or e}", file=sys.stderr)
        return protocol.EXIT_UNAVAILABLE
    ours, theirs = socket.socketpair()
    if os.fork() == 0:
        ours.close()
        request_process(theirs, written, broker)
    theirs.close()
    for fd in written:
        os.close(fd)
    return ask(ours, (action, values, host, passing, pty))
