"""The `docker` area's containers run to completion on the host's daemon, through its Engine API.

A run is created, attached to before it starts, relayed and waited for, and its container removed whatever happens. A
refusal is printed in the docker CLI's words with its exit code, 125.
"""

import contextlib
import fcntl
import http.client
import os
from dataclasses import dataclass

from .. import relay
from ..dockerapi import NO_SUCH_IMAGE, SHORT_REPLY_LIMIT, Failed, Unavailable, daemon_error

PULL_NEVER = 'never'
RUN_HELP = "Run 'docker run --help' for more information"
MEMORY_UNITS = {'b': 1, 'k': 1 << 10, 'm': 1 << 20, 'g': 1 << 30}
SECURITY_OPT = ('no-new-privileges',)
CAP_DROP = ('ALL',)
OOM_SCORE_ADJ = 1000
PIDS_LIMIT = 512
RUN_SECONDS = relay.SECONDS
ATTACHED = {'AttachStdin': False, 'AttachStdout': True, 'AttachStderr': True, 'Tty': False, 'OpenStdin': False}
HOST_OPTIONS = (('network', 'NetworkMode'), ('runtime', 'Runtime'), ('security_opt', 'SecurityOpt'),
                ('cap_drop', 'CapDrop'), ('memory', 'Memory'), ('memory_swap', 'MemorySwap'),
                ('oom_score_adj', 'OomScoreAdj'), ('pids_limit', 'PidsLimit'))
OPTIONS = (('entrypoint', 'Entrypoint'), ('user', 'User'), ('workdir', 'WorkingDir'))


class RefusedRun(Exception):
    """The daemon refused to make or start the container, or the image could not be pulled for it."""


@dataclass(frozen=True)
class Spec:
    """One `docker run --rm`, its fields named after the CLI's options."""

    image: str
    cmd: tuple
    entrypoint: tuple = ()
    user: str = ''
    workdir: str = ''
    environment: tuple = ()
    labels: tuple = ()
    binds: tuple = ()
    network: str = ''
    runtime: str = ''
    memory: int = 0
    memory_swap: int = 0
    oom_score_adj: int = 0
    pids_limit: int = 0
    security_opt: tuple = ()
    cap_drop: tuple = ()
    pull: str = 'missing'
    lock: str = ''

    @classmethod
    def hardened(cls, memory, **fields):
        """A host build container: RootDocker's hardening and nothing a request could add."""
        limit = memory_bytes(memory)
        return cls(**fields, memory=limit, memory_swap=limit, oom_score_adj=OOM_SCORE_ADJ, pids_limit=PIDS_LIMIT,
                   security_opt=SECURITY_OPT, cap_drop=CAP_DROP)

    def body(self):
        """The body `POST /containers/create` takes for this run: every option it sets, under the API's name."""
        host = {'AutoRemove': False, 'Binds': list(self.binds), **self.options(HOST_OPTIONS)}
        environment = {'Env': [f'{name}={value}' for name, value in self.environment]} if self.environment else {}
        return {'Image': self.image, 'Cmd': list(self.cmd), 'Labels': dict(self.labels), **ATTACHED, 'HostConfig': host,
                **self.options(OPTIONS), **environment}

    def options(self, names):
        return {key: getattr(self, name) for name, key in names if getattr(self, name)}


def memory_bytes(value):
    """The daemon's bytes for a limit the CLI takes as `512m`, `2048m` or `2g`."""
    unit = value[-1].lower()
    if unit in MEMORY_UNITS:
        return int(value[:-1]) * MEMORY_UNITS[unit]
    return int(value)


def run_container(daemon, output, spec):
    """`docker run --rm …` as the API makes it: the run's output, the container's exit code and always its removal."""
    return HostRun(daemon, output, spec).run()


@dataclass(frozen=True)
class HostRun:
    """One `spec` run on the host's daemon, its output relayed to `output` as it comes."""

    daemon: object
    output: object
    spec: Spec

    def run(self):
        with held(self.spec.lock):
            try:
                container = self.created()
                try:
                    return self.attended(container)
                finally:
                    self.remove(container)
            except RefusedRun as e:
                self.output.stderr(f'docker: {e}\n\n{RUN_HELP}\n'.encode())
                return 125

    def created(self):
        """The created container's id; a missing image is pulled first, as `docker run` does."""
        try:
            return self.create()
        except Failed as e:
            if not str(e).startswith(NO_SUCH_IMAGE.format('')) or self.spec.pull == PULL_NEVER:
                raise RefusedRun(str(e)) from e
        except Unavailable as e:
            raise RefusedRun(str(e)) from e
        self.output.stderr(f"Unable to find image '{self.spec.image}' locally\n".encode())
        try:
            self.daemon.pull(self.spec.image)
            return self.create()
        except (Failed, Unavailable) as e:
            raise RefusedRun(str(e)) from e

    def create(self):
        return self.daemon.containers.create(self.spec.body(), limit=SHORT_REPLY_LIMIT)

    def attended(self, container):
        """Attaches before the start, relays the output as it comes and reads the exit code once it has ended."""
        path = self.daemon.containers.path(container)
        stream = self.daemon.hijack(f'{path}/attach?stream=1&stdout=1&stderr=1', {'Detach': False, 'Tty': False},
                                    self.daemon.seconds)
        try:
            if stream.status not in (101, 200):
                raise RefusedRun(daemon_error(stream.status, stream.refusal(SHORT_REPLY_LIMIT)))
            self.started(path)
            if not relay.relay(stream, self.output, seconds=RUN_SECONDS):
                return 1
        except TimeoutError as e:
            raise Unavailable(f"the host's Docker sent nothing for {RUN_SECONDS}s") from e
        finally:
            stream.close()
        return self.daemon.containers.wait(container, RUN_SECONDS, SHORT_REPLY_LIMIT)['StatusCode']

    def started(self, path):
        try:
            status, answer = self.daemon.call('POST', f'{path}/start', limit=SHORT_REPLY_LIMIT)
        except Unavailable as e:
            raise RefusedRun(str(e)) from e
        if status not in (204, 304):
            raise RefusedRun(daemon_error(status, answer))

    def remove(self, container):
        """The `--rm` the daemon does not do: a container left behind is a container a cancelled build keeps running."""
        try:
            self.daemon.containers.delete(container, 'force=1')
        except (Failed, Unavailable, OSError, http.client.HTTPException):
            pass


@contextlib.contextmanager
def held(path):
    """A shared lock of `path`, made when it is missing, held while the block runs; nothing without a path."""
    if path == '':
        yield
        return
    try:
        fd = os.open(path, os.O_RDONLY | os.O_CREAT | os.O_CLOEXEC, 0o644)
    except OSError as e:
        raise Failed(f'cannot open {path}: {e.strerror}') from e
    try:
        fcntl.flock(fd, fcntl.LOCK_SH)
        yield
    finally:
        os.close(fd)
