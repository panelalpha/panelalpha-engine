"""Actions whose values are read in order, for commands that take lists.

Each list travels as its length followed by its items (`2 NAME a NAME2 b`).
"""

import os
from dataclasses import dataclass, field
from operator import methodcaller

from .. import composefiles, paths, values
from ..refused import Refused
from . import Declared, Plan


class Values:
    def __init__(self, given):
        self.given = list(given)
        self.at = 0

    def one(self, check):
        if self.at >= len(self.given):
            raise Refused('too few values')
        value = check(self.given[self.at])
        self.at += 1
        return value

    def flag(self, name):
        return self.one(lambda value: value if value in ('', name) else values.refuse('flag', value)) == name

    def group(self, *checks):
        count = int(self.one(values.count))
        if count > len(self.given) - self.at:
            raise Refused('too few values')
        return [tuple(self.one(check) for check in checks) for _ in range(count)]

    def rest(self, check):
        taken = [check(value) for value in self.given[self.at:]]
        self.at = len(self.given)
        return taken

    def end(self):
        if self.at != len(self.given):
            raise Refused(f'{len(self.given) - self.at} values too many')


@dataclass(frozen=True)
class Reads:
    """A reader taking its values in order: each step takes its own from the Values and gives one checked value."""

    steps: tuple = ()

    def __call__(self, given):
        return tuple(step(given) for step in self.steps)


def reads(*readers):
    """The readers' steps one after another, a plain check read as `one` of it."""
    steps = ()
    for reader in readers:
        steps += (reader if isinstance(reader, Reads) else one(reader)).steps
    return Reads(steps)


def step(take):
    """A reader of one value, which `take(given)` takes from the Values its own way."""
    return Reads((take,))


def one(check):
    return step(methodcaller('one', check))


def rest(check):
    return step(methodcaller('rest', check))


def flag(name):
    return step(methodcaller('flag', name))


def group(*checks):
    return step(methodcaller('group', *checks))


def constant(value):
    """A reader of no value that gives `value`, for a declaration that fixes what another reads."""
    return step(lambda given: value)


nothing = Reads()


@dataclass
class Context:
    """The compose file, project directory and mount sources a builder may place."""

    policy: object
    host: bool
    placement: paths.Placement = field(default_factory=paths.Placement)

    def compose_file(self, path, words=()):
        return composefiles.place_file(self.placement, path, self.policy, words)

    def engine_directory(self, path, words=()):
        return composefiles.place_engine_directory(self.placement, path, self.policy, words)

    def mount_source(self, path, roots):
        return check_mount_source(path, [self.policy.roots[name] for name in roots], self.policy.host_root)


def check_mount_source(path, roots, host_root):
    """A bind-mount source walked on the host right before the run and passed by name, since the daemon resolves it.
    A missing source is left for the daemon to create."""
    paths.root_of(path, roots)
    location = paths.locate(host_root.rstrip('/') + path, [host_root.rstrip('/') + root for root in roots])
    if location.parent is None:
        return path
    try:
        if paths.is_symlink(location.parent, location.name):
            raise Refused(f"'{path}' is a symlink")
    finally:
        os.close(location.parent)
    return path


@dataclass(frozen=True)
class Command(Declared):
    """`read` checks the values and `build` makes the argv from a Context and them, or with `native` what the
    broker runs itself; `passes` names caller variables, `secret` values kept out of the audit."""

    read: object
    build: object
    on_host: bool = False
    detach: bool = False

    def check(self, given):
        reader = Values(given)
        checked = self.read(reader)
        reader.end()
        return checked

    def plan(self, checked, policy, host=False):
        if host and not (self.on_host or self.host_only):
            raise Refused('this action does not run in the host\'s namespaces')
        if self.host_only and not host:
            raise Refused('this action runs only in the host\'s namespaces')
        context = Context(policy, host)
        try:
            argv = self.build(context, *checked)
        except BaseException:
            for fd in [context.placement.cwd, *context.placement.fds]:
                if fd is not None:
                    os.close(fd)
            raise
        if self.native:
            return Plan(None, None, native=argv)
        return Plan(argv, context.placement.cwd, context.placement.fds, detach=self.detach)
