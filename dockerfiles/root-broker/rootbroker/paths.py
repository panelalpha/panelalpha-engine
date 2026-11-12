"""Opens a caller's path without following symlinks and turns it into `./name` or `/proc/self/fd/N[/name]`.

Below an untrusted root each component is opened relative to the one before it (openat2 RESOLVE_NO_SYMLINKS, or
O_NOFOLLOW), since a DinD tenant can leave root-owned links in its home.
"""

import contextlib
import ctypes
import errno
import os
import posixpath
import stat
import tempfile
from dataclasses import dataclass, replace

from .libc import LIBC, last_error
from .refused import Refused

SYS_OPENAT2 = 437
AT_FDCWD = -100
RESOLVE_NO_SYMLINKS = 0x04
RESOLVE_BENEATH = 0x08
DIRECTORY = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC
FINAL = os.O_PATH | os.O_NOFOLLOW | os.O_CLOEXEC
READ = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
CREATED = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC
LINKED = (errno.ELOOP, errno.ENOTDIR)
MISSING = 'missing'
NOT_A_DIRECTORY = 'not-a-directory'
DENIED = 'denied'
KIND_LINK = 'symlink'
KIND_DIRECTORY = 'directory'
KIND_FILE = 'file'

ENTRY = 'entry'
FOLLOW = 'follow'
NOFOLLOW = 'nofollow'
EXISTING = 'existing'
NAMED = 'named'
COPY_SOURCE = 'copy-source'
COPY_TARGET = 'copy-target'
MOVE_TARGET = 'move-target'
CREATE = 'create'
CREATE_PRIVATE = 'create-private'
PARENTS = 'parents'
RESOLVE = 'resolve'
AS_GIVEN = 'as-given'
ROOT_ITSELF = 'root-itself'
TAGGED = 'tagged'
CREATE_PERMISSIONS = {CREATE: 0o666, CREATE_PRIVATE: 0o600}


class OpenHow(ctypes.Structure):
    _fields_ = [('flags', ctypes.c_uint64), ('mode', ctypes.c_uint64), ('resolve', ctypes.c_uint64)]


openat2_available = None


def openat2(dirfd, name, flags, resolve):
    how = OpenHow(flags, 0, resolve)
    fd = LIBC.syscall(ctypes.c_long(SYS_OPENAT2), ctypes.c_int(dirfd), ctypes.c_char_p(os.fsencode(name)),
                      ctypes.byref(how), ctypes.c_size_t(ctypes.sizeof(how)))
    if fd < 0:
        raise last_error(name)
    return fd


def kernel_has_openat2():
    global openat2_available
    if openat2_available is None:
        try:
            os.close(openat2(AT_FDCWD, '/', DIRECTORY, 0))
            openat2_available = True
        except OSError:
            openat2_available = False
    return openat2_available


def open_beneath(fd, name, flags=DIRECTORY):
    """`name`, one component, opened in the directory `fd` without following a link."""
    if kernel_has_openat2():
        return openat2(fd, name, flags, RESOLVE_NO_SYMLINKS | RESOLVE_BENEATH)
    return os.open(name, flags | os.O_NOFOLLOW, dir_fd=fd)


class Walk:
    """Down from an open directory one component at a time, never through a link; `fd` is the directory at
    `reached`, `path` the component last tried, and leaving the `with` closes `fd` unless it was `taken`."""

    def __init__(self, fd, path):
        self.fd = fd
        self.reached = path
        self.path = path

    def __enter__(self):
        return self

    def __exit__(self, *_):
        if self.fd is not None:
            os.close(self.fd)
            self.fd = None

    def taken(self):
        fd, self.fd = self.fd, None
        return fd

    def into(self, name, flags=DIRECTORY, check=None):
        """Opens `name` in the directory reached and moves to it; `check(fd, path)` returns it, or closes it and raises."""
        self.path = self.reached + '/' + name
        child = open_beneath(self.fd, name, flags)
        os.close(self.fd)
        self.fd = None
        self.fd = check(child, self.path) if check else child
        self.reached = self.path
        return self

    def down(self, parts, flags=DIRECTORY, check=None):
        """`into` each of `parts` in turn."""
        for part in parts:
            self.into(part, flags, check)
        return self

    @contextlib.contextmanager
    def refusing(self):
        """A missing component, or a link or a file on the way, refused by the path it stopped at."""
        try:
            yield self
        except FileNotFoundError as e:
            raise Refused(f"'{self.path}' does not exist") from e
        except OSError as e:
            if e.errno not in LINKED:
                raise
            raise Refused(f"'{self.path}' is a symlink or not a directory") from e


def is_symlink(fd, name):
    try:
        return stat.S_ISLNK(os.stat(name, dir_fd=fd, follow_symlinks=False).st_mode)
    except OSError:
        return False


@dataclass
class Location:
    """The open directory holding the final component, or why the walk stopped short."""

    path: str
    root: str
    parent: int | None
    parent_path: str
    name: str
    trailing: str
    stopped: str | None = None
    trusted: bool = False

    @property
    def text(self):
        return self.name + self.trailing


def components(path):
    """The path's names, without the empty and `.` parts a filesystem walk steps over, so `/a/./b` reads as `/a/b`
    and cannot name a root it is not below."""
    return [part for part in path.split('/') if part not in ('', '.')]


def below(root, path):
    """The components of `path` after those of `root`; None when `path` is neither `root` nor below it."""
    prefix, parts = components(root), components(path)
    return parts[len(prefix):] if parts[:len(prefix)] == prefix else None


def root_of(path, roots):
    matches = [root for root in roots if below(root, path) is not None]
    if not matches:
        raise Refused(f"'{path}' is not below {', '.join(sorted(roots)) or 'any root this action allows'}")
    return max(matches, key=lambda root: len(components(root)))


def locate(path, roots, make_parents=False, trusted_roots=()):
    parts = components(path)
    trailing = path[len(path.rstrip('/')):]
    if not parts:
        raise Refused(f"'{path}' names no file")
    root = root_of(path, roots)
    root_parts = components(root)
    if parts == root_parts or root in trusted_roots:
        return trusted(path, root, parts, trailing)
    fd, stopped = open_root(root, make_parents)
    if stopped:
        return Location(path, root, None, root, parts[-1], trailing, stopped)
    with Walk(fd, '/' + '/'.join(root_parts)) as walk:
        for part in parts[len(root_parts):-1]:
            stopped = step(walk, part, make_parents)
            if stopped:
                return Location(path, root, None, walk.path, parts[-1], trailing, stopped)
        return Location(path, root, walk.taken(), walk.path, parts[-1], trailing)


def trusted(path, root, parts, trailing):
    parent_path = '/' + '/'.join(parts[:-1])
    try:
        fd = os.open(parent_path, DIRECTORY)
    except FileNotFoundError:
        return Location(path, root, None, parent_path, parts[-1], trailing, MISSING)
    except NotADirectoryError:
        return Location(path, root, None, parent_path, parts[-1], trailing, NOT_A_DIRECTORY)
    except PermissionError:
        return Location(path, root, None, parent_path, parts[-1], trailing, DENIED)
    return Location(path, root, fd, parent_path, parts[-1], trailing, trusted=True)


def open_root(root, make_parents):
    try:
        return os.open(root, DIRECTORY), None
    except FileNotFoundError:
        if not make_parents:
            return None, MISSING
        os.makedirs(root, exist_ok=True)
        return os.open(root, DIRECTORY), None
    except NotADirectoryError:
        return None, NOT_A_DIRECTORY
    except PermissionError:
        return None, DENIED


def step(walk, part, make_parents):
    """Into `part`, made first when it is missing and `make_parents`; or why the walk stops there."""
    try:
        try:
            walk.into(part)
        except FileNotFoundError:
            if not make_parents:
                return MISSING
            make_directory(walk.fd, part)
            walk.into(part)
    except PermissionError:
        return DENIED
    except OSError as e:
        if e.errno not in LINKED:
            raise
        if is_symlink(walk.fd, part):
            raise Refused(f"'{walk.path}' is a symlink") from e
        return NOT_A_DIRECTORY
    return None


def make_directory(fd, name):
    try:
        os.mkdir(name, 0o777, dir_fd=fd)
    except FileExistsError:
        pass


def open_final(location):
    flags = FINAL & ~os.O_NOFOLLOW if location.trusted else FINAL
    try:
        fd = os.open(location.name, flags, dir_fd=location.parent)
    except FileNotFoundError:
        return None, MISSING
    mode = os.fstat(fd).st_mode
    if stat.S_ISLNK(mode):
        os.close(fd)
        return None, KIND_LINK
    return fd, KIND_DIRECTORY if stat.S_ISDIR(mode) else KIND_FILE


def create_final(location, mode=0o666):
    try:
        return os.open(location.name, CREATED, mode, dir_fd=location.parent)
    except FileExistsError:
        return None


class Onto(str):
    """A move target that was missing or no directory at the check, by its name: the command renames onto it."""


class Placement:
    """One command's operands, and the working directory and descriptors they resolve against."""

    def __init__(self, use_cwd=True):
        self.use_cwd = use_cwd
        self.cwd = None
        self.cwd_path = None
        self.fds = []
        self.sentinels = {}
        self.copied_mode = None

    def in_parent(self, location):
        if self.use_cwd and self.cwd is None:
            self.cwd, self.cwd_path = location.parent, location.parent_path
            return './' + location.text
        if self.use_cwd and location.parent_path == self.cwd_path:
            os.close(location.parent)
            return './' + location.text
        return self.descriptor(location.parent) + '/' + location.text

    def itself(self, location, fd):
        os.close(location.parent)
        return self.descriptor(fd) + location.trailing

    def descriptor(self, fd):
        self.fds.append(fd)
        return f'/proc/self/fd/{fd}'

    def stopped(self, location):
        if location.stopped not in self.sentinels:
            self.sentinels[location.stopped] = self.descriptor(sentinel(location.stopped))
        through = '/' if location.stopped == NOT_A_DIRECTORY else f'/{location.stopped}/'
        return self.sentinels[location.stopped] + through + location.text


def sentinel(kind):
    if kind in (MISSING, DENIED):
        directory = tempfile.mkdtemp(prefix='pa-root-')
        fd = os.open(directory, DIRECTORY)
        if kind == DENIED:
            os.chmod(directory, 0)
        os.rmdir(directory)
        return fd
    fd, name = tempfile.mkstemp(prefix='pa-root-')
    os.unlink(name)
    return fd


def place(placement, path, mode, roots, trusted_roots=()):
    if mode == RESOLVE:
        return resolvable(path, roots)
    if mode == AS_GIVEN:
        return as_given(path, roots)
    if mode == ROOT_ITSELF:
        return root_itself(path, roots)
    if mode == TAGGED:
        return path[:2] + as_given(path[2:], roots)
    location = locate(path, roots, mode == PARENTS, trusted_roots)
    if location.stopped:
        return placement.stopped(location)
    if mode in (ENTRY, PARENTS):
        return entry(placement, location)
    if mode == FOLLOW:
        return placement.in_parent(location)
    return final(placement, location, mode)


def entry(placement, location):
    if location.trailing and not location.trusted and is_symlink(location.parent, location.name):
        raise Refused(f"'{location.path}' is a symlink")
    return placement.in_parent(location)


def final(placement, location, mode):
    fd, kind = open_final(location)
    if kind == KIND_LINK:
        raise Refused(f"'{location.path}' is a symlink")
    if mode in (COPY_SOURCE, COPY_TARGET):
        return copied(placement, location, mode, fd, kind)
    if kind == MISSING and mode in CREATE_PERMISSIONS and not location.trailing:
        fd = create_final(location, CREATE_PERMISSIONS[mode])
        return final(placement, location, mode) if fd is None else placement.itself(location, fd)
    if kind == MISSING and mode == EXISTING:
        return missing(placement, location)
    if mode == MOVE_TARGET and kind != KIND_DIRECTORY:
        return renamed_onto(placement, location, fd)
    if kind == MISSING:
        return placement.in_parent(location)
    if mode == NAMED:
        os.close(fd)
        return placement.in_parent(location)
    return placement.itself(location, fd)


def copied(placement, location, mode, fd, kind):
    """A file cp reads or writes, held open so a link planted at its name later is never followed.

    A missing target is created here with the source's permission bits, as cp would create it."""
    if mode == COPY_TARGET and kind == KIND_DIRECTORY:
        raise Refused(f"'{location.path}' is a directory")
    if kind == MISSING and (mode == COPY_SOURCE or placement.copied_mode is None or location.trailing):
        return missing(placement, location)
    if kind == MISSING:
        fd = create_final(location, placement.copied_mode)
        if fd is None:
            return final(placement, location, mode)
    elif mode == COPY_SOURCE and kind == KIND_FILE:
        placement.copied_mode = stat.S_IMODE(os.fstat(fd).st_mode) & 0o777
    return placement.itself(location, fd)


def renamed_onto(placement, location, fd):
    if fd is not None:
        os.close(fd)
    return Onto(placement.in_parent(location))


def missing(placement, location):
    """A file missing at the check, as a path missing too, so a link planted at its name later is never followed."""
    os.close(location.parent)
    return placement.stopped(replace(location, parent=None, stopped=MISSING))


def resolvable(path, roots):
    root_of(posixpath.normpath(path), roots)
    return path


def as_given(path, roots):
    root_of(path, roots)
    return path


def root_itself(path, roots):
    if posixpath.normpath(path) not in [posixpath.normpath(root) for root in roots]:
        raise Refused(f"'{path}' is not {', '.join(sorted(roots)) or 'a root this action allows'}")
    return path
