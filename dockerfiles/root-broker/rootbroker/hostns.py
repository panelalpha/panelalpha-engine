"""Enters pid 1's namespaces and root for a host request, as `nsenter --target 1 --all` does.

Everything is opened before anything is entered.
"""

import os

from .libc import LIBC, last_error

ORDER = ('user', 'cgroup', 'ipc', 'uts', 'net', 'pid', 'mnt', 'time')


def differs(target, name):
    try:
        theirs = os.stat(f'{target}/ns/{name}')
        ours = os.stat(f'/proc/self/ns/{name}')
    except FileNotFoundError:
        return False
    return (theirs.st_dev, theirs.st_ino) != (ours.st_dev, ours.st_ino)


def setns(fd):
    if hasattr(os, 'setns'):
        os.setns(fd, 0)
    elif LIBC.setns(fd, 0) != 0:
        raise last_error()


def enter(only=None, target='/proc/1'):
    """Every namespace of pid 1 that differs from ours, or only the named ones. The root comes with `mnt`."""
    try:
        enter_namespaces(only, target)
    except OSError as e:
        raise OSError(e.errno, f"cannot enter the host's namespaces: {e.strerror}") from e


def enter_namespaces(only, target):
    names = [name for name in ORDER if only is None or name in only]
    root = os.open(f'{target}/root', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC) if 'mnt' in names else None
    namespaces = [os.open(f'{target}/ns/{name}', os.O_RDONLY | os.O_CLOEXEC) for name in names if differs(target, name)]
    for fd in namespaces:
        setns(fd)
        os.close(fd)
    if root is None:
        return
    os.fchdir(root)
    os.chroot('.')
    os.chdir('/')
    os.close(root)
