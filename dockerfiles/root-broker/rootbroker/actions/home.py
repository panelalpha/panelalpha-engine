"""readInHome and renameInHome, native actions that walk below ROOT without following links.

Exit 3 for a link or non-directory on the way or a link to read, 4 for a file to read that is not regular, else 1.
"""

import errno
import operator
import os
import posixpath
import stat

from ..paths import DIRECTORY, LINKED, READ, Walk
from ..protocol import CHUNK
from ..refused import Refused
from .failures import Failures



class Stopped(Exception):
    def __init__(self, message, code):
        super().__init__(message)
        self.code = code


FAILURES = Failures(
    (Stopped, '{e}', operator.attrgetter('code')),
    (OSError, '{e.strerror}', 1),
)


def components(path):
    parts = path.split('/')
    if not path.startswith('/') or any(part in ('.', '..') for part in parts):
        raise Stopped(f"'{path}' is not an absolute path without . or ..", 1)
    return [part for part in parts if part != '']


def below(root, path):
    prefix = components(root)
    parts = components(path)
    if parts[:len(prefix)] != prefix:
        raise Stopped(f"'{path}' is not below {root}", 1)
    return parts[len(prefix):]


def open_directory(root, path):
    with Walk(os.open(root, DIRECTORY), root.rstrip('/')) as walk:
        parts = below(root, path)
        try:
            return walk.down(parts).taken()
        except OSError as e:
            if e.errno in LINKED:
                raise Stopped(f"'{walk.path}' is a symlink or not a directory", 3) from e
            raise


def split(path):
    parts = components(path)
    if not parts:
        raise Stopped(f"'{path}' names no file", 1)
    return '/' + '/'.join(parts[:-1]), parts[-1]


def checked_root(policy, root):
    homes = posixpath.normpath(policy.roots['homes'])
    normalised = posixpath.normpath(root)
    if normalised != homes and posixpath.dirname(normalised) != homes:
        raise Refused(f"'{root}' is neither {homes} nor a home directly below it")
    return root


def read(policy, root, path):
    """A file in the account's home, read as the account without following a link."""
    return FAILURES.writing(read_into, checked_root(policy, root), path)


def read_into(output, root, path):
    parent, name = split(path)
    directory = open_directory(root, parent)
    try:
        fd = os.open(name, READ, dir_fd=directory)
    except OSError as e:
        if e.errno == errno.ELOOP:
            raise Stopped(f"'{path}' is a symlink", 3) from e
        raise
    finally:
        os.close(directory)
    try:
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            raise Stopped(f"'{path}' is not a regular file", 4)
        os.set_blocking(fd, True)
        while chunk := os.read(fd, CHUNK):
            output.stdout(chunk)
    finally:
        os.close(fd)


def rename(policy, root, source, target):
    """A rename within the account's home, as the account, never across a link."""
    return FAILURES.finishing(rename_onto, checked_root(policy, root), source, target)


def rename_onto(root, source, target):
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
