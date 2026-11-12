"""A stdlib Docker Engine API client on a unix socket, every reply read up to a size limit.

`Daemon` is one daemon's API, worded in its failures by whose daemon it is; its containers, images, volumes and
networks are small wrappers over it.
"""

import contextlib
import copy
import http.client
import json
import re
import socket
import urllib.parse
from dataclasses import dataclass

from . import relay, replies

SOCKET = '/var/run/docker.sock'
SECONDS = 5
EXEC_SECONDS = 3600
PULL_SECONDS = 900
LIMIT = 4 << 20
SHORT_REPLY_LIMIT = 1 << 16
PROGRESS_LINE_LIMIT = 1 << 16
MESSAGE_LIMIT = 4096
RECEIVE = 1 << 16
PROJECT = 'com.docker.compose.project'
SERVICE = 'com.docker.compose.service'
ONEOFF = 'com.docker.compose.oneoff'
WORKING_DIR = 'com.docker.compose.project.working_dir'
SYSBOX_RUNTIME = 'sysbox-runc'
CLIENT = object()
DOCKER_HUB = 'docker.io'
LIBRARY = 'library/'
LOCALHOST = 'localhost'
LATEST = 'latest'
STATUS_LINE = re.compile(rb'HTTP/[0-9.]+ ([1-9][0-9]{2})(?: .*)?', re.DOTALL)


class Connection(http.client.HTTPConnection):
    """HTTP to a daemon at a unix socket path, or on whatever socket a dialler callable returns."""

    def __init__(self, address, seconds):
        super().__init__('localhost', timeout=seconds)
        self.address = address

    def connect(self):
        dialled = callable(self.address)
        self.sock = self.address() if dialled else socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(self.timeout)
        if not dialled:
            self.sock.connect(self.address)


class TooLarge(ValueError):
    pass


class Unavailable(Exception):
    """The daemon, or the container it runs in, is not running; the message says which, in Docker's own words."""


class Failed(Exception):
    """The daemon answered with an error, or with something this module does not accept."""


def request(method, path, address=SOCKET, seconds=SECONDS, limit=LIMIT, body=None, content_type='application/json'):
    """`(status, body)`; a body over `limit` bytes raises TooLarge, so a daemon cannot fill the broker's memory."""
    conn = Connection(address, seconds)
    try:
        try:
            conn.request(method, path, body=body, headers={'Content-Type': content_type} if body is not None else {})
        except (BrokenPipeError, ConnectionResetError):
            if body is None:
                raise
        response = conn.getresponse()
        return response.status, read_body(response, limit)
    finally:
        conn.close()


def read_body(response, limit):
    body = response.read(limit + 1)
    if len(body) > limit:
        raise TooLarge(f'the reply is larger than {limit} bytes')
    return body


def one_line(message):
    """A daemon's message cut to MESSAGE_LIMIT, its whitespace runs one space each."""
    return ' '.join(message[:MESSAGE_LIMIT].split())


class Hijacked:
    """The raw stream a hijacking endpoint (`/exec/{id}/start`) leaves after its headers, or its error reply."""

    def __init__(self, sock, status, pending, length=None):
        self.sock = sock
        self.status = status
        self.pending = bytearray(pending)
        self.length = length

    @classmethod
    def open(cls, path, address, seconds, body):
        """POSTs `body` as JSON asking for the connection itself, and returns what the daemon left of it."""
        conn = Connection(address, seconds)
        conn.connect()
        sock = conn.sock
        payload = json.dumps(body).encode()
        try:
            sock.sendall(
                f'POST {path} HTTP/1.1\r\nHost: docker\r\nContent-Type: application/json\r\n'
                f'Content-Length: {len(payload)}\r\nConnection: Upgrade\r\nUpgrade: tcp\r\n\r\n'.encode() + payload
            )
            head = bytearray()
            while b'\r\n\r\n' not in head:
                data = sock.recv(RECEIVE)
                if not data or len(head) > LIMIT:
                    raise http.client.HTTPException('the daemon closed before its reply headers')
                head += data
            headers, _, rest = bytes(head).partition(b'\r\n\r\n')
            status_line, *fields = headers.split(b'\r\n')
            return cls(sock, status_code(status_line), rest, content_length(fields))
        except BaseException:
            sock.close()
            raise

    def refusal(self, limit):
        """The body of a reply that kept the connection a plain HTTP one: as long as it says, at most `limit` bytes,
        since the daemon keeps the connection open after it."""
        return self.read(limit if self.length is None else min(self.length, limit))

    def read(self, size):
        while len(self.pending) < size:
            data = self.sock.recv(RECEIVE)
            if not data:
                break
            self.pending += data
        taken = bytes(self.pending[:size])
        del self.pending[:size]
        return taken

    def send(self, data):
        """Writes `data` to the process's stdin, then closes it."""
        self.sock.sendall(data)
        self.sock.shutdown(socket.SHUT_WR)

    def close(self):
        self.sock.close()


def status_code(line):
    """The status a reply's first line gives; a line that is no HTTP status line is http.client's BadStatusLine."""
    found = STATUS_LINE.fullmatch(line)
    if found is None:
        raise http.client.BadStatusLine(one_line(line.decode('latin-1')))
    return int(found.group(1))


def content_length(fields):
    """A reply's Content-Length from its header lines, or None."""
    for field in fields:
        name, _, value = field.partition(b':')
        if name.strip().lower() == b'content-length' and value.strip().isdigit():
            return int(value)
    return None


@dataclass(frozen=True)
class Wording:
    """How a daemon's failures read, formatted with its `who` and `name`, and the `path`, `error` or `seconds`."""

    too_large: str = '{who} answered {path} with {error}'
    refused: str = '{who} is not running'
    silent: str = '{who} did not answer within {seconds}s'


class Daemon:
    """One daemon's Engine API at `address`: a unix socket path, or a dialler that returns a connected socket."""

    def __init__(self, name, address, seconds=SECONDS, wording=Wording()):
        self.name = name
        self.address = address
        self.seconds = seconds
        self.wording = wording

    @property
    def who(self):
        return f'the Docker daemon of {self.name}'

    def bounded(self, seconds):
        """This daemon with `seconds` as the bound of every request that names none."""
        bounded = copy.copy(self)
        bounded.seconds = seconds
        return bounded

    def call(self, method, path, limit=LIMIT, seconds=None, body=None, content_type='application/json'):
        """`(status, body)`, failing in this daemon's words when the reply is too large or does not come."""
        seconds = seconds or self.seconds
        try:
            return request(method, path, self.address, seconds, limit, body, content_type)
        except TooLarge as e:
            raise self.too_large(path, e) from e
        except (ConnectionRefusedError, FileNotFoundError) as e:
            raise Unavailable(self.worded(self.wording.refused)) from e
        except TimeoutError as e:
            raise Unavailable(self.worded(self.wording.silent, seconds=seconds)) from e

    def worded(self, text, **values):
        return text.format(who=self.who, name=self.name, **values)

    def too_large(self, path, error):
        return Failed(self.worded(self.wording.too_large, path=path.split('?')[0], error=error))

    def json(self, method, path, missing=(404,), limit=LIMIT, body=None, seconds=None):
        """The decoded reply, or None for a `missing` status; any other status is the daemon's error.

        A dict or list `body` is sent as JSON."""
        status, reply = self.call(method, path, limit, seconds, None if body is None else json.dumps(body).encode())
        if status in missing:
            return None
        if status // 100 != 2:
            raise Failed(daemon_error(status, reply))
        try:
            return json.loads(reply) if reply else None
        except (ValueError, RecursionError) as e:
            raise Failed(f'{self.who} answered {path.split("?")[0]} with no JSON') from e

    def expect(self, method, path, ok, limit=SHORT_REPLY_LIMIT):
        """The reply's body when its status is one of `ok`; any other status is the daemon's error."""
        status, body = self.call(method, path, limit)
        if status not in ok:
            raise Failed(daemon_error(status, body))
        return body

    def stream(self, method, path, seconds, headers=None, body=None):
        """A connection and its response, for a reply read as it arrives; the caller closes the connection."""
        conn = Connection(self.address, seconds)
        try:
            conn.request(method, path, body=body, headers=headers or {})
            return conn, conn.getresponse()
        except BaseException:
            conn.close()
            raise

    @contextlib.contextmanager
    def streaming(self, method, path, seconds, headers=None, body=None, silence=None):
        """The response of `stream`, closed however its reading ends; a status but 200 is the daemon's error.

        With `silence`, a reply that stops for `seconds` once it has begun is Unavailable in those words."""
        conn, response = self.stream(method, path, seconds, headers, body)
        try:
            if response.status != 200:
                raise self.stream_error(response, path)
            yield response
        except TimeoutError as e:
            if silence is None:
                raise
            raise Unavailable(silence) from e
        finally:
            conn.close()

    def stream_error(self, response, path):
        """The daemon's error for a streamed reply that is not a 200, its body read up to SHORT_REPLY_LIMIT."""
        try:
            return Failed(daemon_error(response.status, read_body(response, SHORT_REPLY_LIMIT)))
        except TooLarge as e:
            return self.too_large(path, e)

    def hijack(self, path, body, seconds):
        return Hijacked.open(path, self.address, seconds, body)

    def exec(self, output, container, argv, *, user='', workdir='', environment=(), stdin=None, seconds=EXEC_SECONDS):
        """`docker exec` of `argv` in `container`, its output relayed and its exit code returned; `stdin` is bytes or
        CLIENT for the client's own, `seconds` bounds the start and each silence of the output."""
        body = exec_body(argv, user, workdir, environment, stdin is not None)
        exec_id = replies.created(self.json('POST', f'/containers/{quoted(container)}/exec', missing=(), body=body))['Id']
        if not self.relayed_exec(exec_id, output, stdin, seconds):
            return 1
        exit_code = replies.exec_state(self.json('GET', f'/exec/{exec_id}/json', missing=())).get('ExitCode')
        return exit_code if isinstance(exit_code, int) else 1

    def relayed_exec(self, exec_id, output, stdin, seconds):
        """Starts the exec and relays its streams: True once they end, False once the client has hung up."""
        stream = self.hijack(f'/exec/{exec_id}/start', {'Detach': False, 'Tty': False}, seconds)
        try:
            if stream.status not in (101, 200):
                raise Failed(daemon_error(stream.status, stream.refusal(SHORT_REPLY_LIMIT)))
            if isinstance(stdin, bytes):
                stream.send(stdin)
            return relay.relay(stream, output, stdin is CLIENT, seconds)
        finally:
            stream.close()

    def pull(self, ref, headers=None):
        """`docker pull`, its progress read to its end; the progress's first error fails it."""
        silence = f'{self.who} sent nothing for {PULL_SECONDS}s while pulling {ref}'
        with self.images.pulling(ref, PULL_SECONDS, headers, silence) as response:
            failure = Progress(response, self.who, 'a pull progress line').first_error()
        if failure is not None:
            raise Failed(failure)

    def close(self):
        """Nothing is held between requests; a daemon reached through a descriptor closes it here."""

    containers = property(lambda self: Containers(self))
    images = property(lambda self: Images(self))
    volumes = property(lambda self: Volumes(self))
    networks = property(lambda self: Networks(self))


def exec_body(argv, user, workdir, environment, attach_stdin):
    """The body `POST /containers/{id}/exec` takes for one command."""
    return {'AttachStdout': True, 'AttachStderr': True, 'AttachStdin': attach_stdin, 'Tty': False, 'Cmd': argv,
            'Env': [f'{name}={value}' for name, value in environment],
            **({'User': user} if user else {}), **({'WorkingDir': workdir} if workdir else {})}


DAEMON_ERROR = 'Error response from daemon: '
NO_SUCH_IMAGE = DAEMON_ERROR + 'No such image: {}'


def daemon_error(status, body):
    """`Error response from daemon: <message>`, Docker's own wording, on one line and cut to a size."""
    try:
        message = json.loads(body).get('message')
    except (ValueError, AttributeError, RecursionError):
        message = None
    if not isinstance(message, str) or message == '':
        message = f'status {status}'
    return DAEMON_ERROR + one_line(message)


def quoted(value):
    return urllib.parse.quote(value, safe='')


def labels(container):
    return ((container or {}).get('Config') or {}).get('Labels') or {}


def compose_filters(project, service=None, running=True, one_offs=False):
    """The filters for the containers compose made for its project, or for one service of it, as compose labels them;
    its one-off `run` containers only with `one_offs`."""
    service_label = [f'{SERVICE}={service}'] if service is not None else []
    one_off_label = [] if one_offs else [f'{ONEOFF}=False']
    return {
        'label': [f'{PROJECT}={project}', *service_label, *one_off_label],
        **({'status': ['running']} if running else {}),
    }


@dataclass(frozen=True)
class ImageRef:
    """An image reference as the docker CLI reads it: `[registry/]path[:tag][@digest]`."""

    ref: str

    def repository_and_tag(self):
        """`repo:tag`, a tag after the last slash, `latest` without one."""
        slash, colon = self.ref.rfind('/'), self.ref.rfind(':')
        return (self.ref[:colon], self.ref[colon + 1:]) if colon > slash else (self.ref, LATEST)

    def pull_query(self):
        """`fromImage` without its tag, and the digest or else the tag separately, as the docker CLI asks."""
        name, _, digest = self.ref.partition('@')
        repository, tag = ImageRef(name).repository_and_tag()
        return {'fromImage': repository, 'tag': digest or tag}

    def cli_name(self):
        """The reference as the CLI names it once pulled: its registry, the library path and a tag always there."""
        name, _, digest = self.ref.partition('@')
        registry, slash, path = name.partition('/')
        if not slash:
            registry, path = DOCKER_HUB, LIBRARY + name
        elif '.' not in registry and ':' not in registry and registry != LOCALHOST:
            registry, path = DOCKER_HUB, name
        tagged = path if ':' in path.rpartition('/')[2] else f'{path}:{LATEST}'
        return f'{registry}/{tagged}' + (f'@{digest}' if digest else '')


@dataclass(frozen=True)
class Progress:
    """A daemon's JSON progress stream, an event a line read up to PROGRESS_LINE_LIMIT bytes; `who` and `kind` word a
    line it cannot take."""

    response: object
    who: str
    kind: str

    def __iter__(self):
        while True:
            raw = self.response.readline(PROGRESS_LINE_LIMIT + 1)
            if not raw:
                return
            if len(raw) > PROGRESS_LINE_LIMIT:
                raise Failed(f'{self.who} sent {self.kind} over {PROGRESS_LINE_LIMIT} bytes')
            try:
                yield json.loads(raw)
            except (ValueError, RecursionError) as e:
                raise Failed(f'{self.who} sent {self.kind} that is no JSON') from e

    def first_error(self):
        """The stream's first error, read to its end, on one line."""
        failure = None
        for event in self:
            message = progress_error(event)
            if failure is None and message is not None:
                failure = one_line(message)
        return failure


def progress_error(event):
    """A progress event's `error`, None when it holds none."""
    message = event.get('error') if isinstance(event, dict) else None
    return message if isinstance(message, str) else None


class Objects:
    """One kind of a daemon's objects, each at `ROOT/<name>`: inspected at INSPECT below that, removed there."""

    ROOT = ''
    INSPECT = '/json'
    REMOVED = (200,)
    REMOVAL_LIMIT = LIMIT

    def __init__(self, daemon):
        self.daemon = daemon

    def path(self, name):
        return f'{self.ROOT}/{quoted(name)}'

    def inspect(self, name, shape, missing=(404,), part=None):
        """The reply for `name`, or its `part`, as `shape` keeps it; None for a `missing` status, or for no reply at all
        while `missing` names one."""
        found = self.daemon.json('GET', self.path(name) + (self.INSPECT if part is None else part), missing)
        return None if found is None and missing else shape(found)

    def inspected(self, name, shape, wording, part=None):
        """`inspect`, failing in `wording` of the name when the daemon has no such object."""
        found = self.inspect(name, shape, part=part)
        if found is None:
            raise Failed(wording.format(name))
        return found

    def each(self, names, shape):
        """`inspect` of every name, None for each one the daemon does not have."""
        return [self.inspect(name, shape) for name in names]

    def each_line(self, output, names, shape, wording, line):
        """`line` of each object on stdout; one the daemon lacks is `wording` on stderr and fails the whole."""
        code = 0
        for name in names:
            found = self.inspect(name, shape)
            if found is None:
                output.stderr(f'{wording.format(name)}\n'.encode())
                code = 1
            else:
                output.stdout(line(found))
        return code

    def delete(self, name, query='', limit=None):
        """`(status, body)` of the object's removal, its reply read up to `limit` or else REMOVAL_LIMIT."""
        return self.daemon.call('DELETE', self.path(name) + (f'?{query}' if query else ''), limit or self.REMOVAL_LIMIT)

    def remove_each(self, output, names, query, forgiving, told=None, tells_missing=False):
        """Tries every name as `docker rm` does: a refusal is told and fails the whole, a missing one too unless
        `forgiving`, then untold unless `tells_missing`; `told(name, body)` is what a removal prints."""
        code = 0
        for name in names:
            status, body = self.delete(name, query)
            if status in self.REMOVED:
                if told:
                    output.stdout(told(name, body))
                continue
            forgiven = forgiving and status == 404
            if tells_missing or not forgiven:
                output.stderr(f'{daemon_error(status, body)}\n'.encode(errors='replace'))
            if not forgiven:
                code = 1
        return code


class Containers(Objects):
    ROOT = '/containers'
    REMOVED = (200, 204)
    REMOVAL_LIMIT = SHORT_REPLY_LIMIT

    def listed(self, query, shape, missing=(404,)):
        """`docker ps` with `query`, as `shape` keeps it."""
        return shape(self.daemon.json('GET', f'{self.ROOT}/json?{urllib.parse.urlencode(query)}', missing))

    def create(self, body, name='', missing=(), limit=LIMIT):
        """`docker create` of `body`, under `name` when given: the container's id; None for a `missing` status, or for
        no reply at all while `missing` names one."""
        query = f'?{urllib.parse.urlencode({"name": name})}' if name else ''
        found = self.daemon.json('POST', f'{self.ROOT}/create{query}', missing, limit, body)
        return None if found is None and missing else replies.created(found)['Id']

    def wait(self, name, seconds, limit=LIMIT, condition=''):
        """`docker wait`'s checked reply, once the container has stopped or `condition` holds."""
        query = f'?{urllib.parse.urlencode({"condition": condition})}' if condition else ''
        return replies.waited(self.daemon.json('POST', f'{self.path(name)}/wait{query}', (), limit, seconds=seconds))


class Images(Objects):
    ROOT = '/images'

    def tag(self, source, target):
        """`docker tag`: the target's repository and tag apart."""
        repository, tag = ImageRef(target).repository_and_tag()
        query = urllib.parse.urlencode({'repo': repository, 'tag': tag})
        self.daemon.expect('POST', f'{self.path(source)}/tag?{query}', (201,))

    def pulling(self, ref, seconds, headers=None, silence=None):
        """The streamed reply of `docker pull`: the daemon's progress."""
        query = urllib.parse.urlencode(ImageRef(ref).pull_query())
        return self.daemon.streaming('POST', f'{self.ROOT}/create?{query}', seconds, headers, silence=silence)

    def pushing(self, ref, seconds, headers):
        """The streamed reply of `docker push`: the daemon's progress."""
        repository, tag = ImageRef(ref).repository_and_tag()
        query = urllib.parse.urlencode({'tag': tag})
        return self.daemon.streaming('POST', f'{self.path(repository)}/push?{query}', seconds, headers)


class Volumes(Objects):
    ROOT = '/volumes'
    INSPECT = ''


class Networks(Objects):
    ROOT = '/networks'
    INSPECT = ''
