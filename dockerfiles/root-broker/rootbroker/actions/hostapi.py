"""Actions on the host's own dockerd through its Engine API, declared the way `account-docker` actions are.

What goes wrong reaches stderr in the docker CLI's words and exits 1, as the CLI did.
"""

import functools
import http.client

from .. import hostdaemon, replies
from ..dockerapi import Failed, Unavailable
from .daemoncall import DaemonCall
from .failures import Failures
from .sequence import Command

FAILURES = Failures(
    ((Unavailable, Failed), '{e}', 1),
    (replies.Unexpected, "the host's Docker answered with an unexpected {e.where}", 1),
    ((ConnectionRefusedError, FileNotFoundError), hostdaemon.CANNOT_CONNECT, 1),
    ((http.client.HTTPException, ConnectionError), "the host's Docker stopped answering: {e!r}", 1),
    (TimeoutError, "the host's Docker sent nothing for {daemon.seconds}s", 1),
)


def host_daemon(seconds, context, *given):
    """The host's daemon, which no value names, opened with `seconds` as the bound of each of its requests."""
    return functools.partial(hostdaemon.open_host, seconds), (), given


def run_on_host(work, given, output):
    """`work` run on the host's daemon as a host action runs, from a process of the broker's own, its failures worded as
    the host actions word them."""
    call = DaemonCall(work, functools.partial(host_daemon, hostdaemon.SECONDS), FAILURES)
    return call.run(functools.partial(hostdaemon.open_host, hostdaemon.SECONDS), tuple(given), output)


def asking(read, work, prepare=None, passes=(), secret=(), on_host=False, host_only=False, seconds=hostdaemon.SECONDS):
    """A native action on the host's daemon: `DaemonCall(work, host_daemon(seconds), FAILURES, prepare=prepare)`."""
    return Command(read, DaemonCall(work, functools.partial(host_daemon, seconds), FAILURES, prepare=prepare),
                   native=True, passes=passes, secret=secret, on_host=on_host, host_only=host_only)
