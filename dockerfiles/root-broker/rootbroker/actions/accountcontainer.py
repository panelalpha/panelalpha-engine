"""The `account-container` area, one action per App\\System\\Privileged\\RootAccountContainer method.

The container is found by name and checked by its compose labels and runtime before anything runs; a registry
refresh takes only the DinD one.
"""

import os
import signal
import urllib.parse

from .. import accountdaemon, innerprocesses, replies
from ..dockerapi import Failed, Unavailable, quoted
from ..refused import Refused
from .accountdocker import asking
from .accountrun import read_exec_values
from .sequence import nothing

DAEMON_CONFIG = '/etc/docker/daemon.json'
BIND = 'bind'
DOCKERD = 'dockerd'
PS_ARGS = '-eo pid,comm'

WATCHED_STDIN = '''set -m
exec 3<&0
"$@" </dev/null &
child=$!
( cat <&3 >/dev/null; kill -TERM -- -"$child" 2>/dev/null; sleep 3; kill -KILL -- -"$child" 2>/dev/null ) &
watch=$!
exec 2>/dev/null
wait "$child"
status=$?
kill -KILL -- -"$watch" 2>/dev/null
exit "$status"'''


def in_container(daemon, output, user, workdir, environment, argv):
    """`docker exec -i [-u] [-w] [-e]… <account> <argv>`, the command ends with its caller's connection; the exit
    code is the command's."""
    return daemon.exec(output, daemon.name, watched(argv), user=user, workdir=workdir, environment=environment,
                       watch_stdin=True)


def watched(argv):
    """`argv` under a shell that ends it, its process group too, once the exec's stdin does, which is the caller's
    connection: a command must not outlive the client that asked for it."""
    return ['/bin/bash', '-c', WATCHED_STDIN, 'bash', *argv]


def host(account, accounts_directory):
    return accountdaemon.open_container(account, accounts_directory)


def dind(account, accounts_directory):
    """The host's daemon, bound to the account's own DinD container; any other account's container is refused."""
    try:
        return accountdaemon.open_dind_container(account, accounts_directory)
    except Failed as e:
        raise Refused(str(e)) from e


def daemon_config_mounted(daemon, output):
    """`1` when the account's container binds the daemon.json its engine renders, `0` when it carries its own."""
    mounted = any(mount.get('Type') == BIND and mount.get('Destination') == DAEMON_CONFIG
                  for mount in inspected(daemon).get('Mounts') or ())
    output.stdout(b'1\n' if mounted else b'0\n')
    return 0


def reload_daemon(daemon, output):
    """SIGHUP to the account's own dockerd, which then reads the daemon.json the engine has just written."""
    state = inspected(daemon).get('State') or {}
    pid = state.get('Pid')
    if state.get('Running') is not True or not isinstance(pid, int) or pid <= 0:
        raise Unavailable(f'container {daemon.name} is not running')
    pidfd = own_dockerd(daemon, pid)
    try:
        signal.pidfd_send_signal(pidfd, signal.SIGHUP)
    except ProcessLookupError:
        raise Failed(f'container {daemon.name} is running no dockerd of its own') from None
    finally:
        os.close(pidfd)
    return 0


def inspected(daemon):
    """The account's container as the host daemon has it now."""
    container = daemon.json('GET', f'/containers/{quoted(daemon.name)}/json')
    if container is None:
        raise Unavailable(f'container {daemon.name} is not running')
    return replies.container(container)


def own_dockerd(daemon, init_pid):
    """The pidfd of the container's own dockerd, its name and its place below the container's init read again after
    the descriptor was opened; Failed when the container runs none of its own."""
    pid = dockerd_pid(daemon)
    pidfd = open_pidfd(pid) if pid is not None else None
    if pidfd is not None and innerprocesses.process_name(pid) == DOCKERD and innerprocesses.descends_from(pid, init_pid):
        return pidfd
    if pidfd is not None:
        os.close(pidfd)
    raise Failed(f'container {daemon.name} is running no dockerd of its own')


def dockerd_pid(daemon):
    """The host pid the container's process list gives the first `dockerd`, or None."""
    query = urllib.parse.urlencode({'ps_args': PS_ARGS})
    top = replies.top(daemon.json('GET', f'/containers/{quoted(daemon.name)}/top?{query}', missing=()))
    for row in top.get('Processes') or ():
        if len(row) > 1 and row[1] == DOCKERD and row[0].isascii() and row[0].isdigit():
            return int(row[0])
    return None


def open_pidfd(pid):
    try:
        return os.pidfd_open(pid)
    except ProcessLookupError:
        return None


ACTIONS = {
    'exec': asking(read_exec_values, in_container, opener=host),
    'daemonConfigMounted': asking(nothing, daemon_config_mounted, opener=dind),
    'reloadDaemon': asking(nothing, reload_daemon, opener=dind),
}
