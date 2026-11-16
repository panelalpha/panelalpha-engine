import base64
import http.server
import io
import json
import os
import shutil
import tarfile
import tempfile
import threading
import unittest
import urllib.parse

import support
from rootbroker import hostdaemon, protocol
from rootbroker.actions import Policy, hostbuilds, registry
from test_account_docker import Chunks, serve

IMAGE_ID = 'sha256:' + 'e' * 64
FIRST = 'sha256:' + '1' * 64
SECOND = 'sha256:' + '2' * 64
FAILURE = 'process "/bin/sh -c false" did not complete successfully: exit code: 1'
DOCKERFILE = b'FROM debian:bookworm-slim\nRUN true\n'
ACTIONS = {name: registry()[name] for name in (
    'docker.buildFromStdin',
    'docker.inspectManifest',
    'lines.buildFromStdin',
    'lines.buildQuietly',
)}


def varint(value):
    data = bytearray()
    while True:
        byte = value & 0x7f
        value >>= 7
        data.append(byte | (0x80 if value else 0))
        if not value:
            return bytes(data)


def field(number, wire, payload):
    key = varint(number << 3 | wire)
    if wire == 0:
        return key + varint(payload)
    return key + varint(len(payload)) + payload


def whole(number, value):
    return field(number, 0, value)


def bytes_field(number, value):
    return field(number, 2, value if isinstance(value, bytes) else value.encode())


def moment(seconds):
    return field(1, 0, int(seconds)) + field(2, 0, round((seconds - int(seconds)) * 1e9))


def vertex(digest, name=None, cached=False, started=None, completed=None, error=None):
    data = bytes_field(1, digest)
    if name is not None:
        data += bytes_field(3, name)
    if cached:
        data += whole(4, 1)
    if started is not None:
        data += bytes_field(5, moment(started))
    if completed is not None:
        data += bytes_field(6, moment(completed))
    if error is not None:
        data += bytes_field(7, error)
    return data


def logged(digest, line, seconds=None):
    data = bytes_field(1, digest)
    if seconds is not None:
        data += bytes_field(2, moment(seconds))
    return data + bytes_field(4, line)


def trace(vertexes=(), logs=()):
    payload = b''.join(bytes_field(1, item) for item in vertexes) + b''.join(bytes_field(3, item) for item in logs)
    return json.dumps({'id': hostbuilds.TRACE, 'aux': base64.b64encode(payload).decode()}) + '\n'


def image(message):
    return json.dumps({'aux': {'ID': message}}) + '\n'


def failed(message):
    return json.dumps({'errorDetail': {'code': 1, 'message': message}, 'error': message}) + '\n'


class BuildsTest(support.Serving):
    actions = ACTIONS

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-builds-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
        self.original = hostdaemon.SOCKET
        hostdaemon.SOCKET = self.daemon_socket
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET = self.original
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def context(self):
        directory = os.path.join(self.roots['engine'], 'templates', 'user', 'dind', 'project')
        os.makedirs(os.path.join(directory, 'sub'), exist_ok=True)
        with open(os.path.join(directory, 'Dockerfile'), 'wb') as f:
            f.write(b'FROM debian:bookworm-slim\n')
        with open(os.path.join(directory, 'sub', 'file'), 'wb') as f:
            f.write(b'#!/bin/sh\n')
        return directory

    def build_request(self):
        return next(item for item in self.sent if urllib.parse.urlsplit(item[1]).path == '/build')

    def test_the_dockerfile_arrives_as_a_tar_holding_it_alone(self):
        self.routes[('POST', '/build')] = (200, Chunks([trace(vertexes=[vertex('a', name='[1/1] FROM x',
                                                                              started=1.0, completed=1.0)]).encode()]))
        result = self.pa_root('docker.buildFromStdin', 'acme/tool:1', 'no-cache', '2', 'VERSION', '1.2',
                              'NOTE', "it's\nlong", stdin=DOCKERFILE)
        self.assertEqual((result.returncode, result.stdout), (0, b''), result.stderr)
        _, path, body, headers = self.build_request()
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)
        self.assertEqual(query, {
            'version': ['2'], 't': ['acme/tool:1'], 'pull': ['1'], 'nocache': ['1'],
            'buildargs': [json.dumps({'VERSION': '1.2', 'NOTE': "it's\nlong"})],
        })
        self.assertEqual(headers['Content-Type'], 'application/x-tar')
        self.assertEqual(headers['X-Registry-Config'], 'e30=')
        with tarfile.open(fileobj=io.BytesIO(body)) as archive:
            self.assertEqual(archive.getnames(), ['Dockerfile'])
            self.assertEqual(archive.extractfile('Dockerfile').read(), DOCKERFILE)

    def test_a_line_build_pulls_only_when_asked_and_sends_no_build_arguments(self):
        self.routes[('POST', '/build')] = (200, Chunks([b'']))
        result = self.pa_root('lines.buildFromStdin', 'acme/tool:1', 'pull', stdin=DOCKERFILE)
        self.assertEqual(result.returncode, 0, result.stderr)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.build_request()[1]).query)
        self.assertEqual(query, {'version': ['2'], 't': ['acme/tool:1'], 'pull': ['1']})
        self.pa_root('lines.buildFromStdin', 'acme/tool:1', '', stdin=DOCKERFILE)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'version': ['2'], 't': ['acme/tool:1']})

    def test_the_plain_progress_is_printed_from_the_trace_messages(self):
        self.routes[('POST', '/build')] = (200, Chunks([
            trace(vertexes=[vertex('a', name='[internal] load build definition from Dockerfile', started=1.0)]).encode(),
            trace(logs=[logged('a', 'transferring dockerfile: 32B', seconds=1.0)]).encode(),
            trace(vertexes=[vertex('a', started=1.0, completed=1.0)]).encode(),
            trace(vertexes=[vertex('b', name='[1/2] FROM docker.io/library/debian:bookworm-slim', started=2.0)]).encode(),
            trace(logs=[logged('b', 'resolve docker.io/library/debian:bookworm-slim', seconds=2.5)]).encode(),
            trace(vertexes=[vertex('b', started=2.0, completed=4.4)]).encode(),
            trace(vertexes=[vertex('c', name='[2/2] RUN echo hi', cached=True, started=5.0, completed=5.0)]).encode(),
            trace(vertexes=[vertex('d', name='exporting to image', started=6.0)]).encode(),
            trace(logs=[logged('d', 'exporting layers\nwriting image', seconds=6.0)]).encode(),
            trace(vertexes=[vertex('d', started=6.0, completed=7.0)]).encode(),
        ]))
        result = self.pa_root('lines.buildFromStdin', 'acme/tool:1', '', stdin=DOCKERFILE)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr.decode().splitlines(), [
            '#1 [internal] load build definition from Dockerfile',
            '#1 0.000 transferring dockerfile: 32B',
            '#1 DONE 0.0s',
            '#2 [1/2] FROM docker.io/library/debian:bookworm-slim',
            '#2 0.500 resolve docker.io/library/debian:bookworm-slim',
            '#2 DONE 2.4s',
            '#3 [2/2] RUN echo hi',
            '#3 CACHED',
            '#4 exporting to image',
            '#4 0.000 exporting layers',
            '#4 0.000 writing image',
            '#4 DONE 1.0s',
        ])

    def test_a_stream_line_of_the_build_is_printed_as_it_came(self):
        self.routes[('POST', '/build')] = (200, Chunks([
            (json.dumps({'stream': 'Step 1/1 : FROM debian\n'}) + '\n').encode(),
            trace(vertexes=[vertex('a', name='[1/1] FROM docker.io/library/debian', started=1.0, completed=1.0)]).encode(),
        ]))
        result = self.pa_root('lines.buildFromStdin', 'acme/tool:1', '', stdin=DOCKERFILE)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr.decode().splitlines()[0], 'Step 1/1 : FROM debian')

    def test_a_failed_build_prints_the_vertex_error_and_exits_one(self):
        self.routes[('POST', '/build')] = (200, Chunks([
            trace(vertexes=[vertex('a', name='[2/2] RUN false', started=1.0)]).encode(),
            trace(logs=[logged('a', 'boom', seconds=1.2)]).encode(),
            trace(vertexes=[vertex('a', started=1.0, completed=1.2, error=FAILURE)]).encode(),
            failed(FAILURE + '\n').encode(),
        ]))
        result = self.pa_root('docker.buildFromStdin', 'acme/tool:1', '', '0', stdin=DOCKERFILE)
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, (
            '#1 [2/2] RUN false\n'
            '#1 0.200 boom\n'
            f'#1 ERROR: {FAILURE}\n'
            f'ERROR: failed to build: {FAILURE}\n'
        ).encode())

    def test_a_build_the_daemon_refuses_is_told_in_docker_s_words(self):
        self.routes[('POST', '/build')] = (400, {'message': 'the build context is not a tar'})
        result = self.pa_root('lines.buildFromStdin', 'acme/tool:1', '', stdin=DOCKERFILE)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, b'Error response from daemon: the build context is not a tar\n')

    def test_a_dockerfile_over_the_stdin_limit_is_refused_before_the_build(self):
        result = self.pa_root('docker.buildFromStdin', 'acme/tool:1', '', '0',
                              stdin=b'x' * (hostbuilds.DOCKERFILE_LIMIT + 1))
        self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertIn(b'more than', result.stderr)
        self.assertEqual([path for _, path, _, _ in self.sent if path.startswith('/build')], [])

    def test_a_quiet_build_sends_the_directory_as_a_tar_and_prints_the_image_id(self):
        context = self.context()
        os.symlink('file', os.path.join(context, 'sub', 'link'))
        with open(os.path.join(context, '.dockerignore'), 'w') as f:
            f.write('# a context is what the Dockerfile needs\nignored.txt\nsub/skip*\n!sub/skip-this\n')
        for name in ('ignored.txt', 'sub/skipped', 'sub/skip-this'):
            with open(os.path.join(context, name), 'w') as f:
                f.write('x')
        self.routes[('POST', '/build')] = (200, Chunks([image(IMAGE_ID).encode()]))
        result = self.pa_root('lines.buildQuietly', 'acme/account:1', context)
        self.assertEqual((result.returncode, result.stdout), (0, (IMAGE_ID + '\n').encode()), result.stderr)
        self.assertEqual(result.stderr, b'')
        _, path, body, _ = self.build_request()
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)
        self.assertEqual(query, {'version': ['2'], 't': ['acme/account:1'], 'q': ['1']})
        with tarfile.open(fileobj=io.BytesIO(body)) as archive:
            self.assertEqual([member.name for member in archive.getmembers()], [
                '.dockerignore', 'Dockerfile', 'sub', 'sub/file', 'sub/link', 'sub/skip-this',
            ])
            self.assertTrue(archive.getmember('sub').isdir())
            self.assertEqual(archive.getmember('sub/link').linkname, 'file')
            self.assertEqual(archive.extractfile('Dockerfile').read(), b'FROM debian:bookworm-slim\n')

    def test_a_quiet_build_the_daemon_names_in_no_message_asks_it_for_the_image(self):
        context = self.context()
        self.routes[('POST', '/build')] = (200, Chunks([b'']))
        self.routes[('GET', '/images/acme%2Faccount%3A1/json')] = (200, {'Id': IMAGE_ID})
        result = self.pa_root('lines.buildQuietly', 'acme/account:1', context)
        self.assertEqual((result.returncode, result.stdout), (0, (IMAGE_ID + '\n').encode()), result.stderr)

    def test_a_context_link_leaving_the_directory_is_refused(self):
        context = self.context()
        os.symlink('/etc/passwd', os.path.join(context, 'outside'))
        result = self.pa_root('lines.buildQuietly', 'acme/account:1', context)
        self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertIn(b'outside the build context', result.stderr)
        self.assertEqual([path for _, path, _, _ in self.sent if path.startswith('/build')], [])

    def test_a_context_that_is_no_engine_directory_is_refused(self):
        result = self.pa_root('lines.buildQuietly', 'acme/account:1', '/home/u/project')
        self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertEqual(self.sent, [])


INDEX = {
    'schemaVersion': 2,
    'mediaType': 'application/vnd.docker.distribution.manifest.list.v2+json',
    'manifests': [
        {'mediaType': 'application/vnd.docker.distribution.manifest.v2+json', 'digest': FIRST, 'size': 529,
         'platform': {'architecture': 'amd64', 'os': 'linux'}},
        {'mediaType': 'application/vnd.docker.distribution.manifest.v2+json', 'digest': SECOND, 'size': 529,
         'platform': {'architecture': 'arm64', 'os': 'linux', 'variant': 'v8'}},
    ],
}
MANIFEST = {
    'schemaVersion': 2,
    'mediaType': 'application/vnd.docker.distribution.manifest.v2+json',
    'config': {'mediaType': 'application/vnd.docker.container.image.v1+json', 'size': 100, 'digest': 'sha256:' + 'c' * 64},
    'layers': [{'mediaType': 'application/vnd.docker.image.rootfs.diff.tar.gzip', 'size': 1000},
               {'mediaType': 'application/vnd.docker.image.rootfs.diff.tar.gzip', 'size': 2000}],
}
OCI = {'schemaVersion': 2, 'mediaType': 'application/vnd.oci.image.manifest.v1+json', 'layers': [{'size': 7}]}
AUTH = 'Bearer realm="{realm}/token",service="registry",scope="repository:{repository}:pull"'


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def do_GET(self):
        self.server.asked.append((self.path, dict(self.headers)))
        answer = self.server.routes.get(urllib.parse.urlsplit(self.path).path)
        if answer is None:
            answer = (404, {'errors': [{'code': 'MANIFEST_UNKNOWN', 'message': 'manifest unknown'}]}, {})
        status, body, headers = answer(self.headers) if callable(answer) else answer
        payload = body if isinstance(body, bytes) else json.dumps(body).encode()
        self.send_response(status)
        for name, value in headers.items():
            self.send_header(name, value)
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)
        self.close_connection = True

    def log_message(self, *args):
        pass


class FakeRegistry(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, routes):
        super().__init__(('127.0.0.1', 0), Handler)
        self.routes = routes
        self.asked = []

    @property
    def base(self):
        return '127.0.0.1:%d' % self.server_address[1]

    def authorization(self, path):
        return [headers.get('Authorization') for asked, headers in self.asked if asked == path][-1]


class ManifestsTest(unittest.TestCase):
    def setUp(self):
        self.routes = {}
        self.registry = FakeRegistry(self.routes)
        self.addCleanup(self.registry.server_close)
        self.addCleanup(self.registry.shutdown)
        threading.Thread(target=self.registry.serve_forever, daemon=True).start()

    def route_manifests(self, repository, documents, token='secret'):
        base = f'/v2/{repository}/manifests/'

        def answer(expected):
            def reply(headers):
                if headers.get('Authorization') != 'Bearer ' + token:
                    return 401, {'errors': [{'code': 'UNAUTHORIZED', 'message': 'authentication required'}]}, {
                        'WWW-Authenticate': AUTH.format(realm=f'http://{self.registry.base}', repository=repository),
                    }
                return 200, documents[urllib.parse.unquote(expected[len(base):])], {}
            return reply

        for reference in documents:
            self.routes[base + reference] = answer(base + reference)
        self.routes['/token'] = (200, {'token': token}, {})

    def ask(self, image):
        action = registry()['docker.inspectManifest']
        plan = action.plan(action.check([image]), Policy())
        captured = Captured()
        return plan.native(captured), captured

    def test_a_manifest_list_is_an_entry_per_platform_asked_with_a_token(self):
        self.route_manifests('repo/app', {'1': INDEX, FIRST: MANIFEST, SECOND: MANIFEST})
        code, output = self.ask(f'{self.registry.base}/repo/app:1')
        self.assertEqual(code, 0, output.err)
        found = json.loads(output.out)
        self.assertEqual([entry['Descriptor']['platform']['architecture'] for entry in found], ['amd64', 'arm64'])
        self.assertEqual(found[0]['Ref'], f'{self.registry.base}/repo/app@{FIRST}')
        self.assertEqual([layer['size'] for layer in found[0]['SchemaV2Manifest']['layers']], [1000, 2000])
        self.assertNotIn('OCIManifest', found[0])
        token = next(path for path, _ in self.registry.asked if path.startswith('/token'))
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(token).query), {
            'scope': ['repository:repo/app:pull'], 'service': ['registry'],
        })
        self.assertEqual(self.registry.authorization('/v2/repo/app/manifests/1'), 'Bearer secret')

    def test_one_manifest_is_printed_as_one_object(self):
        self.route_manifests('single', {'1': OCI})
        code, output = self.ask(f'{self.registry.base}/single:1')
        self.assertEqual(code, 0, output.err)
        found = json.loads(output.out)
        self.assertEqual(found['OCIManifest'], OCI)
        self.assertEqual(found['Descriptor']['mediaType'], 'application/vnd.oci.image.manifest.v1+json')
        self.assertEqual(found['Descriptor']['size'], len(json.dumps(OCI).encode()))

    def test_a_missing_manifest_is_told_as_the_cli_told_it(self):
        self.route_manifests('repo/app', {})
        code, output = self.ask(f'{self.registry.base}/repo/app:1')
        self.assertEqual(code, 1)
        self.assertEqual(output.out, b'')
        self.assertEqual(output.err, f'no such manifest: {self.registry.base}/repo/app:1\n'.encode())

    def test_a_token_the_registry_refuses_is_told(self):
        self.routes['/v2/repo/app/manifests/1'] = (401, {'errors': [{'code': 'UNAUTHORIZED',
                                                                    'message': 'authentication required'}]},
                                                   {'WWW-Authenticate': 'Basic realm="registry"'})
        code, output = self.ask(f'{self.registry.base}/repo/app:1')
        self.assertEqual(code, 1)
        self.assertEqual(output.err, b'unauthorized: authentication required\n')

    def test_a_registry_that_is_not_there_is_told(self):
        code, output = self.ask('127.0.0.1:1/repo/app:1')
        self.assertEqual(code, 1)
        self.assertIn(b'could not reach the registry', output.err)


class Captured:
    def __init__(self):
        self.out = b''
        self.err = b''

    def stdout(self, data):
        self.out += data

    def stderr(self, data):
        self.err += data


class TraceTest(unittest.TestCase):
    def test_a_vertex_log_is_read_whichever_field_buildkit_wrote_its_timestamp_in(self):
        current = hostbuilds.status(bytes_field(3, bytes_field(1, 'a') + bytes_field(2, moment(1.5))
                                                   + bytes_field(4, b'boom\n')))
        earlier = hostbuilds.status(bytes_field(3, bytes_field(1, 'a') + bytes_field(3, moment(1.5))
                                                   + bytes_field(4, b'boom\n')))
        self.assertEqual(current['logs'], [{'vertex': 'a', 'timestamp': 1.5, 'msg': b'boom\n'}])
        self.assertEqual(earlier['logs'], current['logs'])


class ReferenceTest(unittest.TestCase):
    def test_docker_hub_images_are_asked_of_its_registry_under_library(self):
        self.assertEqual(hostbuilds.reference('debian:bookworm-slim'),
                         ('registry-1.docker.io', 'library/debian', 'bookworm-slim'))
        self.assertEqual(hostbuilds.reference('library/debian'), ('registry-1.docker.io', 'library/debian', 'latest'))
        self.assertEqual(hostbuilds.reference('ghcr.io/acme/app:1'), ('ghcr.io', 'acme/app', '1'))
        self.assertEqual(hostbuilds.reference('127.0.0.1:5000/a/b:2'), ('127.0.0.1:5000', 'a/b', '2'))
        self.assertEqual(hostbuilds.reference('debian@' + FIRST), ('registry-1.docker.io', 'library/debian', FIRST))


if __name__ == '__main__':
    unittest.main()
