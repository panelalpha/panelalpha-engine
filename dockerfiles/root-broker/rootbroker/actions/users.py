"""The `users` area: one action per App\\System\\Privileged\\RootUsers method, in the host's namespaces only.

Removal and quotas take only hosting accounts from the host's passwd, and a new account's home is /home/<name>.
"""

import posixpath
import re
from dataclasses import dataclass

from .. import paths, values
from ..refused import Refused
from .hosted import host
from .sequence import reads

SHELL = '/usr/sbin/nologin'
MOUNTINFO = '/proc/self/mountinfo'
ESCAPED = re.compile(r'\\([0-7]{3})')


@dataclass(frozen=True)
class Mount:
    device: str
    root: str
    point: str


def create(context, name, home):
    if home != posixpath.join(context.policy.roots['homes'], name):
        raise Refused(f"'{home}' is not the home an account {name!r} is made with")
    return ['useradd', '-M', '-d', home, '-s', SHELL, '-U', name]


def delete(context, name):
    context.policy.accounts.by_name(name)
    return ['userdel', name]


def set_quota(context, name, blocks, inodes, mount_point):
    context.policy.accounts.by_name(name)
    return ['setquota', '-u', name, blocks, blocks, inodes, inodes, quota_mount_point(mount_point)]


def quota_mount_point(path):
    """Quota tools take only the mount of a filesystem's root, so a bind of a subtree, like the homes' nosuid
    self-bind, is answered with that filesystem's root mount. Any other path is returned as given."""
    try:
        mounts = read_mounts()
    except OSError:
        return path
    holding = holding_mount(mounts, path)
    if holding is None or holding.root == '/':
        return path
    roots = [mount for mount in mounts if mount.device == holding.device and mount.root == '/']
    above = [mount for mount in roots if paths.below(mount.point, path) is not None]
    chosen = above or roots
    return max(chosen, key=lambda mount: len(paths.components(mount.point))).point if chosen else path


def holding_mount(mounts, path):
    """The mount `path` is reached through: the deepest mount point above it, the later of two on the same point."""
    holding = None
    for mount in mounts:
        if paths.below(mount.point, path) is None:
            continue
        if holding is None or len(paths.components(mount.point)) >= len(paths.components(holding.point)):
            holding = mount
    return holding


def read_mounts():
    with open(MOUNTINFO) as f:
        return [mount for mount in map(parse_mount, f) if mount is not None]


def parse_mount(line):
    fields = line.split(' ')
    if len(fields) < 5:
        return None
    return Mount(fields[2], unescape(fields[3]), unescape(fields[4]))


def unescape(field):
    return ESCAPED.sub(lambda match: chr(int(match.group(1), 8)), field)


ACTIONS = {
    'create': host(reads(values.username, values.path), create),
    'delete': host(reads(values.username), delete),
    'uid': host(reads(values.username), lambda context, name: ['id', '-u', name]),
    'gid': host(reads(values.username), lambda context, name: ['id', '-g', name]),
    'setQuota': host(reads(values.username, values.count, values.count, values.path), set_quota),
}
