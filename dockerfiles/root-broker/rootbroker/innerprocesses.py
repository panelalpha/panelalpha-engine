"""A host process an account's daemon names by its pid in the account: its name, its place below a pid, its sockets.

It must sit in the account's pid namespace or a child of it (NS_GET_PARENT), and is read through one /proc/<pid>
directory fd, so a reused pid reads nothing.
"""

import fcntl
import os

NS_GET_PARENT = 0xB702
SOCKET_TABLES = ('net/tcp', 'net/tcp6')
TABLE_LIMIT = 4 << 20
DEPTH = 64


def process_name(pid):
    """The process's `comm` as the host's /proc has it, or None once it is gone."""
    try:
        with open(f'/proc/{pid}/comm') as f:
            return f.read().strip()
    except OSError:
        return None


def descends_from(pid, init_pid):
    """Whether the host process `pid` is `init_pid` or sits below it."""
    for _ in range(DEPTH):
        if pid == init_pid:
            return True
        if pid is None or pid <= 1:
            return False
        pid = parent(pid)
    return False


def parent(pid):
    try:
        with open(f'/proc/{pid}/status') as f:
            return next((int(line.split()[1]) for line in f if line.startswith('PPid:')), None)
    except (OSError, ValueError, IndexError):
        return None


def namespace_inode(fd):
    return os.fstat(fd).st_ino


def in_namespace(process_fd, account_namespace):
    """The process's pid namespace is the account's, or a child of it."""
    namespace = os.open('ns/pid', os.O_RDONLY | os.O_CLOEXEC, dir_fd=process_fd)
    try:
        if namespace_inode(namespace) == account_namespace:
            return True
        try:
            parent = fcntl.ioctl(namespace, NS_GET_PARENT)
        except OSError:
            return False
        try:
            return namespace_inode(parent) == account_namespace
        finally:
            os.close(parent)
    finally:
        os.close(namespace)


def account_level_pid(process_fd):
    """The process's pid one namespace below the host's, from `NSpid:` in its status."""
    with os.fdopen(os.open('status', os.O_RDONLY | os.O_CLOEXEC, dir_fd=process_fd), closefd=True) as f:
        for line in f:
            if line.startswith('NSpid:'):
                pids = line.split()[1:]
                return int(pids[1]) if len(pids) > 1 else None
    return None


def host_process(proc, init_pid, inner_pid):
    """An open /proc/<pid> directory of the host process the account calls `inner_pid`, or None."""
    account = os.open(f'{proc}/{init_pid}/ns/pid', os.O_RDONLY | os.O_CLOEXEC)
    try:
        account_namespace = namespace_inode(account)
    finally:
        os.close(account)
    for name in os.listdir(proc):
        if not name.isdigit():
            continue
        try:
            fd = os.open(f'{proc}/{name}', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        except OSError:
            continue
        try:
            if account_level_pid(fd) == inner_pid and in_namespace(fd, account_namespace):
                return fd
        except (OSError, ValueError):
            pass
        os.close(fd)
    return None


def socket_tables(process_fd):
    """`net/tcp` then `net/tcp6` of the process, as `cat` would print them; a missing one is skipped."""
    out = bytearray()
    for table in SOCKET_TABLES:
        try:
            fd = os.open(table, os.O_RDONLY | os.O_CLOEXEC, dir_fd=process_fd)
        except OSError:
            continue
        with os.fdopen(fd, 'rb') as f:
            out += f.read(TABLE_LIMIT)
    return bytes(out)
