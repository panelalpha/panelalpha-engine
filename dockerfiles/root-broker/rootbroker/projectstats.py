"""The lines `host.projectStats` writes: cgroup files on the host, and container names from the Engine API.

Each container the host's daemon names counts once it is confirmed as the account's own, and a DinD account's inner
containers come from its own daemon. Every number is a host cgroup file, never a daemon's data file.
"""

import http.client
import json
import os
import posixpath
import re
import time

from . import accountdaemon, dockerapi, paths, replies

CGROUP_ROOT = '/sys/fs/cgroup'
SOCKET = dockerapi.SOCKET
SYSTEMD_SCOPE = 'system.slice/docker-{}.scope'
CGROUPFS = 'docker'
INSIDE = 'docker'
REQUEST_SECONDS = 2
FILE_LIMIT = 1 << 16
NUMBER = re.compile(r'[0-9]+|max')
CONTROL = re.compile(r'[\x00-\x1f\x7f]')
DIND = 'dind'
CLASSIC = 'classic'
MEMORY_CURRENT = 'memory.current'
MEMORY_MAX = 'memory.max'
MEMORY_STAT = 'memory.stat'
CPU_STAT = 'cpu.stat'
INACTIVE_FILE = 'inactive_file'
USAGE_USEC = 'usage_usec'


class Budget:
    """The action's own seconds, out of which each daemon request gets a short bound of its own."""

    def __init__(self, seconds):
        self.ends = time.monotonic() + seconds

    def take(self):
        """The seconds a request may take now, or None once the action's own seconds are used up."""
        left = self.ends - time.monotonic()
        return min(REQUEST_SECONDS, left) if left > 0 else None


class Named:
    """What `open_daemon` reads of an account: the name its container carries in the accounts' project."""

    def __init__(self, name):
        self.name = name


class Reading:
    """`host.projectStats` for the request's `seconds`, usernames and the accounts' directory below the engine."""

    def __init__(self, seconds, usernames, accounts_directory):
        self.seconds = seconds
        self.usernames = frozenset(usernames)
        self.accounts_directory = accounts_directory

    def __call__(self, output):
        budget = Budget(self.seconds)
        for name, outer, kind in self.own(budget):
            directory = cgroup(outer)
            if directory is None:
                continue
            output.stdout(line('P', name, *readings(directory)))
            nested = inside(directory)
            names = self.named(name, budget) if nested and kind == DIND else {}
            for container in nested:
                container_name, service = names.get(container, ('', ''))
                output.stdout(line('C', name, container, container_name, service,
                                   *readings(posixpath.join(directory, INSIDE, container))))
        return 0

    def own(self, budget):
        """Each running container that is the account's own, as its name, its id and which kind it is."""
        own = []
        asked = set()
        for container in host_listing(budget.take()):
            for listed in container.get('Names') or []:
                name = listed.removeprefix('/')
                if name not in self.usernames or name in asked:
                    continue
                asked.add(name)
                kind = kind_of(name, container['Id'], self.accounts_directory, budget.take())
                if kind is not None:
                    own.append((name, container['Id'], kind))
        return own

    def named(self, name, budget):
        """The containers a DinD account's daemon lists, by id, with the name and compose service each has."""
        seconds = budget.take()
        if seconds is None:
            return {}
        try:
            daemon = accountdaemon.open_daemon(Named(name), self.accounts_directory, SOCKET, seconds=seconds)
        except (OSError, dockerapi.Failed, dockerapi.Unavailable):
            return {}
        try:
            return listed_names(daemon, budget.take())
        finally:
            daemon.close()


def host_listing(seconds):
    """The running containers the host's daemon lists, bounded and checked; none when it does not answer."""
    if seconds is None:
        return []
    try:
        status, body = dockerapi.request('GET', '/containers/json', SOCKET, seconds)
        return replies.containers(json.loads(body)) if status == 200 else []
    except (OSError, ValueError, http.client.HTTPException):
        return []


def kind_of(name, container_id, accounts_directory, seconds):
    """`DIND`, `CLASSIC` or None: whether the host's daemon describes this container as the account's own."""
    if seconds is None:
        return None
    try:
        status, body = dockerapi.request('GET', f'/containers/{container_id}/json', SOCKET, seconds)
        container = json.loads(body) if status == 200 else None
    except (OSError, ValueError, http.client.HTTPException):
        return None
    if not isinstance(container, dict):
        return None
    if accountdaemon.is_account_container(container, name, accounts_directory):
        return DIND
    return CLASSIC if accountdaemon.is_classic_container(container, name, accounts_directory) else None


def listed_names(daemon, seconds):
    """What a daemon's listing says of each container's name and compose service, by id; empty when it fails."""
    if seconds is None:
        return {}
    try:
        found = replies.containers(daemon.json('GET', '/containers/json?all=1', missing=(), seconds=seconds))
    except (OSError, ValueError, http.client.HTTPException, dockerapi.Failed, dockerapi.Unavailable):
        return {}
    names = {}
    for container in found:
        listed = container.get('Names') or []
        name = listed[0].removeprefix('/') if listed else ''
        names[container['Id']] = (name, (container.get('Labels') or {}).get(dockerapi.SERVICE) or '')
    return names


def cgroup(container_id):
    """A container's cgroup directory, in either place the host keeps it; None when it has none."""
    for directory in (posixpath.join(CGROUP_ROOT, SYSTEMD_SCOPE.format(container_id)),
                      posixpath.join(CGROUP_ROOT, CGROUPFS, container_id)):
        if os.path.isdir(directory):
            return directory
    return None


def inside(directory):
    """The ids of the containers running inside a DinD account, from the cgroups their daemon made."""
    nested = posixpath.join(directory, INSIDE)
    try:
        names = os.listdir(nested)
    except OSError:
        return []
    return [name for name in sorted(names) if os.path.isdir(posixpath.join(nested, name))]


def readings(directory):
    """A cgroup's memory current, memory max, inactive file page cache and CPU microseconds."""
    return [number(directory, MEMORY_CURRENT), number(directory, MEMORY_MAX),
            stat(directory, MEMORY_STAT, INACTIVE_FILE), stat(directory, CPU_STAT, USAGE_USEC)]


def number(directory, name):
    """The one number in a cgroup file, `max` where there is no limit, empty when the file is not there."""
    words = content(directory, name).split()
    text = words[0].decode('ascii', 'replace') if words else ''
    return text if NUMBER.fullmatch(text) else ''


def stat(directory, name, key):
    """The number after `key` in a cgroup stat file, empty when the file or the line is not there."""
    for line in content(directory, name).splitlines():
        words = line.split()
        if len(words) == 2 and words[0] == key.encode('ascii'):
            text = words[1].decode('ascii', 'replace')
            return text if NUMBER.fullmatch(text) else ''
    return ''


def content(directory, name):
    """What a cgroup file holds, up to FILE_LIMIT bytes; empty when it is no plain file that can be read now."""
    try:
        fd = os.open(posixpath.join(directory, name), paths.READ)
    except OSError:
        return b''
    try:
        return os.read(fd, FILE_LIMIT)
    except OSError:
        return b''
    finally:
        os.close(fd)


def field(value):
    """One field of a line without control characters, so no daemon's reply can write a line of its own."""
    return CONTROL.sub('', value)


def line(*fields):
    """One line of the action's output, its fields separated by tabs."""
    return ('\t'.join(field(value) for value in fields) + '\n').encode('utf-8', 'replace')
