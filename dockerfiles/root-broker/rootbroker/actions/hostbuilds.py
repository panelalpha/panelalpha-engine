"""The `docker` area's image builds and manifest reads: the host's Engine API and the registry's own v2 API.

A build's context is a tar the broker makes of the client's Dockerfile or an engine directory, its trace printed as
`--progress=plain`. A manifest is asked of the registry, since the daemon knows only what it has pulled.
"""

import base64
import fnmatch
import hashlib
import http.client
import io
import json
import os
import posixpath
import re
import stat
import tarfile
import urllib.parse
from dataclasses import dataclass

from .. import cli, dockerapi, paths, values
from ..dockerapi import Failed, ImageRef, Unavailable
from ..refused import Refused
from .failures import Failures
from .sequence import Command

DOCKERFILE_LIMIT = 1 << 20
CONTEXT_LIMIT = 64 << 20
DOCKERFILE = 'Dockerfile'
DOCKERIGNORE = '.dockerignore'
IGNORE_LIMIT = 1 << 20
REGISTRY_CONFIG = 'e30='
BUILD_SECONDS = 3600
LINE_LIMIT = 4 << 20
MESSAGE_LIMIT = 1 << 16
TRACE = 'moby.buildkit.trace'
REGISTRY_SECONDS = 15
REGISTRY_LIMIT = 4 << 20
TOKEN_LIMIT = 1 << 16
PLATFORMS = 64
HUB = 'registry-1.docker.io'
ALIASES = {'docker.io': HUB, 'index.docker.io': HUB}
LOOPBACK = ('localhost', '::1')
DIGEST = re.compile(values.IMAGE_DIGEST)
CHALLENGE = re.compile(r'(\w+)="([^"]*)"')
MANIFEST_TYPES = (
    'application/vnd.oci.image.index.v1+json, '
    'application/vnd.docker.distribution.manifest.list.v2+json, '
    'application/vnd.oci.image.manifest.v1+json, '
    'application/vnd.docker.distribution.manifest.v2+json'
)
REGISTRY_FAILURES = Failures(
    (Failed, '{e}', 1),
    ((OSError, ValueError, http.client.HTTPException), 'could not reach the registry of {image}: {e}', 1),
)


def inspect_manifest(read):
    """The `docker.inspectManifest` action: what `docker manifest inspect --verbose` asked the registry for."""

    def build(context, image):
        return REGISTRY_FAILURES.writing(read_manifest, image, image=image)

    return Command(read, build, native=True)


def stdin_build(daemon, output, tag, pull, no_cache, build_args):
    """`docker build -`: the Dockerfile from the client's stdin as the whole context, BuildKit's progress printed."""
    dockerfile = output.channel.read_stdin(DOCKERFILE_LIMIT)
    return Build(tag, pull, no_cache, build_args).run(daemon, output, dockerfile_tar(dockerfile))


def quiet_context(context, tag, path):
    """The tag and the engine directory checked as a build's context, with the policy that opens it."""
    check_context(context.policy, path)
    return tag, path, context.policy


def quiet_build(daemon, output, tag, path, policy):
    """`docker build -q` of an engine directory: the directory sent as the context, the image ID printed."""
    fd = context_directory(policy, path)
    try:
        body = ContextArchive(fd, path).tar()
    finally:
        os.close(fd)
    return Build(tag, quiet=True).run(daemon, output, body)


@dataclass(frozen=True)
class Build:
    """One `docker build -t <tag>` on the host's daemon, from a context tar."""

    tag: str
    pull: bool = False
    no_cache: bool = False
    build_args: tuple = ()
    quiet: bool = False

    def run(self, daemon, output, body):
        """Builds from `body` as the context tar, prints the build's progress, and returns the exit code."""
        watching = Watching(output, self.quiet)
        path = '/build?' + self.query()
        headers = {'Content-Type': 'application/x-tar', 'X-Registry-Config': REGISTRY_CONFIG}
        try:
            with daemon.streaming('POST', path, BUILD_SECONDS, headers, body) as response:
                image_id, failure = read_messages(response, watching)
        except TimeoutError as e:
            raise Unavailable(f"the host's Docker sent nothing for {BUILD_SECONDS}s while building {self.tag}") from e
        finally:
            watching.finish()
        failure = watching.failure if failure is None else failure
        if failure is not None:
            output.stderr(f'ERROR: failed to build: {failure.rstrip()}\n'.encode())
            return 1
        if self.quiet:
            output.stdout(f'{image_id or built_image(daemon, self.tag)}\n'.encode())
        return 0

    def query(self):
        options = {'pull': self.pull and '1', 'nocache': self.no_cache and '1',
                   'buildargs': self.build_args and json.dumps(dict(self.build_args)), 'q': self.quiet and '1'}
        return urllib.parse.urlencode({'version': '2', 't': self.tag, **{k: v for k, v in options.items() if v}})


def read_messages(response, watching):
    """The image id and the error the build's reply holds, printing its progress as it arrives."""
    image_id, failure = None, None
    while line := response.readline(LINE_LIMIT + 1):
        if len(line) > LINE_LIMIT:
            raise Failed(f"the host's Docker sent a build message over {LINE_LIMIT} bytes")
        if not line.strip():
            continue
        message = decode_message(line)
        aux = message.get('aux')
        if message.get('id') == TRACE and isinstance(aux, str):
            watching.trace(aux)
        elif isinstance(aux, dict) and isinstance(aux.get('ID'), str):
            image_id = aux['ID']
        elif isinstance(message.get('stream'), str):
            watching.stream(message['stream'])
        if isinstance(message.get('error'), str):
            failure = message['error']
        elif isinstance(message.get('errorDetail'), dict) and isinstance(message['errorDetail'].get('message'), str):
            failure = message['errorDetail']['message']
    return image_id, failure


def decode_message(line):
    try:
        message = json.loads(line)
    except (ValueError, RecursionError) as e:
        raise Failed("the host's Docker sent a build message that is no JSON") from e
    if not isinstance(message, dict):
        raise Failed("the host's Docker sent a build message that is no object")
    return message


def built_image(daemon, tag):
    return daemon.images.inspected(tag, image_id, 'No such image: {}')


def image_id(reply):
    found = reply.get('Id') if isinstance(reply, dict) else None
    return found if isinstance(found, str) else None


def dockerfile_tar(dockerfile):
    """One tar holding the Dockerfile alone, which is a build's whole context."""
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode='w') as archive:
        add_content(archive, DOCKERFILE, 0o644, dockerfile)
    return buffer.getvalue()


class Watching:
    """`docker build --progress=plain` printed from the build's traces."""

    def __init__(self, output, quiet=False):
        self.output = output
        self.quiet = quiet
        self.numbers = {}
        self.names = set()
        self.starts = {}
        self.buffers = {}
        self.elapsed = {}
        self.failure = None

    def trace(self, payload):
        if self.quiet:
            return
        try:
            message = status(base64.b64decode(payload, validate=True))
        except ValueError as e:
            raise Failed("the host's Docker sent a build trace this broker cannot read") from e
        for vertex in message['vertexes']:
            self.vertex(vertex)
        for log in message['logs']:
            self.log(log)

    def number(self, digest):
        return self.numbers.setdefault(digest, len(self.numbers) + 1)

    def vertex(self, vertex):
        number = self.number(vertex.get('digest', ''))
        if 'started' in vertex and number not in self.names:
            self.names.add(number)
            self.starts[number] = vertex['started']
            self.line(f'#{number} {vertex["name"]}' if vertex.get('name') else f'#{number}')
        error = vertex.get('error', '')
        if error:
            self.failure = error
            self.flush(number)
            self.line(f'#{number} ERROR: {error}')
        if 'completed' not in vertex:
            return
        self.flush(number)
        if vertex.get('cached'):
            self.line(f'#{number} CACHED')
        elif not error:
            self.line(f'#{number} DONE {vertex["completed"] - self.starts.get(number, vertex["completed"]):.1f}s')

    def log(self, log):
        number = self.number(log.get('vertex', ''))
        stamp, start = log.get('timestamp'), self.starts.get(number)
        if stamp is not None and start is not None:
            self.elapsed[number] = stamp - start
        self.buffers[number] = self.buffers.get(number, b'') + log['msg']
        self.lines(number)

    def lines(self, number):
        while b'\n' in self.buffers.get(number, b''):
            content, self.buffers[number] = self.buffers[number].split(b'\n', 1)
            self.prefixed(number, content)

    def flush(self, number):
        if self.buffers.get(number):
            self.prefixed(number, self.buffers[number])
            self.buffers[number] = b''

    def finish(self):
        for number in sorted(self.buffers):
            self.flush(number)

    def prefixed(self, number, content):
        self.output.stderr(b'#%d %.3f %s\n' % (number, self.elapsed.get(number, 0.0), content))

    def line(self, text):
        self.output.stderr((text + '\n').encode())

    def stream(self, text):
        if not self.quiet:
            self.output.stderr(text.encode())


def status(payload):
    """The vertexes and logs of a BuildKit `StatusResponse`."""
    return protobuf(payload, STATUS, {'vertexes': [], 'logs': []})


def vertex(payload):
    return protobuf(payload, VERTEX, {})


def log(payload):
    """A VertexLog's vertex, timestamp and message; the timestamp's field number has varied, its wire type has not."""
    return protobuf(payload, LOG, {'msg': b''})


def moment(payload):
    found = dict(fields(payload))
    return found.get(1, 0) + found.get(2, 0) / 1e9


def timestamp(value):
    """A timestamp in its length-delimited wire type; None for a varint."""
    return moment(value) if isinstance(value, bytes) else None


def protobuf(payload, table, found):
    """`found` with the fields of a protobuf message that `table` names `(name, read)`: `read(value)` under its name,
    appended where `found` holds a list there; a read that gives None sets nothing."""
    for number, value in fields(payload):
        if number not in table:
            continue
        name, read = table[number]
        taken = read(value)
        if isinstance(found.get(name), list):
            found[name].append(taken)
        elif taken is not None:
            found[name] = taken
    return found


def fields(payload):
    """`(number, value)` for each field of a protobuf message, a length-delimited one as bytes."""
    at = 0
    while at < len(payload):
        key, at = varint(payload, at)
        number, wire = key >> 3, key & 7
        if number == 0:
            raise ValueError('field 0')
        if wire == 0:
            value, at = varint(payload, at)
        elif wire == 2:
            size, at = varint(payload, at)
            value, at = payload[at:at + size], at + size
        elif wire == 1:
            value, at = payload[at:at + 8], at + 8
        elif wire == 5:
            value, at = payload[at:at + 4], at + 4
        else:
            raise ValueError(f'wire type {wire}')
        if at > len(payload):
            raise ValueError('truncated message')
        yield number, value


def varint(payload, at):
    value, shift = 0, 0
    while at < len(payload):
        byte = payload[at]
        at += 1
        value |= (byte & 0x7f) << shift
        if byte < 0x80:
            return value, at
        shift += 7
        if shift > 63:
            raise ValueError('varint too long')
    raise ValueError('truncated varint')


def text(value):
    return bytes(value).decode('utf-8', 'replace')


STATUS = {1: ('vertexes', vertex), 3: ('logs', log)}
VERTEX = {1: ('digest', text), 3: ('name', text), 4: ('cached', lambda value: value != 0), 5: ('started', moment),
          6: ('completed', moment), 7: ('error', text)}
LOG = {1: ('vertex', text), 2: ('timestamp', timestamp), 3: ('timestamp', timestamp), 4: ('msg', bytes)}


def check_context(policy, path):
    """A build context opened right now and closed again: it must be an engine directory with no link followed."""
    os.close(context_directory(policy, path))


def context_directory(policy, path):
    """The engine directory `path` names, opened a component at a time with no link followed."""
    root = policy.roots['engine']
    paths.root_of(path, [root])
    with paths.Walk(os.open(root, paths.DIRECTORY), root.rstrip('/')) as walk, walk.refusing():
        return walk.down(paths.below(root, path)).taken()


class ContextArchive:
    """A directory as a build context's tar: its `.dockerignore` honoured, no link out of it followed and no more than
    CONTEXT_LIMIT bytes of its files taken."""

    def __init__(self, fd, path):
        self.fd = fd
        self.root = os.path.realpath(path)
        self.rules = ignore_rules(fd)
        self.size = 0

    def tar(self):
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode='w') as archive:
            self.walk(archive, self.fd, '')
        return buffer.getvalue()

    def walk(self, archive, fd, prefix):
        with os.scandir(fd) as found:
            for entry in sorted(found, key=lambda one: one.name):
                self.take(archive, fd, entry, prefix + entry.name)

    def take(self, archive, fd, entry, name):
        status = None if entry.is_symlink() else entry.stat(follow_symlinks=False)
        if status is not None and not stat.S_ISDIR(status.st_mode) and not stat.S_ISREG(status.st_mode):
            raise Refused(f"'{name}' is not a file, a directory or a link")
        if ignored(self.rules, name):
            return
        if status is None:
            self.add_link(archive, fd, entry, name)
        elif stat.S_ISDIR(status.st_mode):
            add_directory(archive, name, status)
            self.add_children(archive, fd, entry.name, name)
        else:
            self.add_file(archive, fd, entry, name, status)

    def add_children(self, archive, fd, directory, name):
        child = unlinked(fd, directory, os.O_DIRECTORY, f"'{name}' is a symlink or not a directory")
        try:
            self.walk(archive, child, name + '/')
        finally:
            os.close(child)

    def add_file(self, archive, fd, entry, name, status):
        remaining = CONTEXT_LIMIT - self.size
        with os.fdopen(unlinked(fd, entry.name, 0, f"'{name}' is a symlink"), 'rb') as file:
            content = file.read(remaining + 1)
        if len(content) > remaining:
            raise Refused(f'the build context is larger than {CONTEXT_LIMIT} bytes')
        self.size += len(content)
        add_content(archive, name, stat.S_IMODE(status.st_mode), content)

    def add_link(self, archive, fd, entry, name):
        target = os.readlink(entry.name, dir_fd=fd)
        if leaves(self.root, name, target):
            raise Refused(f"'{name}' is a symlink to {target}, outside the build context")
        link = tarfile.TarInfo(name)
        link.type = tarfile.SYMTYPE
        link.linkname = target
        link.mode = 0o777
        archive.addfile(link)


def add_directory(archive, name, status):
    entry = tarfile.TarInfo(name + '/')
    entry.type = tarfile.DIRTYPE
    entry.mode = stat.S_IMODE(status.st_mode)
    archive.addfile(entry)


def unlinked(fd, name, flags, refusal):
    """`name` in the directory `fd`, opened for reading with `flags` and never through a link, which is `refusal`."""
    try:
        return os.open(name, os.O_RDONLY | flags | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=fd)
    except OSError as e:
        if e.errno not in paths.LINKED:
            raise
        raise Refused(refusal) from e


def add_content(archive, name, mode, content):
    entry = tarfile.TarInfo(name)
    entry.mode = mode
    entry.size = len(content)
    archive.addfile(entry, io.BytesIO(content))


def leaves(root, name, target):
    """Whether a link at `name` points, however indirectly, outside `root`."""
    resolved = os.path.realpath(os.path.join(os.path.dirname(os.path.join(root, name)), target))
    return os.path.commonpath((root, resolved)) != root


def ignore_rules(fd):
    """The context's `.dockerignore` as `(pattern, negated)` pairs, or none."""
    try:
        handle = os.open(DOCKERIGNORE, paths.READ, dir_fd=fd)
    except OSError:
        return ()
    with os.fdopen(handle, 'rb') as file:
        content = file.read(IGNORE_LIMIT + 1)
    if len(content) > IGNORE_LIMIT:
        raise Refused(f'{DOCKERIGNORE} is larger than {IGNORE_LIMIT} bytes')
    rules = []
    for line in content.decode('utf-8', 'replace').splitlines():
        line = line.strip()
        if line == '' or line.startswith('#'):
            continue
        negated = line.startswith('!')
        cleaned = posixpath.normpath(line[1:] if negated else line)
        if cleaned in ('', '.', '..') or cleaned.startswith('../') or cleaned.startswith('/'):
            continue
        rules.append((cleaned, negated))
    return rules


def ignored(rules, name):
    """Whether `.dockerignore` excludes `name`, or a directory holding it."""
    parts = name.split('/')
    return any(rules_exclude(rules, '/'.join(parts[:index])) for index in range(1, len(parts) + 1))


def rules_exclude(rules, name):
    excluded = False
    for pattern, negated in rules:
        if matches(pattern, name):
            excluded = not negated
    return excluded


def matches(pattern, name):
    return segments_match(pattern.split('/'), name.split('/'))


def segments_match(pattern, parts):
    if not pattern:
        return not parts
    if pattern[0] == '**':
        return any(segments_match(pattern[1:], parts[index:]) for index in range(len(parts) + 1))
    if not parts:
        return False
    return fnmatch.fnmatchcase(parts[0], pattern[0]) and segments_match(pattern[1:], parts[1:])


def read_manifest(output, image):
    domain, repository, tag = reference(image)
    output.stdout(cli.line(cli.indented(Registry(domain).manifests(repository, tag), ascii=True)))


def reference(image):
    """The registry, repository and tag or digest of an image reference."""
    name, _, digest = image.partition('@')
    name, tag = ImageRef(name).repository_and_tag()
    domain, repository = located(name)
    return domain, repository, digest or tag


def located(name):
    """Docker Hub's official images live under `library/`; any other first component with a dot or a port is a host."""
    first, _, rest = name.partition('/')
    if rest and ('.' in first or ':' in first or first == 'localhost'):
        domain, repository = first, rest
    else:
        domain, repository = HUB, name
    domain = ALIASES.get(domain, domain)
    if domain == HUB and '/' not in repository:
        repository = 'library/' + repository
    return domain, repository


class Registry:
    """A registry's v2 API, read anonymously with a bearer token when the registry asks for one."""

    def __init__(self, domain, seconds=REGISTRY_SECONDS, limit=REGISTRY_LIMIT):
        self.domain = domain
        self.seconds = seconds
        self.limit = limit
        self.base = f'{"http" if insecure(domain) else "https"}://{domain}'
        self.tokens = {}

    def manifests(self, repository, reference):
        document, media_type, digest, size = self.manifest(repository, reference)
        descriptor = {'mediaType': media_type, 'digest': digest, 'size': size}
        if 'manifests' not in document:
            return shaped(self.ref(repository, digest), descriptor, document)
        found = []
        for listed in self.platforms(document, repository):
            child, _, _, _ = self.manifest(repository, listed['digest'])
            found.append(shaped(self.ref(repository, listed['digest']), listed, child))
        return found

    def platforms(self, document, repository):
        listed = document.get('manifests')
        if not isinstance(listed, list):
            raise Failed(f'the registry listed no manifest this broker can read for {repository}')
        if len(listed) > PLATFORMS:
            raise Failed(f'the registry listed more than {PLATFORMS} manifests for {repository}')
        found = [entry for entry in listed if isinstance(entry, dict) and isinstance(entry.get('digest'), str)]
        if not found:
            raise Failed(f'the registry listed no manifest this broker can read for {repository}')
        return found

    def manifest(self, repository, reference):
        path = f'/v2/{repository}/manifests/{urllib.parse.quote(reference, safe=":")}'
        status, headers, body = self.call(path, repository)
        if status == 401:
            self.tokens[repository] = self.token(repository, headers.get('WWW-Authenticate', ''))
            status, headers, body = self.call(path, repository)
        if status == 404:
            raise Failed(f'no such manifest: {self.named(repository, reference)}')
        if status != 200:
            raise Failed(registry_error(status, body))
        document = decoded(body, repository)
        media_type = document.get('mediaType') or headers.get('Content-Type', '').split(';')[0]
        digest = headers.get('Docker-Content-Digest') or 'sha256:' + hashlib.sha256(body).hexdigest()
        return document, media_type, digest, len(body)

    def call(self, path, repository):
        headers = {'Accept': MANIFEST_TYPES}
        token = self.tokens.get(repository)
        if token is not None:
            headers['Authorization'] = 'Bearer ' + token
        return get_url(self.base + path, headers, self.seconds, self.limit)

    def token(self, repository, header):
        parameters = challenge(header)
        if parameters is None:
            raise Failed('unauthorized: authentication required')
        realm = urllib.parse.urlsplit(parameters['realm'])
        query = urllib.parse.parse_qsl(realm.query)
        query.append(('scope', parameters.get('scope', f'repository:{repository}:pull')))
        if 'service' in parameters:
            query.append(('service', parameters['service']))
        url = urllib.parse.urlunsplit((realm.scheme, realm.netloc, realm.path, urllib.parse.urlencode(query), ''))
        status, _, body = get_url(url, {}, self.seconds, TOKEN_LIMIT)
        if status != 200:
            raise Failed(registry_error(status, body))
        document = decoded(body, repository)
        token = document.get('token') or document.get('access_token')
        if not isinstance(token, str) or token == '':
            raise Failed('the registry gave no token')
        return token

    def ref(self, repository, digest):
        return f'{displayed(self.domain)}/{repository}@{digest}'

    def named(self, repository, reference):
        separator = '@' if DIGEST.fullmatch(reference) else ':'
        return f'{displayed(self.domain)}/{repository}{separator}{reference}'


def insecure(domain):
    """Whether the registry is on this host, which Docker talks to over plain HTTP without being told to."""
    host = urllib.parse.urlsplit('//' + domain).hostname or domain
    return host in LOOPBACK or host.startswith('127.')


def displayed(domain):
    return 'docker.io' if domain == HUB else domain


def shaped(ref, descriptor, document):
    """One `--verbose` entry: the descriptor, and the manifest under the name its media type gives it."""
    entry = {'Ref': ref, 'Descriptor': descriptor}
    entry['OCIManifest' if oci(document) else 'SchemaV2Manifest'] = document
    return entry


def oci(document):
    return str(document.get('mediaType', '')).startswith('application/vnd.oci.')


def challenge(header):
    """A `WWW-Authenticate` header's bearer parameters, or None for another scheme."""
    scheme, _, rest = header.partition(' ')
    if scheme.lower() != 'bearer':
        return None
    parameters = dict(CHALLENGE.findall(rest))
    return parameters if 'realm' in parameters else None


def registry_error(status, body):
    try:
        message = json.loads(body)['errors'][0]['message']
    except (ValueError, KeyError, IndexError, TypeError):
        message = None
    if not isinstance(message, str) or message == '':
        return f'the registry answered {status}'
    return ' '.join(message.split())[:MESSAGE_LIMIT]


def decoded(body, repository):
    try:
        document = json.loads(body)
    except (ValueError, RecursionError) as e:
        raise Failed(f'the registry of {repository} sent no JSON') from e
    if not isinstance(document, dict):
        raise Failed(f'the registry of {repository} sent no manifest')
    return document


def get_url(url, headers, seconds, limit):
    """A GET of an absolute URL, its body read up to `limit` bytes."""
    parts = urllib.parse.urlsplit(url)
    kind = http.client.HTTPSConnection if parts.scheme == 'https' else http.client.HTTPConnection
    connection = kind(parts.netloc, timeout=seconds)
    try:
        connection.request('GET', urllib.parse.urlunsplit(('', '', parts.path or '/', parts.query, '')), headers=headers)
        response = connection.getresponse()
        return response.status, response.headers, dockerapi.read_body(response, limit)
    except dockerapi.TooLarge as e:
        raise Failed(f'the registry sent more than {limit} bytes') from e
    finally:
        connection.close()
