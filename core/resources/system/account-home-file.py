"""Read or rename a file in an account's home without following a symlink.

Run as root by App\\System\\Filesystem. Below ROOT, the directory that holds
the account homes or one account's home, every directory on the path is opened
relative to the one before it with O_NOFOLLOW, and the file itself is opened or renamed relative
to the last of them, so the check that no component is a symlink and the read
or rename are the same system calls: a directory the tenant swaps for a link
at any moment fails the walk instead of redirecting it. ROOT itself is the
host's, and is opened as it is.

    read ROOT PATH             write PATH's contents to stdout
    rename ROOT SOURCE TARGET  rename SOURCE onto TARGET (a symlink at TARGET is replaced)

Exit 3: a directory on the way is a symlink or not a directory, or the file
to read is a symlink. Exit 4: the file to read is not a regular file. Exit 1:
any other failure. Paths must lie below ROOT, with no `.` or `..` components.
"""

import errno
import os
import stat
import sys

DIRECTORY = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
FILE = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
LINKED = (errno.ELOOP, errno.ENOTDIR)


class Refused(Exception):
    def __init__(self, message, code):
        super().__init__(message)
        self.code = code


def components(path):
    parts = path.split('/')
    if not path.startswith('/') or any(part in ('.', '..') for part in parts):
        raise Refused(f"'{path}' is not an absolute path without . or ..", 1)
    return [part for part in parts if part != '']


def below(root, path):
    prefix = components(root)
    parts = components(path)
    if parts[:len(prefix)] != prefix:
        raise Refused(f"'{path}' is not below {root}", 1)
    return parts[len(prefix):]


def open_directory(root, path):
    fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    walked = root.rstrip('/')
    try:
        for part in below(root, path):
            walked += '/' + part
            try:
                child = os.open(part, DIRECTORY, dir_fd=fd)
            except OSError as e:
                if e.errno in LINKED:
                    raise Refused(f"'{walked}' is a symlink or not a directory", 3) from e
                raise
            os.close(fd)
            fd = child
    except BaseException:
        os.close(fd)
        raise
    return fd


def split(path):
    parts = components(path)
    if not parts:
        raise Refused(f"'{path}' names no file", 1)
    return '/' + '/'.join(parts[:-1]), parts[-1]


def read(root, path):
    parent, name = split(path)
    directory = open_directory(root, parent)
    try:
        fd = os.open(name, FILE, dir_fd=directory)
    except OSError as e:
        if e.errno == errno.ELOOP:
            raise Refused(f"'{path}' is a symlink", 3) from e
        raise
    finally:
        os.close(directory)
    try:
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            raise Refused(f"'{path}' is not a regular file", 4)
        os.set_blocking(fd, True)
        while chunk := os.read(fd, 1 << 16):
            sys.stdout.buffer.write(chunk)
    finally:
        os.close(fd)


def rename(root, source, target):
    source_parent, source_name = split(source)
    target_parent, target_name = split(target)
    source_directory = open_directory(root, source_parent)
    try:
        target_directory = open_directory(root, target_parent)
        try:
            os.rename(source_name, target_name, src_dir_fd=source_directory, dst_dir_fd=target_directory)
        finally:
            os.close(target_directory)
    finally:
        os.close(source_directory)


def main(argv):
    if len(argv) == 3 and argv[0] == 'read':
        read(argv[1], argv[2])
    elif len(argv) == 4 and argv[0] == 'rename':
        rename(argv[1], argv[2], argv[3])
    else:
        raise Refused('usage: read ROOT PATH | rename ROOT SOURCE TARGET', 1)


if __name__ == '__main__':
    try:
        main(sys.argv[1:])
    except Refused as e:
        print(e, file=sys.stderr)
        sys.exit(e.code)
    except OSError as e:
        print(e.strerror, file=sys.stderr)
        sys.exit(1)
