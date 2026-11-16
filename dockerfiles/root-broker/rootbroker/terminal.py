"""A pseudo-terminal's size, which pa-root reads from its own and the broker sets on the command's, and taking one as
the controlling terminal."""

import fcntl
import struct
import termios

WINSIZE = struct.Struct('HHHH')


def set_size(fd, rows, cols):
    fcntl.ioctl(fd, termios.TIOCSWINSZ, WINSIZE.pack(rows, cols, 0, 0))


def size_of(fd):
    rows, cols, _, _ = WINSIZE.unpack(fcntl.ioctl(fd, termios.TIOCGWINSZ, WINSIZE.pack(0, 0, 0, 0)))
    return rows, cols


def take_terminal():
    fcntl.ioctl(0, termios.TIOCSCTTY, 0)
