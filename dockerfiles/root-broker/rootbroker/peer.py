"""Whether a peer runs in the core container, in the mount namespace of core's first process.

A host process with the same uid can reach the socket through /proc/<pid>/root, so the uid alone is not enough. Core
is the running `core` service of the broker's own compose project on the host's daemon.
"""

import http.client
import json
import os
import re
import signal
import socket
import time
import urllib.parse

from . import dockerapi

SO_PEERPIDFD = getattr(socket, 'SO_PEERPIDFD', 77)
PIDFD = 4
RETRY_SECONDS = 1.0
MOUNTINFO = '/proc/self/mountinfo'
CORE = 'core'
OWN_CONTAINER = re.compile(r'/containers/([0-9a-f]{64})/(?:hostname|hosts|resolv\.conf) ')
UNANSWERED = (OSError, ValueError, KeyError, TypeError, http.client.HTTPException)


def mount_namespace(pid):
    return os.readlink(f'/proc/{pid}/ns/mnt')


def peer_pidfd(conn):
    try:
        return int.from_bytes(conn.getsockopt(socket.SOL_SOCKET, SO_PEERPIDFD, PIDFD), 'little', signed=True)
    except OSError:
        return None


def still_alive(pidfd):
    """Whether the process the pidfd holds has not ended."""
    try:
        signal.pidfd_send_signal(pidfd, 0)
    except ProcessLookupError:
        return False
    return True


def runs_in(conn, pid, namespace):
    if namespace is None:
        return False
    pidfd = peer_pidfd(conn)
    try:
        theirs = mount_namespace(pid)
        if pidfd is not None and not still_alive(pidfd):
            return False
    except (FileNotFoundError, ProcessLookupError, PermissionError):
        return False
    finally:
        if pidfd is not None:
            os.close(pidfd)
    return theirs == namespace


def get(path, socket_path=dockerapi.SOCKET, seconds=dockerapi.SECONDS):
    status, body = dockerapi.request('GET', path, socket_path, seconds)
    return json.loads(body) if status == 200 else None


def own_container(mountinfo=MOUNTINFO):
    with open(mountinfo) as f:
        found = OWN_CONTAINER.search(f.read())
    return found.group(1) if found else None


def running_core(project, socket_path):
    filters = json.dumps(dockerapi.compose_filters(project, CORE))
    found = get('/containers/json?filters=' + urllib.parse.quote(filters), socket_path)
    return found[0]['Id'] if isinstance(found, list) and len(found) == 1 else None


def first_pid(container, socket_path=dockerapi.SOCKET):
    """The host pid of the container's first process, or None while it does not run."""
    pid = ((get(f'/containers/{container}/json', socket_path) or {}).get('State') or {}).get('Pid')
    return pid if isinstance(pid, int) and pid > 0 else None


def core_pid(socket_path=dockerapi.SOCKET, mountinfo=MOUNTINFO):
    try:
        own = own_container(mountinfo)
        project = dockerapi.labels(get(f'/containers/{own}/json', socket_path)).get(dockerapi.PROJECT) if own else None
        core = running_core(project, socket_path) if project else None
        return first_pid(core, socket_path) if core else None
    except UNANSWERED:
        return None


def own_pid(socket_path=dockerapi.SOCKET, mountinfo=MOUNTINFO):
    """The host pid of the first process of the container this process runs in, or None when Docker does not say."""
    try:
        own = own_container(mountinfo)
        return first_pid(own, socket_path) if own else None
    except UNANSWERED:
        return None


class Core:
    """Core's mount namespace, held open so its inode cannot be reused; found again once core's process ends."""

    def __init__(self, locate=core_pid, clock=time.monotonic):
        self.locate = locate
        self.clock = clock
        self.pid = None
        self.pidfd = None
        self.held_namespace = None
        self.namespace = None
        self.missed_at = None

    def current(self):
        if self.held():
            return self.namespace
        self.forget()
        if self.missed_at is None or self.clock() - self.missed_at >= RETRY_SECONDS:
            self.hold(self.locate())
            self.missed_at = None if self.namespace is not None else self.clock()
        return self.namespace

    def held(self):
        if self.pidfd is None or not still_alive(self.pidfd):
            return False
        try:
            return mount_namespace(self.pid) == self.namespace
        except OSError:
            return False

    def hold(self, pid):
        if pid is None:
            return
        try:
            pidfd = os.pidfd_open(pid)
        except OSError:
            return
        try:
            held = os.open(f'/proc/{pid}/ns/mnt', os.O_RDONLY | os.O_CLOEXEC)
        except OSError:
            os.close(pidfd)
            return
        namespace = os.readlink(f'/proc/self/fd/{held}')
        if not still_alive(pidfd):
            os.close(held)
            os.close(pidfd)
            return
        self.pid, self.pidfd, self.held_namespace, self.namespace = pid, pidfd, held, namespace

    def forget(self):
        for fd in (self.pidfd, self.held_namespace):
            if fd is not None:
                os.close(fd)
        self.pid = self.pidfd = self.held_namespace = self.namespace = None
