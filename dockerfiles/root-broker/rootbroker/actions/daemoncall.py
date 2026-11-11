"""The build of a native action on a daemon, the host's or an account's: the daemon opened when the action runs and
closed once its work returns, and what goes wrong worded by the area's Failures tables."""

import functools
from dataclasses import dataclass

from .failures import Failures


@dataclass(frozen=True)
class DaemonCall:
    """`work(daemon, output, *values)` on the daemon `opener(context, *values)` opens, with the values left or as
    `prepare` checks them; `opening` words a failure to open the daemon, `failures` one in `work`."""

    work: object
    opener: object
    failures: Failures
    opening: Failures = Failures()
    prepare: object = None

    def __call__(self, context, *given):
        open_daemon, named, left = self.opener(context, *given)
        return functools.partial(self.run, open_daemon, self.prepare(context, *named, *left) if self.prepare else left)

    def run(self, open_daemon, given, output):
        try:
            daemon = open_daemon()
        except self.opening.caught as e:
            return self.opening.answered(output, e)
        try:
            return self.failures.run(output, self.work, daemon, output, *given, daemon=daemon)
        finally:
            daemon.close()
