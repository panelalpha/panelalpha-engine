"""The rootfile:// helper: `<mode> <path> <root> [<opened root>]`, one command per stdin line, answered `OK` or `ERR`.

Every component below the root is opened without following links; a link is followed only while it stays below it.
"""

import errno
import json
import os
import stat
import string
import sys

from .paths import below
from .realpaths import TOP, Trail
from .refused import Refused

USAGE = 'Usage: pa-file-stream <mode> <path> <root> [<opened root>]\n'
OPEN_FLAGS = {'r': 0, 'w': os.O_CREAT | os.O_TRUNC, 'a': os.O_CREAT | os.O_APPEND, 'x': os.O_CREAT | os.O_EXCL,
              'c': os.O_CREAT}
STAT_FIELDS = ('dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize',
               'blocks')


class Refusal(Exception):
    pass


def open_flags(mode):
    if mode[:1] not in OPEN_FLAGS:
        return None
    flags = OPEN_FLAGS[mode[0]]
    access = os.O_RDWR if '+' in mode else os.O_WRONLY if flags else os.O_RDONLY
    return flags | access | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC


def open_below(mode, path, root, opened_root):
    inside = below(root, path)
    flags = open_flags(mode)
    trail = None
    try:
        if inside is None:
            raise Refusal()
        trail = Trail(root, os.open(opened_root, TOP))
        name, seen, parent = trail.resolve(path, inside).ended()
    except (Refusal, Refused, OSError) as e:
        if trail is not None:
            trail.close()
        raise Refusal(f'Refusing {path}: it does not resolve to a file under {root}') from e
    try:
        return opened_as_seen(path, mode, flags, name, seen, parent)
    finally:
        trail.close()


def opened_as_seen(path, mode, flags, name, seen, parent):
    try:
        if flags is None:
            raise OSError(errno.EINVAL, 'unknown mode')
        fd = os.open(name, flags, 0o666, dir_fd=parent)
    except OSError as e:
        raise Refusal(f'Cannot open {path} with mode {mode}') from e
    opened, expected = os.fstat(fd), os.fstat(seen)
    if (opened.st_dev, opened.st_ino) != (expected.st_dev, expected.st_ino):
        os.close(fd)
        raise Refusal(f'Refusing {path}: it changed between the check and the open')
    if not (stat.S_ISREG(opened.st_mode) or stat.S_ISDIR(opened.st_mode)):
        os.close(fd)
        raise Refusal(f'Refusing {path}: it is neither a file nor a directory')
    os.set_blocking(fd, True)
    return fd


def php_integer(text):
    digits = text.lstrip(string.whitespace)
    sign = digits[:1] if digits[:1] in ('-', '+') else ''
    number = digits[len(sign):]
    count = len(number) - len(number.lstrip(string.digits))
    return int(sign + number[:count]) if count else 0


def php_hex(text):
    digits = ''.join(c for c in text if c in string.hexdigits)
    return int(digits, 16) if digits else 0


class Stream:
    """An open file and the position PHP's stream would report for it."""

    def __init__(self, fd, stdin, stdout):
        self.fd = fd
        self.stdin = stdin
        self.stdout = stdout
        self.position = 0
        self.eof = False

    def reply(self, line, data=b''):
        self.stdout.write(line.encode() + b'\n' + data)
        self.stdout.flush()

    def serve(self):
        while line := self.stdin.readline():
            text = line.decode('latin-1').rstrip('\r\n')
            if text == '':
                continue
            command, _, argument = text.partition(' ')
            if self.answer(command.upper(), argument):
                return

    def answer(self, command, argument):
        handlers = {'READ': self.read, 'WRITE': self.write, 'SEEK': self.seek, 'TELL': self.tell, 'STAT': self.stat,
                    'EOF': self.at_end, 'CLOSE': self.close}
        handler = handlers.get(command)
        if handler is None:
            self.reply('ERR unknown_command')
            return False
        return handler(argument) is True

    def read(self, argument):
        wanted = php_integer(argument)
        data = self.read_up_to(wanted) if wanted > 0 else None
        if data is None:
            self.reply('ERR read_failed')
            return
        self.position += len(data)
        self.reply(f'OK {len(data):x}', data)

    def read_up_to(self, wanted):
        data = bytearray()
        while len(data) < wanted:
            try:
                chunk = os.read(self.fd, wanted - len(data))
            except OSError as e:
                self.eof = self.eof or e.errno != errno.EBADF
                return bytes(data) if data else None
            if not chunk:
                self.eof = True
                break
            data += chunk
        return bytes(data)

    def write(self, argument):
        length = php_hex(argument)
        data = self.stdin.read(length) if length else b''
        written = self.write_all(data)
        self.position += written
        self.reply('OK' if written == length else 'ERR write_failed')

    def write_all(self, data):
        written = 0
        try:
            while written < len(data):
                written += os.write(self.fd, data[written:])
        except OSError:
            pass
        return written

    def seek(self, argument):
        values = [php_integer(value) for value in (argument or '0 0').split(' ')]
        offset, whence = values[0], values[1] if len(values) > 1 else os.SEEK_SET
        if whence == os.SEEK_CUR:
            offset, whence = self.position + offset, os.SEEK_SET
        try:
            self.position = os.lseek(self.fd, offset, whence)
        except (OSError, OverflowError, ValueError):
            self.reply('ERR seek_failed')
            return
        self.eof = False
        self.reply('OK')

    def tell(self, _argument):
        self.reply(f'OK {self.position}')

    def stat(self, _argument):
        info = os.fstat(self.fd)
        values = [info.st_dev, info.st_ino, info.st_mode, info.st_nlink, info.st_uid, info.st_gid, info.st_rdev,
                  info.st_size, info[stat.ST_ATIME], info[stat.ST_MTIME], info[stat.ST_CTIME], info.st_blksize,
                  info.st_blocks]
        fields = {**{str(index): value for index, value in enumerate(values)}, **dict(zip(STAT_FIELDS, values))}
        self.reply('OK ' + json.dumps(fields, separators=(',', ':')))

    def at_end(self, _argument):
        self.reply('OK 1' if self.eof else 'OK 0')

    def close(self, _argument):
        os.close(self.fd)
        self.reply('OK')
        return True


def main(argv, stdin=None, stdout=None):
    stdin = stdin or sys.stdin.buffer
    stdout = stdout or sys.stdout.buffer
    if len(argv) not in (3, 4):
        sys.stderr.write(USAGE)
        return 1
    mode, path, root = argv[:3]
    try:
        fd = open_below(mode, path, root, argv[3] if len(argv) == 4 else root)
    except Refusal as e:
        sys.stderr.write(f'{e}\n')
        return 1
    try:
        Stream(fd, stdin, stdout).serve()
    except BrokenPipeError:
        return 1
    return 0
