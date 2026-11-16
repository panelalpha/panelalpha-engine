"""The `users` area: one action per App\\System\\Privileged\\RootUsers method, in the host's namespaces only.

Removal and quotas take only hosting accounts from the host's passwd, and a new account's home is /home/<name>.
"""

import os
import posixpath
import re
import stat
from dataclasses import dataclass

from .. import paths, values
from ..refused import Refused
from .failures import Failures
from .hosted import host
from .sequence import reads

SHELL = '/usr/sbin/nologin'
MARK = 'PanelAlpha project'
TRACE = '.panelalpha'
PROJECTS = 'users'
MOUNTINFO = '/proc/self/mountinfo'
ESCAPED = re.compile(r'\\([0-7]{3})')
FIELDS = '\t'

FAILURES = Failures((Refused, '{e}', 77))


@dataclass(frozen=True)
class Mount:
    device: str
    root: str
    point: str


def create(context, name, home):
    if home != posixpath.join(context.policy.roots['homes'], name):
        raise Refused(f"'{home}' is not the home an account {name!r} is made with")
    return ['useradd', '-M', '-d', home, '-s', SHELL, '-c', MARK, '-U', name]


def delete(context, name):
    context.policy.accounts.by_name(name)
    return ['userdel', name]


def hosting_accounts(context):
    """Every hosting account on the host, as `name<TAB>uid<TAB>gid<TAB>home<TAB>shell<TAB>engine` lines, the last 1
    for an account `create` made."""
    return FAILURES.writing(write_hosting_accounts, context.policy)


def write_hosting_accounts(output, policy):
    accounts = policy.accounts
    for account in accounts.entries():
        try:
            checked = accounts.checked(account)
        except Refused:
            continue
        engine = '1' if made_by_the_engine(policy, checked) else '0'
        fields = (checked.name, str(checked.uid), str(checked.gid), checked.home, checked.shell, engine)
        output.stdout((FIELDS.join(fields) + '\n').encode())


def made_by_the_engine(policy, account):
    """`create`'s mark in the comment field, on the home named after the account. An account made before `create` wrote
    the mark is the engine's only in `create`'s old shape (its shell, the private group `-U` made at the uid's number)
    and with a trace the engine leaves: `.panelalpha` in its home, or its project directory below `<engine>/users`."""
    if account.home != posixpath.join(policy.accounts.homes, account.name):
        return False
    if account.comment == MARK:
        return True
    return account.shell == SHELL and account.gid == account.uid and has_engine_trace(policy, account.name)


def has_engine_trace(policy, name):
    traces = [posixpath.join(policy.accounts.homes, name, TRACE)]
    if 'engine' in policy.roots:
        traces.append(posixpath.join(policy.roots['engine'], PROJECTS, name))
    return any(is_real_directory(trace) for trace in traces)


def is_real_directory(path):
    try:
        return stat.S_ISDIR(os.lstat(path).st_mode)
    except OSError:
        return False


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
    'hostingAccounts': host(reads(), hosting_accounts, native=True),
    'setQuota': host(reads(values.username, values.count, values.count, values.path), set_quota),
}
