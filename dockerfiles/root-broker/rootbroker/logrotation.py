"""What the broker's logrotate service runs in `sites-http`, through the host's Engine API, once logs are rotated.

    python3 -m rootbroker.logrotation reopen nginx|apache
    python3 -m rootbroker.logrotation modsecurity
"""

import contextlib
import sys

from . import hostdaemon
from .actions import hostapi
from .actions.webserver import WEB_SERVER
from .output import Printed

WEBSERVER_LABEL = 'com.panelalpha.webserver'
REOPENS = {
    'nginx': ['nginx', '-s', 'reopen'],
    'apache': ['apachectl', '-k', 'graceful'],
}
RESTARTS = {
    'nginx': ['service', 'nginx', 'restart'],
    'nginx-proxy': ['nginx', '-s', 'reload'],
    'apache': ['apachectl', 'restart'],
}
USAGE = 'usage: python3 -m rootbroker.logrotation reopen nginx|apache | modsecurity'


def reopen(daemon, output, server):
    """The logs reopened by the web server in `sites-http`: nginx's reopen, Apache's graceful restart."""
    return WEB_SERVER.exec(daemon, output, REOPENS[server])


def restart_after_audit_rotation(daemon, output):
    """Restarts the web server `sites-http` runs so ModSecurity writes the new audit log, or reloads nginx-proxy,
    whose nginx is the container's first process; nothing for any other."""
    command = RESTARTS.get(webserver(daemon))
    return WEB_SERVER.exec(daemon, output, command) if command else 0


def webserver(daemon):
    """The web server label of the service's one container; '' with none or several, and when the daemon does not
    answer, so that nothing restarts."""
    containers = []
    with contextlib.suppress(*hostdaemon.UNANSWERED):
        containers = WEB_SERVER.containers(daemon)
    return (containers[0].get('Labels') or {}).get(WEBSERVER_LABEL, '') if len(containers) == 1 else ''


def main(arguments):
    if len(arguments) == 2 and arguments[0] == 'reopen' and arguments[1] in REOPENS:
        return hostapi.run_on_host(reopen, arguments[1:], Printed())
    if arguments == ['modsecurity']:
        return hostapi.run_on_host(restart_after_audit_rotation, (), Printed())
    print(USAGE, file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
