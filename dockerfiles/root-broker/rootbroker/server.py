"""The broker's socket, served by one forked process per request."""

import dataclasses
import grp
import os
import pwd
import socket
import socketserver
import stat
import struct
import time

from . import hostns, peer, protocol, runner
from .actions import Policy, registry
from .actions.rootscripts import PUSH_LOCK
from .audit import Audit, Audited
from .channel import Channel
from .refused import Refused

CACHE_REGISTRY_LOCK = os.path.basename(PUSH_LOCK)
GROUP = 'www-data'
REQUEST_SECONDS = 10
PEER = struct.Struct('3i')


def peer_of(conn):
    return PEER.unpack(conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, PEER.size))


def environment(action, env):
    unexpected = sorted(set(env) - set(action.passes))
    if unexpected:
        raise Refused(f"this action takes no {', '.join(unexpected)} from the caller's environment")
    return env


def fail(conn, fields, code, message, **why):
    """Audits why the request failed, then tells the client if it still listens."""
    fields.update(why, exit=code)
    try:
        Channel(conn).answer(message, code)
    except OSError:
        pass


class Broker:
    program = 'pa-root'
    via = None

    def __init__(self, allowed_uids, policy=None, actions=None, audit=None, enter_host=hostns.enter, core=None):
        self.allowed_uids = frozenset(allowed_uids)
        self.core = core or peer.Core()
        self.core_namespace = None
        self.policy = policy or Policy()
        self.actions = registry() if actions is None else actions
        self.audit = audit or Audit()
        self.enter_host = enter_host

    def prepare(self):
        self.core_namespace = self.core.current()

    def serve(self, conn):
        started = time.monotonic()
        pid, uid, _ = peer_of(conn)
        audited = Audited(self.audit, pid, uid, started, self.via)
        try:
            audited.fields['exit'] = self.handle(conn, pid, uid, audited.fields, audited.answered)
        except Refused as e:
            fail(conn, audited.fields, protocol.EXIT_REFUSED, f'{self.program}: refused: {e}\n', refused=str(e))
        except protocol.ProtocolError as e:
            fail(conn, audited.fields, protocol.EXIT_PROTOCOL, f'{self.program}: {e}\n', refused=str(e))
        except OSError as e:
            fail(conn, audited.fields, 1, f'{self.program}: {e.strerror or e}\n', error=str(e))
        finally:
            audited.finish()

    def handle(self, conn, pid, uid, entry, answered=None):
        self.admit(conn, pid, uid)
        channel = Channel(conn)
        request = protocol.parse_request(channel.request(REQUEST_SECONDS))
        entry.update(action=request.action, host=request.host, values=request.values, env=sorted(request.env),
                     **({'pty': True} if request.size else {}))
        action = self.actions.get(request.action)
        if action is None:
            raise Refused(f'there is no action {request.action!r}')
        if request.size is not None and not action.terminal:
            raise Refused('this action runs in no terminal')
        if request.size is not None and request.host:
            raise Refused('no action runs in a terminal in the host\'s namespaces')
        entry['values'] = action.audited(request.values)
        passed = environment(action, request.env)
        checked = action.check(request.values)
        if request.host:
            self.enter_host(action.namespaces)
        plan = action.plan(checked, self.policy, request.host)
        return runner.run(channel, dataclasses.replace(plan, passed=passed, terminal=request.size), answered)

    def admit(self, conn, pid, uid):
        """Refuses a peer that is not one of the allowed uids in the core container."""
        if uid not in self.allowed_uids:
            raise Refused(f'uid {uid} may not use the root broker')
        if self.core_namespace is None:
            raise Refused('the core container is not running, or Docker did not say which it is')
        if not peer.runs_in(conn, pid, self.core_namespace):
            raise Refused(f'pid {pid} does not run in the core container')


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        self.server.broker.serve(self.request)


class Server(socketserver.ForkingMixIn, socketserver.UnixStreamServer):
    max_children = 256

    def __init__(self, path, broker):
        self.broker = broker
        super().__init__(path, Handler)

    def process_request(self, request, client_address):
        self.broker.prepare()
        super().process_request(request, client_address)


def socket_directory(path, gid):
    directory = os.path.dirname(path)
    os.makedirs(directory, mode=0o750, exist_ok=True)
    if not stat.S_ISDIR(os.lstat(directory).st_mode):
        raise SystemExit(f'{directory} is not a directory')
    os.chown(directory, 0, gid)
    os.chmod(directory, 0o750)


def shared_lock(path):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o644)
    try:
        os.fchown(fd, 0, 0)
        os.fchmod(fd, 0o644)
    finally:
        os.close(fd)


def remove_stale(path):
    try:
        if stat.S_ISSOCK(os.lstat(path).st_mode):
            os.unlink(path)
    except FileNotFoundError:
        pass


def listen(path, broker, gid):
    socket_directory(path, gid)
    shared_lock(os.path.join(os.path.dirname(path), CACHE_REGISTRY_LOCK))
    remove_stale(path)
    previous = os.umask(0o177)
    try:
        server = Server(path, broker)
    finally:
        os.umask(previous)
    os.chown(path, 0, gid)
    os.chmod(path, 0o660)
    return server


def main():
    os.umask(0o022)
    gid = grp.getgrnam(GROUP).gr_gid
    broker = Broker({0, pwd.getpwnam(GROUP).pw_uid})
    server = listen(protocol.SOCKET, broker, gid)
    server.serve_forever()
