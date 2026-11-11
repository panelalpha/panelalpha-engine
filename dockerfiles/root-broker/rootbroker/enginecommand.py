"""A command in an engine service's running container through the host's Engine API, with its output and exit code;
exit 1 when it cannot run.

    python3 -m rootbroker.enginecommand SERVICE COMMAND...
"""

import sys

from .actions import hostapi
from .actions.engineservices import Service
from .output import Printed

USAGE = 'usage: python3 -m rootbroker.enginecommand SERVICE COMMAND...'


def run(daemon, output, service, *argv):
    """`argv` in the running container of the engine's `service`, as `docker compose exec -T` runs it."""
    return Service(None, service).exec(daemon, output, list(argv))


def main(arguments):
    if len(arguments) < 2:
        print(USAGE, file=sys.stderr)
        return 2
    return hostapi.run_on_host(run, arguments, Printed())


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
