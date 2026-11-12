"""Sequence actions that run only in the host's namespaces, built after entering them so every check sees the host."""

from .. import paths
from .sequence import Command, nothing

HOMES = ('homes',)


def host(read, build, **options):
    return Command(read, build, host_only=True, **options)


def fixed(*argv):
    return host(nothing, lambda context: list(argv))


def in_home(context, path):
    """An account's home on the host, walked without following links and given below the open /home."""
    return paths.place(context.placement, path, paths.ENTRY, context.policy.roots_for(HOMES, True), context.policy.trusted())
