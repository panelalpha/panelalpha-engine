"""`account-docker` actions that exec, run, copy files, read socket tables and remove containers in an account's daemon.

Mounts are named volumes or paths in the account's home, resolved by the daemon in the account's filesystem. A file
copied in is one of the engine's, never a path a request names.
"""

import os
import posixpath
import urllib.parse
import uuid

from .. import innerprocesses, relay, replies, tarballs, values
from ..dockerapi import EXEC_SECONDS, LIMIT, Failed
from ..refused import Refused
from .accountdocker import asking
from .builders import script
from .sequence import flag, group, reads, rest, step

RUN_SECONDS = 3600
LABEL = 'com.panelalpha.engine'
BIND_MODES = ('ro', 'rw')
ENGINE_FILES = {'wp-cli': 'templates/user/dind/project/wp-cli/wp-cli.phar'}
ENGINE_FILE_LIMIT = 64 << 20
ARCHIVE_LIMIT = tarballs.FILE_LIMIT + (1 << 16)
WRITE_STDIN = 'cat > "$1.$$" && chmod 0644 "$1.$$" && mv -f "$1.$$" "$1"'


def absolute(value):
    if not values.path(value).startswith('/') or '..' in value.split('/') or ':' in value:
        values.refuse('absolute path', value)
    return value


bind_mode = values.one_of(BIND_MODES, 'mount mode')


def in_home(account, source):
    """A named volume, or a path in the account's own home."""
    if not source.startswith('/'):
        return values.name(source)
    home = account.home.rstrip('/') + '/'
    if not absolute(source).startswith(home):
        raise Refused(f"'{source}' is not in the home of {account.name}")
    return source


def run_binds(context, account, image, binds, argv):
    return image, [f'{in_home(account, source)}:{target}:{mode}' for source, target, mode in binds], argv


def engine_file(context, account, container, key, target):
    """The engine's own file under its key, in the engine directory, which only root writes."""
    if key not in ENGINE_FILES:
        values.refuse('engine file', key)
    return container, posixpath.join(context.policy.roots['engine'], ENGINE_FILES[key]), target


def read_engine_file(path):
    with open(path, 'rb', opener=lambda name, flags: os.open(name, flags | os.O_NOFOLLOW | os.O_CLOEXEC)) as f:
        data = f.read(ENGINE_FILE_LIMIT + 1)
    if len(data) > ENGINE_FILE_LIMIT:
        raise Failed(f'{path} is larger than {ENGINE_FILE_LIMIT} bytes')
    return data


def exec_in(daemon, output, container, user, workdir, environment, argv, stdin=None, seconds=EXEC_SECONDS):
    """`docker exec [-i] [-u] [-w] [-e]… <container> <argv>`; the exit code is the command's.

    The daemon's `exec` with the action's values in their order; `stdin` is bytes, or CLIENT for the client's own."""
    return daemon.exec(output, container, argv, user=user, workdir=workdir, environment=environment, stdin=stdin,
                       seconds=seconds)


def create(daemon, body, image):
    """Creates the container, pulling a missing image first as `docker run` does."""
    created = daemon.containers.create(body, missing=(404,))
    if created is None:
        daemon.pull(image)
    return created or daemon.containers.create(body)


def remove_quietly(daemon, container):
    try:
        daemon.containers.delete(container, 'force=1&v=1', LIMIT)
    except (Failed, OSError):
        pass


def run_once(daemon, output, image, binds, argv):
    """`docker run --rm -v … <image> <argv>`: its output once it exits, its exit code as the action's."""
    container = create(daemon, {'Image': image, 'Cmd': argv, 'Labels': {LABEL: 'run'}, 'HostConfig': {'Binds': binds}},
                       image)
    try:
        daemon.expect('POST', f'/containers/{container}/start', (204, 304))
        waited = daemon.containers.wait(container, RUN_SECONDS, condition='not-running')
        conn, response = daemon.stream('GET', f'/containers/{container}/logs?stdout=1&stderr=1', RUN_SECONDS)
        try:
            if response.status == 200:
                relay.written(output, relay.Frames().pulled(response.read))
        finally:
            conn.close()
    finally:
        remove_quietly(daemon, container)
    return waited['StatusCode']


def put_file(daemon, output, container, source, target):
    """Writes the engine file `source` as `target` in the container, mode 0644, as its default user.

    Through `cat` on stdin, since a sysbox account's daemon cannot extract an archive into a running container."""
    return daemon.exec(output, container, script(WRITE_STDIN)(target), stdin=read_engine_file(source))


def read_image_file(daemon, output, image, path):
    """`path` of `image`, read from a created, never started, container."""
    body = {'Image': image, 'Entrypoint': ['/pa-none'], 'Cmd': [], 'NetworkDisabled': True, 'Labels': {LABEL: 'read'}}
    container = daemon.containers.create(body, name=f'pa-read-{uuid.uuid4().hex[:12]}')
    try:
        archive = daemon.expect('GET', f'/containers/{container}/archive?{urllib.parse.urlencode({"path": path})}', (200,),
                                ARCHIVE_LIMIT)
        try:
            output.stdout(tarballs.first_file(archive))
        except tarballs.NotAFile as e:
            raise Failed(f'{path} in {image}: {e}') from e
    finally:
        remove_quietly(daemon, container)
    return 0


def listening_sockets(daemon, output, container):
    """The container's `net/tcp` and `net/tcp6`, read by the host from its process; nothing when it does not run."""
    found = daemon.containers.inspect(container, lambda reply: replies.container(reply or {}))
    state = (found or {}).get('State') or {}
    pid = state.get('Pid')
    if state.get('Running') is not True or not isinstance(pid, int) or pid <= 0 or daemon.init_pid is None:
        return 0
    process = innerprocesses.host_process(daemon.proc, daemon.init_pid, pid)
    if process is None:
        return 0
    try:
        output.stdout(innerprocesses.socket_tables(process))
    finally:
        os.close(process)
    return 0


def remove_containers(daemon, output, force, volumes, containers):
    """Every container is tried, as `docker rm` does; with force a missing one is no failure."""
    return daemon.containers.remove_each(output, containers, f'force={int(force)}&v={int(volumes)}', forgiving=force)


def command(given):
    """Every value left, the argv to run: at least one."""
    argv = given.rest(values.text)
    if not argv:
        raise Refused('nothing to run')
    return argv


read_exec_values = reads(
    values.optional(values.owner), values.optional(absolute), group(values.environment_name, values.text), step(command),
)
read_run = reads(values.image, group(values.operand, absolute, bind_mode), step(command))


ACTIONS = {
    'exec': asking(reads(values.name, read_exec_values), exec_in),
    'run': asking(read_run, run_once, run_binds),
    'putEngineFile': asking(reads(values.name, values.name, absolute), put_file, engine_file),
    'readImageFile': asking(reads(values.image, absolute), read_image_file),
    'listeningSockets': asking(reads(values.name), listening_sockets),
    'removeContainers': asking(reads(flag('force'), flag('volumes'), rest(values.name)), remove_containers),
}
