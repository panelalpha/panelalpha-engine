"""An account's own dockerd, reached at /proc/<account init>/root/run/docker.sock without following links.

Its container must be the account's outer compose service, under sysbox or privileged as DIND_RUNTIME says. The
tenant controls that daemon, so every reply is read up to a limit and cut down to the fields the engine reads.
"""

import errno
import json
import os
import stat

from . import dockerapi, paths, peer, replies
from .dockerapi import Failed, Unavailable, daemon_error, quoted

INNER_SOCKET = 'run/docker.sock'
CLASSIC_SERVICE = 'php'
DIND_SERVICE = 'dind'
INNER_DIRECTORY = os.O_PATH | os.O_DIRECTORY | os.O_CLOEXEC
SECONDS = 30
WORDING = dockerapi.Wording(refused='the Docker daemon of container {name} is not running')


class Daemon(dockerapi.Daemon):
    """One account's daemon: the account's container checked once, its socket held open as an O_PATH descriptor,
    or a dialler that returns a connected socket."""

    def __init__(self, name, socket_fd=None, seconds=SECONDS, dial=None, init_pid=None, proc='/proc', address=None):
        super().__init__(name, dial or address or f'/proc/self/fd/{socket_fd}', seconds, WORDING)
        self.socket_fd = socket_fd
        self.init_pid = init_pid
        self.proc = proc

    def bounded(self, seconds):
        """This daemon with `seconds` as its default bound, reaching the same socket but not owning it."""
        bounded = super().bounded(seconds)
        bounded.socket_fd = None
        return bounded

    def close(self):
        if self.socket_fd is not None:
            os.close(self.socket_fd)
            self.socket_fd = None


def open_daemon(account, accounts_directory, host_socket=dockerapi.SOCKET, proc='/proc', seconds=SECONDS):
    """The daemon of `account`, a passwd entry already checked to be a hosting account, whose compose project lives
    in `accounts_directory`; `seconds` bounds each request made on the way."""
    container = account_container(account.name, host_socket, accounts_directory, seconds=seconds)
    pid = container['State']['Pid']
    pidfd = process(account.name, pid)
    try:
        socket_fd = inner_socket(account.name, proc, pid)
        try:
            same = account_container(account.name, host_socket, accounts_directory, seconds=seconds)
            seen_again = (same['State']['Pid'], same['State'].get('StartedAt'))
            if not peer.still_alive(pidfd) or seen_again != (pid, container['State'].get('StartedAt')):
                raise Unavailable(f'container {account.name} is restarting')
        except BaseException:
            os.close(socket_fd)
            raise
    finally:
        os.close(pidfd)
    return Daemon(account.name, socket_fd, seconds=seconds, init_pid=pid, proc=proc)


def open_container(account, accounts_directory, host_socket=dockerapi.SOCKET):
    """The host's daemon, asked only about the account's own container, its DinD one or its classic `php` one, which
    it checks first."""
    account_container(account.name, host_socket, accounts_directory, classic=True)
    return Daemon(account.name, address=host_socket)


def open_dind_container(account, accounts_directory, host_socket=dockerapi.SOCKET):
    """The host's daemon, asked only about the account's own DinD container, which it checks first."""
    account_container(account.name, host_socket, accounts_directory)
    return Daemon(account.name, address=host_socket)


def account_container(name, host_socket, accounts_directory, classic=False, seconds=SECONDS):
    try:
        status, body = dockerapi.request('GET', f'/containers/{quoted(name)}/json', host_socket, seconds)
    except OSError as e:
        raise Unavailable(f'the host\'s Docker did not answer: {e.strerror or e}') from e
    if status == 404:
        raise Unavailable(f'container {name} is not running')
    if status != 200:
        raise Failed(daemon_error(status, body))
    container = described(name, body)
    if not is_account_container(container, name, accounts_directory):
        if not classic:
            raise Failed(f'{name} has no Docker daemon of its own: container {name} is not its DinD account container')
        if not is_classic_container(container, name, accounts_directory):
            raise Failed(f"container {name} is not the account's own container")
    state = container.get('State') or {}
    if state.get('Running') is not True or not isinstance(state.get('Pid'), int) or state['Pid'] <= 0:
        raise Unavailable(f'container {name} is not running')
    return container


def described(name, body):
    """The host daemon's inspect of the container, which must be a JSON object."""
    try:
        return replies.unchecked_object(json.loads(body))
    except (ValueError, RecursionError) as e:
        raise Failed(f"the host's Docker answered /containers/{name}/json with no JSON object") from e


def is_account_container(container, name, accounts_directory):
    """The account's DinD container: under sysbox, or the privileged `dind` service an engine on DIND_RUNTIME=privileged
    runs, whose compose file only that setting lets through."""
    host = container.get('HostConfig') or {}
    privileged = host.get('Privileged') is True and dockerapi.labels(container).get(dockerapi.SERVICE) == DIND_SERVICE
    return (is_in_account_project(container, name, accounts_directory)
            and (host.get('Runtime') == dockerapi.SYSBOX_RUNTIME or privileged))


def is_classic_container(container, name, accounts_directory):
    """The `php` container of an account on the classic template, named and labelled as a DinD account container is."""
    return (is_in_account_project(container, name, accounts_directory)
            and dockerapi.labels(container).get(dockerapi.SERVICE) == CLASSIC_SERVICE)


def is_in_account_project(container, name, accounts_directory):
    labels = dockerapi.labels(container)
    return (
        container.get('Name') == '/' + name
        and labels.get(dockerapi.PROJECT) == name
        and labels.get(dockerapi.WORKING_DIR) == os.path.join(accounts_directory, name)
    )


def process(name, pid):
    try:
        return os.pidfd_open(pid)
    except ProcessLookupError as e:
        raise Unavailable(f'container {name} is restarting') from e


def inner_socket(name, proc, pid):
    """`run/docker.sock` below the init's root, refusing any link: a tenant could point one at the host's socket."""
    try:
        root = os.open(f'{proc}/{pid}/root', os.O_PATH | os.O_DIRECTORY | os.O_CLOEXEC)
    except (FileNotFoundError, ProcessLookupError) as e:
        raise Unavailable(f'container {name} is restarting') from e
    try:
        socket_fd = linkless(root, INNER_SOCKET)
    except FileNotFoundError as e:
        raise Unavailable(f'the Docker daemon of container {name} is not running') from e
    except OSError as e:
        if e.errno in paths.LINKED + (errno.EXDEV,):
            raise Failed(f'{INNER_SOCKET} in container {name} is a link, not the daemon\'s socket') from e
        raise
    finally:
        os.close(root)
    if not stat.S_ISSOCK(os.fstat(socket_fd).st_mode):
        os.close(socket_fd)
        raise Failed(f'{INNER_SOCKET} in container {name} is not a socket')
    return socket_fd


def linkless(root, relative):
    *directories, last = relative.split('/')
    with paths.Walk(os.dup(root), '') as walk:
        return walk.down(directories, INNER_DIRECTORY).into(last, paths.FINAL).taken()
