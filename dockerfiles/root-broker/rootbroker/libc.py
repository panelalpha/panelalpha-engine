"""The C library, loaded once, for the calls the os module does not make."""

import ctypes
import os

LIBC = ctypes.CDLL(None, use_errno=True)
LIBC.syscall.restype = ctypes.c_long
LIBC.mount.argtypes = [ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p, ctypes.c_ulong, ctypes.c_char_p]
LIBC.umount2.argtypes = [ctypes.c_char_p, ctypes.c_int]
LIBC.prctl.argtypes = [ctypes.c_int, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong]
LIBC.capset.argtypes = [ctypes.c_void_p, ctypes.c_void_p]


def last_error(*filename):
    """The OSError for the errno a failed call left, in the system's words."""
    code = ctypes.get_errno()
    return OSError(code, os.strerror(code), *filename)
