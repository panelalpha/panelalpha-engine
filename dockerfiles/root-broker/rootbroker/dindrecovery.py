"""The wedged-DinD recovery on the host's own daemon, without rebooting the host.

An account container whose sysbox-fs FUSE connection deadlocked gets its restart policy off, the connection aborted,
its cgroup killed and the container removed, then as asked its inner Docker store wiped and its project brought up.
"""

import contextlib
import glob
import http.client
import os
import posixpath
import shutil
import stat
import subprocess
import time
from dataclasses import dataclass

from . import accountdaemon, composefiles, dockerapi, paths
from .actions.failures import Failures
from .dockerapi import Failed, Unavailable, quoted
from .libc import LIBC
from .output import DROPPED

PREFIX = '[recover-sysbox-dind] '
FUSE = '/sys/fs/fuse/connections'
CGROUP = '/sys/fs/cgroup'
MOUNTINFO = '/proc/self/mountinfo'
SYSBOXFS = '/var/lib/sysboxfs'
USERS = '/opt/panelalpha/shared-hosting/users'
HOMES = '/home'
PROC = '/proc'
SYSTEM_SLICE = 'system.slice'
SCOPE = 'docker-{}.scope'
INNER_DOCKER = 'docker'
INNER_DOCKER_MODE = 0o710
PROBE_SECONDS = 8
HEALTH_SECONDS = 15
REMOVE_SECONDS = 45
ABORT = b'1\n'
KILL = b'1'
FILE_LIMIT = 1 << 20
RESTART_TO_NO = ((b'restart: always', b'restart: "no"'), (b'restart: "always"', b'restart: "no"'))
RESTART_BACK = (b'restart: "no"', b'restart: always')
RESTART_LAST = b'restart: no'
RESTART_LAST_TO = b'restart: always'
DOCKER = 'docker'
COMPOSE = ('compose', 'up', '-d')
SLEEP = time.sleep
MNT_DETACH = 2
PROBE_FAILURES = Failures(((Failed, Unavailable, OSError, http.client.HTTPException), '', 1))


def detect(daemon, output):
    """One `STUCK` or `OK` line per account container, exit 1 when one of them looks stuck."""
    return Recovery(daemon, output).detect()


def recover_all(daemon, output, wipe_inner, recreate):
    """Every account container that looks stuck is recovered, its last failure the exit code."""
    return Recovery(daemon, output, wipe_inner, recreate).all()


def recover_account(daemon, output, name, wipe_inner, recreate):
    """The named account's container, recovered only when it looks stuck."""
    return Recovery(daemon, output, wipe_inner, recreate).account(name)


@dataclass(frozen=True)
class Recovery:
    """One run on the host's daemon: its lines on `output`, `wipe_inner` and `recreating` its two options."""

    daemon: object
    output: object
    wipe_inner: bool = False
    recreating: bool = False

    def log(self, message):
        self.output.stdout(f'{PREFIX}{message}\n'.encode())

    def error(self, message):
        self.output.stderr(f'{PREFIX}ERROR: {message}\n'.encode())

    def done(self, name):
        self.log(f'done {name} (D={d_state_count()})')
        return 0

    def detect(self):
        self.log(f'scanning sysbox containers (host D={d_state_count()})')
        stuck = False
        for name, container in self.account_containers():
            looks = self.looks_stuck(name, container)
            stuck = stuck or looks
            self.output.stdout(f'{"STUCK" if looks else "OK   "} {name}\n'.encode())
        return 1 if stuck else 0

    def all(self):
        targets = [name for name, container in self.account_containers() if self.looks_stuck(name, container)]
        if not targets:
            self.log('no stuck sysbox containers detected')
            return 0
        self.log('stuck targets: ' + ' '.join(targets))
        code = 0
        for name in targets:
            code = self.one(name) or code
        return code

    def account(self, name):
        container = self.checked(name)
        if container is None:
            return 1
        if not self.looks_stuck(name, container):
            self.log(f'{name} is up and its inner docker answers; nothing to recover')
            return 0
        return self.one(name)

    def checked(self, name):
        """The named container, or None once an error says it is missing, not sysbox's or not the account's own."""
        container = self.daemon.json('GET', f'/containers/{quoted(name)}/json')
        if container is None:
            self.error(f'container not found: {name}')
        elif not is_sysbox(container):
            self.error(f'{name} is not runtime=sysbox-runc')
        elif not accountdaemon.is_account_container(container, name, USERS):
            self.error(f"{name} is not the account's own container")
        else:
            return container
        return None

    def one(self, name):
        """One container recovered, step by step, the exit code that of the step that failed: 2 when it cannot be
        removed."""
        container = self.checked(name)
        if container is None:
            return 1
        container_id = container.get('Id') if isinstance(container.get('Id'), str) else ''
        self.log(f'recovering {name} id={container_id[:12]} (D={d_state_count()})')
        self.update_restart(container_id)
        edit_restart(name, recreate=False)
        self.abort_fuse(name, container_id)
        if kill_scope(container_id):
            self.log('wrote cgroup.kill')
            SLEEP(1)
        if not self.removed(name, container_id):
            return 2
        if self.wipe_inner and not self.wipe(name):
            return 1
        unmount(container_id)
        return self.recreate(name) if self.recreating else self.done(name)

    def recreate(self, name):
        """`--recreate`: the account's compose project brought up again, then its inner daemon pinged."""
        directory = posixpath.join(USERS, name)
        if not has_compose_file(name):
            self.error(f'cannot recreate: missing {posixpath.join(directory, composefiles.FILE)}')
            return 1
        edit_restart(name, recreate=True)
        self.log(f'recreating via docker compose up -d in {directory}')
        code = self.compose_up(directory)
        if code != 0:
            return code
        SLEEP(3)
        if not self.inner_docker_answers(name, HEALTH_SECONDS):
            self.error('recreated outer container but inner docker not responding yet')
            return 3
        self.log('inner docker healthy after recreate')
        return self.done(name)

    def account_containers(self):
        """Every account container the host's daemon lists, with its own container's description."""
        found = self.daemon.json('GET', '/containers/json?all=1', missing=())
        for summary in found if isinstance(found, list) else ():
            name = listed_name(summary)
            if not name:
                continue
            container = self.daemon.json('GET', f'/containers/{quoted(name)}/json')
            if is_sysbox(container) and accountdaemon.is_account_container(container, name, USERS):
                yield name, container

    def looks_stuck(self, name, container):
        """The three signs: a wedged exec, an inner daemon that does not answer, a FUSE connection waiting."""
        if self.exec_code(name, ['true'], PROBE_SECONDS) != 0 or not self.inner_docker_answers(name, PROBE_SECONDS):
            return True
        container_id = container.get('Id')
        found = fuse_id(container_id) if isinstance(container_id, str) else None
        return found is not None and fuse_waiting(found) > 0

    def exec_code(self, name, argv, seconds):
        """The command's exit code in the account's container, 1 when it does not finish or fails."""
        return PROBE_FAILURES.run(DROPPED, lambda: self.daemon.bounded(seconds).exec(DROPPED, name, argv, seconds=seconds))

    def inner_docker_answers(self, name, seconds):
        """The account's own dockerd answers /_ping from inside the account, within `seconds`."""
        argv = [
            'curl', '-sf', '--max-time', str(seconds - 2), '--unix-socket', '/run/docker.sock', 'http://localhost/_ping',
        ]
        return self.exec_code(name, argv, seconds) == 0

    def update_restart(self, container_id):
        """`docker update --restart=no`, its answer ignored."""
        with contextlib.suppress(Failed, Unavailable, OSError):
            self.daemon.json('POST', f'/containers/{quoted(container_id)}/update', missing=(),
                             body={'RestartPolicy': {'Name': 'no'}})

    def abort_fuse(self, name, container_id):
        """Aborts the container's sysboxfs FUSE connection, when it has one."""
        found = fuse_id(container_id)
        if found is None:
            self.log(f'no sysboxfs fuse mount found for {name} (continuing)')
            return
        self.log(f'fuse connection id={found}')
        if write_number(posixpath.join(FUSE, found, 'abort'), ABORT):
            self.log(f'aborted fuse connection {found}')
            SLEEP(1)

    def removed(self, name, container_id):
        """`docker rm -f`, retried once every FUSE connection is aborted; False once an error says it failed again."""
        if self.remove(container_id):
            self.log('docker rm -f ok')
            return True
        self.log('docker rm -f failed — aborting all fuse connections and retrying')
        abort_all()
        SLEEP(2)
        if not self.remove(container_id):
            self.error(f'still cannot remove {name} — host reboot may be required')
            return False
        self.log('docker rm -f ok after global fuse abort')
        return True

    def remove(self, container_id):
        """`docker rm -f`, bounded to 45 s; a container already gone is no failure, as the CLI has it."""
        try:
            status, _ = self.daemon.call('DELETE', f'/containers/{quoted(container_id)}?force=1',
                                         limit=dockerapi.SHORT_REPLY_LIMIT, seconds=REMOVE_SECONDS)
        except (Failed, Unavailable, OSError):
            return False
        return status in (200, 204, 404)

    def wipe(self, name):
        """Wipes the account's inner Docker data root and makes it again, mode 0710, never through a link."""
        data_root = posixpath.join(HOMES, name, INNER_DOCKER)
        try:
            home = open_below(HOMES, name)
        except FileNotFoundError:
            return True
        except OSError as e:
            self.error(f'refusing to wipe {data_root}: {e.strerror}')
            return False
        try:
            try:
                mode = os.lstat(INNER_DOCKER, dir_fd=home).st_mode
            except FileNotFoundError:
                return True
            if stat.S_ISLNK(mode):
                self.error(f'refusing to wipe {data_root}: it is a symlink')
                return False
            if not stat.S_ISDIR(mode):
                return True
            self.log(f'wiping inner docker data-root {data_root}')
            shutil.rmtree(INNER_DOCKER, dir_fd=home)
            os.mkdir(INNER_DOCKER, INNER_DOCKER_MODE, dir_fd=home)
            made = os.open(INNER_DOCKER, paths.DIRECTORY | os.O_NOFOLLOW, dir_fd=home)
            try:
                os.fchmod(made, INNER_DOCKER_MODE)
            finally:
                os.close(made)
            return True
        except OSError as e:
            self.error(f'cannot wipe {data_root}: {e.strerror}')
            return False
        finally:
            os.close(home)

    def compose_up(self, directory):
        """`docker compose up -d` in the account's project, its output relayed and its exit code returned."""
        done = subprocess.run([DOCKER, *COMPOSE], cwd=directory, capture_output=True)
        self.output.stdout(done.stdout)
        self.output.stderr(done.stderr)
        return done.returncode


def d_state_count():
    """The host's processes in an uninterruptible wait, as `ps -eo stat` counts them."""
    count = 0
    for name in os.listdir(PROC):
        if not name.isdigit():
            continue
        try:
            with open(posixpath.join(PROC, name, 'stat'), 'rb') as f:
                status = f.read()
        except OSError:
            continue
        if status[status.rfind(b')') + 2:][:1] == b'D':
            count += 1
    return count


def is_sysbox(container):
    return isinstance(container, dict) and (container.get('HostConfig') or {}).get('Runtime') == dockerapi.SYSBOX_RUNTIME


def listed_name(summary):
    """The container's name from its listing, without Docker's leading slash."""
    names = summary.get('Names') if isinstance(summary, dict) else None
    return names[0].lstrip('/') if names and isinstance(names[0], str) else ''


def fuse_id(container_id):
    """The FUSE connection of the container's sysboxfs mount, the minor of mountinfo's third field."""
    marker = posixpath.join(SYSBOXFS, container_id)
    try:
        with open(MOUNTINFO) as f:
            for line in f:
                fields = line.split()
                if marker in line and len(fields) > 2:
                    return fields[2].partition(':')[2] or None
    except OSError:
        return None
    return None


def fuse_waiting(found):
    """The number of requests waiting on a FUSE connection, 0 when it cannot be read."""
    try:
        with open(posixpath.join(FUSE, str(found), 'waiting')) as f:
            return int(f.read().strip())
    except (OSError, ValueError):
        return 0


def kill_scope(container_id):
    """SIGKILLs the container's cgroup subtree through its cgroup.kill, when it has a writable one."""
    scope = posixpath.join(CGROUP, SYSTEM_SLICE, SCOPE.format(container_id))
    return write_number(posixpath.join(scope, 'cgroup.kill'), KILL)


def abort_all():
    """Aborts every FUSE connection the host has, errors ignored."""
    for path in glob.glob(posixpath.join(FUSE, '*', 'abort')):
        write_number(path, ABORT)


def write_number(path, data):
    """Writes a sysfs value without following a link, False when it is not writable or the write fails."""
    try:
        fd = os.open(path, os.O_WRONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        return False
    try:
        os.write(fd, data)
    except OSError:
        return False
    finally:
        os.close(fd)
    return True


def unmount(container_id):
    """Lazily unmounts the removed container's sysboxfs directory and removes it, errors ignored."""
    path = posixpath.join(SYSBOXFS, container_id)
    if not os.path.isdir(path):
        return
    LIBC.umount2(os.fsencode(path), MNT_DETACH)
    with contextlib.suppress(OSError):
        os.rmdir(path)


def open_below(root, name):
    """`root`'s directory `name`, each component opened without following a link."""
    parent = os.open(root, paths.DIRECTORY)
    try:
        return paths.open_beneath(parent, name)
    finally:
        os.close(parent)


@contextlib.contextmanager
def compose_file(name, flags):
    """The account's compose file, opened without following a link; None when it is missing, a link or not a regular
    file below a real directory."""
    try:
        directory = open_below(USERS, name)
    except OSError:
        yield None
        return
    try:
        fd = open_regular(directory, flags)
        try:
            yield fd
        finally:
            if fd is not None:
                os.close(fd)
    finally:
        os.close(directory)


def has_compose_file(name):
    """Whether the account has a compose file the recovery may bring up."""
    with compose_file(name, os.O_RDONLY) as fd:
        return fd is not None


def edit_restart(name, recreate):
    """The compose file's restart lines rewritten for the removal or the recreate, links never followed."""
    with compose_file(name, os.O_RDWR) as fd, contextlib.suppress(OSError):
        if fd is not None:
            rewrite_restart(fd, recreate)


def rewrite_restart(fd, recreate):
    """The compose file's bytes read from `fd`, rewritten and written back in place."""
    content = os.read(fd, FILE_LIMIT + 1)
    if len(content) > FILE_LIMIT:
        return
    edited = rewritten_restart(content, recreate)
    os.lseek(fd, 0, os.SEEK_SET)
    written = 0
    while written < len(edited):
        written += os.write(fd, edited[written:])
    os.ftruncate(fd, len(edited))


def rewritten_restart(content, recreate):
    """The compose file's lines with `restart: always` made `restart: "no"` for the removal, and back for the
    recreate, line by line."""
    lines = content.split(b'\n')
    for index, line in enumerate(lines):
        if recreate:
            line = line.replace(*RESTART_BACK)
            if line.endswith(RESTART_LAST):
                line = line[:-len(RESTART_LAST)] + RESTART_LAST_TO
        else:
            for old, new in RESTART_TO_NO:
                line = line.replace(old, new)
        lines[index] = line
    return b'\n'.join(lines)


def open_regular(directory, flags):
    """The compose file opened without following a link, or None when it is missing, a link or not a regular file."""
    try:
        fd = os.open(composefiles.FILE, flags | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK, dir_fd=directory)
    except OSError:
        return None
    if stat.S_ISREG(os.fstat(fd).st_mode):
        return fd
    os.close(fd)
    return None
