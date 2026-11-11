"""The values the `files` and `account` areas declare their actions with."""

from .. import values
from . import Param


def plain(check):
    return Param(check)


def every(check):
    return Param(check, many=True)


def path(mode, roots, **flags):
    """A path below one of `roots`, placed in `mode`; `flags` are Param's `trim`, `writes` and `creates`."""
    return Param(values.path, mode, roots, **flags)


def every_path(mode, roots, **flags):
    return Param(values.path, mode, roots, many=True, **flags)
