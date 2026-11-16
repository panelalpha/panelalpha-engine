"""`docker compose` and `docker buildx build` for an account's stack, run by the broker against the account's own
daemon, outside its container (see sandbox)."""

import socket

from .. import sandbox, values
from ..refused import Refused
from .accountdocker import asking
from .accountrun import absolute
from .sequence import group, reads, rest

CLIENTS = {'compose': None, 'buildx': ('build',)}
SUBCOMMANDS = frozenset({
    'up', 'down', 'stop', 'start', 'restart', 'pull', 'build', 'run', 'create', 'ps', 'logs', 'config',
})
GLOBAL_OPTIONS_WITH_VALUE = frozenset({
    '-f', '--file', '-p', '--project-name', '--project-directory', '--env-file', '--profile', '--ansi',
    '--progress', '--parallel',
})
GLOBAL_FLAGS = frozenset({'--compatibility', '--dry-run'})
ENVIRONMENT = frozenset({'PWD', 'DOCKER_CONFIG'})


AS = {'': False, 'root': True}


as_whom = values.one_of(AS, 'client identity')
environment_name = values.one_of(ENVIRONMENT, 'client environment variable')
read_run = reads(as_whom, values.optional(absolute), group(environment_name, values.text), rest(values.text))


def in_home(account, path):
    home = account.home.rstrip('/')
    if path != home and not absolute(path).startswith(home + '/'):
        raise Refused(f"'{path}' is not in the home of {account.name}")
    return path


def compose_subcommand(argv):
    """The subcommand of `compose`, after the global options it takes, refused unless it is one the engine sends."""
    rest = list(argv[1:])
    while rest:
        word = rest[0]
        name = word.split('=', 1)[0]
        if name in GLOBAL_FLAGS:
            rest = rest[1:]
        elif name in GLOBAL_OPTIONS_WITH_VALUE:
            rest = rest[1:] if '=' in word else rest[2:]
        elif word.startswith('-'):
            values.refuse('compose option', word)
        elif word not in SUBCOMMANDS:
            values.refuse('compose subcommand', word)
        else:
            return word
    raise Refused('compose runs no subcommand this allows')


def checked(context, account, as_root, workdir, environment, argv):
    """The values `run` takes, once the client is one this runs and its directory and every variable, all paths, are in
    the home."""
    client = argv[0] if argv else ''
    if client not in CLIENTS or (CLIENTS[client] is not None and argv[1:2] != list(CLIENTS[client])):
        raise Refused(f"'{' '.join(argv[:2])}' is not a docker client this runs")
    if client == 'compose':
        compose_subcommand(argv)
    variables = {name: in_home(account, value) for name, value in environment}
    return account, AS[as_root], in_home(account, workdir) if workdir else account.home, variables, argv


def connector(daemon):
    """A new connection to the daemon each call, through the socket the broker opened for it; one the daemon's full
    queue would hold back fails at once instead."""
    target = daemon.address
    if callable(target):
        return target

    def connect():
        conn = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        conn.setblocking(False)
        try:
            conn.connect(target)
        except OSError:
            conn.close()
            raise
        conn.setblocking(True)
        return conn

    return connect


def run(daemon, output, account, files, workdir, environment, argv):
    """`docker compose …` or `docker buildx build …` as the account in the sandbox, against its own daemon.

    With `root`, the client stands in for root in the account, and keeps root's reach over the home's files. It shares
    the account container's network, which the daemon holds."""
    return sandbox.run(connector(daemon), account, workdir, environment, argv, output, files, daemon.network)


ACTIONS = {
    'run': asking(read_run, run, prepare=checked),
}
