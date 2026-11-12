"""`realpath -e` below a root: a link is followed only while it stays below it, so root never looks outside."""

import errno
import os
import stat

from . import paths
from .refused import Refused

TOP = os.O_PATH | os.O_DIRECTORY | os.O_CLOEXEC
MAX_LINKS = 40


class Trail:
    """The names and descriptors from the root down, each component opened on its own and a link read, not followed.

    `stepped` tells whether the last component taken was a name, not `.`, `..` or a link."""

    def __init__(self, root, top):
        self.root = root
        self.steps = [('', top)]
        self.links = 0
        self.stepped = False

    def close(self):
        for _, fd in self.steps:
            os.close(fd)

    def resolve(self, path, parts):
        pending = list(reversed(parts))
        while pending:
            part = pending.pop()
            self.stepped = False
            if part == '.':
                continue
            if part == '..':
                self.climb(path)
                continue
            self.step(path, part, pending)
        return self

    def ended(self):
        """The name, descriptor and directory of the last component; `.` in the directory a `.` or `..` ended in."""
        if self.stepped:
            (name, fd), (_, parent) = self.steps[-1], self.steps[-2]
            return name, fd, parent
        if len(self.steps) == 1:
            raise Refused(f'the path ends at {self.root}')
        return '.', self.steps[-1][1], self.steps[-1][1]

    def joined(self):
        return '/'.join([self.root.rstrip('/')] + [name for name, _ in self.steps[1:]]) or '/'

    def climb(self, path):
        if len(self.steps) == 1:
            raise Refused(f"'{path}' leaves {self.root}")
        os.close(self.steps.pop()[1])

    def step(self, path, part, pending):
        parent = self.steps[-1][1]
        fd = paths.open_beneath(parent, part, paths.FINAL)
        mode = os.fstat(fd).st_mode
        if stat.S_ISLNK(mode):
            os.close(fd)
            pending.extend(reversed(self.link(path, part, parent)))
            return
        self.steps.append((part, fd))
        self.stepped = True
        if pending and not stat.S_ISDIR(mode):
            raise NotADirectoryError(errno.ENOTDIR, os.strerror(errno.ENOTDIR))

    def link(self, path, name, parent):
        self.links += 1
        if self.links > MAX_LINKS:
            raise OSError(errno.ELOOP, os.strerror(errno.ELOOP))
        target = os.readlink(name, dir_fd=parent)
        if not target.startswith('/'):
            return paths.components(target)
        inside = paths.below(self.root, target)
        if inside is None:
            raise Refused(f"'{path}' leads out of {self.root}")
        while len(self.steps) > 1:
            os.close(self.steps.pop()[1])
        return inside


def real_path(path, roots):
    """The real path, an OSError when it does not exist, Refused when the way to it leaves its root."""
    root = paths.root_of(path, roots)
    trail = Trail(root, os.open(root, TOP))
    try:
        return trail.resolve(path, paths.below(root, path)).joined()
    finally:
        trail.close()


def existing(roots, given):
    """Answers as `realpath -e -- <given>...` does, each path resolved before anything is written."""
    answers = []
    for path in given:
        try:
            answers.append((real_path(path, roots) + '\n', None))
        except OSError as e:
            answers.append((None, f'realpath: {path}: {e.strerror}\n'))

    def native(output):
        for found, error in answers:
            if found is not None:
                output.stdout(os.fsencode(found))
            else:
                output.stderr(error.encode())
        return 1 if any(found is None for found, _ in answers) else 0
    return native
