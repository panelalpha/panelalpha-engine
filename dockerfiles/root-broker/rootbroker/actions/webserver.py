"""The `webserver` area: App\\System\\Privileged\\RootWebserver's reload, run once the request is answered.

The job is built from the reload kind alone and works through the host's daemon.
"""

import os
import posixpath
import re
import time

from .. import values
from ..dockerapi import Failed, Unavailable
from ..output import STDERR, Output, Printed
from ..refused import Refused
from . import hostapi
from .engineservices import SITES_HTTP, Service
from .failures import Failures
from .sequence import reads

SAFE = re.compile(r'[A-Za-z0-9_/.-]+')
NGINX_TEST = ['nginx', '-t']
RELOADS = {
    'litespeed': ['/usr/local/lsws/bin/lswsctrl', 'condrestart'],
    'nginx': ['/etc/init.d/nginx', 'reload'],
    'apache': ['apachectl', '-k', 'graceful'],
}
REBIND = 'nginx-rebind'
REBIND_SECONDS = 5
WEB_SERVER = Service(None, SITES_HTTP)
JOB_FAILURES = Failures(((Failed, Unavailable), '{e}', 1))


def kind(value):
    if value not in RELOADS and value != REBIND:
        values.refuse('webserver reload', value)
    return value


class Collected(Output):
    """What a command prints, kept rather than printed."""

    def __init__(self):
        self.data = bytearray()

    def stderr(self, data):
        self.data += data

    stdout = stderr


def detach(output, job):
    """Runs `job` in a process of its own, which the host's init reaps once the request is answered."""
    pid = os.fork()
    if pid:
        os.waitpid(pid, 0)
        return
    os.setsid()
    if os.fork() == 0:
        try:
            output.close()
            job()
        finally:
            os._exit(0)
    os._exit(0)


def config_verdict(daemon):
    """What `nginx -t` said on both streams, or what stopped it from running at all."""
    said = Collected()
    try:
        WEB_SERVER.exec(daemon, said, NGINX_TEST)
    except (Failed, Unavailable) as e:
        said.stderr(f'{e}\n'.encode())
    return bytes(said.data)


def restart_after_config_test(daemon, output):
    """Only nginx's own verdict refuses the restart: a container that is down is what the restart is for."""
    said = config_verdict(daemon)
    if b'[emerg]' in said or b'test failed' in said:
        output.stderr(b'sites-http restart refused, nginx -t: ' + said.rstrip(b'\n') + b'\n')
        return 1
    WEB_SERVER.restart(daemon)
    return 0


def run(daemon, output, reload):
    if reload == REBIND:
        time.sleep(REBIND_SECONDS)
        return restart_after_config_test(daemon, output)
    return WEB_SERVER.exec(daemon, output, RELOADS[reload])


def job(daemon, output, reload):
    return JOB_FAILURES.run(output, run, daemon, output, reload)


def engine_file(context, file, reload):
    """The engine's own compose file, named as the gateway names it."""
    if file != posixpath.join(context.policy.roots['engine'], 'docker-compose.yml') or not SAFE.fullmatch(file):
        raise Refused(f"'{file}' is not the engine's compose file")
    return (reload,)


def queue_reload(daemon, output, reload):
    """Answers at once, then reloads the web server in `sites-http` through the host's Engine API."""
    detach(output, lambda: job(daemon, Printed(STDERR, STDERR), reload))
    return 0


ACTIONS = {
    'queueReload': hostapi.asking(reads(values.path, kind), queue_reload, engine_file, host_only=True),
}
