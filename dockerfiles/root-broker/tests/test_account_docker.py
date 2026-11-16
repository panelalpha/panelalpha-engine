import http.server
import json
import os
import shutil
import socket
import socketserver
import struct
import tempfile
import threading
import time
import unittest
import urllib.parse

import support
from rootbroker import accountdaemon, accounts, protocol
from rootbroker.actions import accountdocker
from rootbroker.refused import Refused

CONTAINER_ID = 'c' * 64
IMAGE_ID = 'sha256:' + 'e' * 64
RUNNING = {
    'Id': CONTAINER_ID, 'Name': '/project-app-1', 'Created': '2026-11-09T09:50:01Z', 'Image': IMAGE_ID,
    'RestartCount': 2, 'Driver': 'overlayfs', 'HostConfig': {'Privileged': True},
    'State': {'Status': 'running', 'Running': True, 'Restarting': False, 'ExitCode': 0, 'Pid': 812,
              'StartedAt': '2026-11-09T09:50:02Z', 'FinishedAt': '0001-01-01T00:00:00Z'},
    'Config': {'Image': 'project-app', 'Tty': False, 'Env': ['SECRET=1'],
               'Labels': {'com.docker.compose.service': 'app'}},
    'NetworkSettings': {'Networks': {'project_default': {'IPAddress': '172.18.0.2', 'MacAddress': 'x'}},
                        'Ports': {'3000/tcp': [{'HostIp': '0.0.0.0', 'HostPort': '32768'}], '9229/tcp': None}},
    'Mounts': [{'Type': 'volume', 'Name': 'project_data', 'Source': '/var/lib/docker/volumes/project_data/_data',
                'Destination': '/data', 'RW': True}],
}
IMAGE = {
    'Id': IMAGE_ID, 'Created': '2026-11-01T00:00:00Z', 'RepoTags': ['node:22'], 'Size': 1,
    'Config': {'Cmd': ['node', 'index.js'], 'Env': ['PATH=/usr/bin'], 'ExposedPorts': {'3000/tcp': {}},
               'User': 'node', 'WorkingDir': '/app', 'Labels': None, 'OnBuild': ['x']},
}


class Daemon(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True


class Chunks(list):
    """A body sent with chunked transfer encoding, one chunk per item."""


class Hijack(bytes):
    """Raw bytes after a 101, the way `/exec/{id}/start` answers."""


class HijackReadingStdin(Hijack):
    """A hijack that reads the client's stdin to its end before it answers."""


class HijackHolding(Hijack):
    """A hijack that keeps its stream open and silent for HOLD_SECONDS after its bytes."""


class HijackUntilClosed(Hijack):
    """A hijack that keeps its stream open until the broker closes it, which it records."""


class Raw(bytes):
    """The whole reply as it goes on the wire, its status line and headers included."""


HOLD_SECONDS = 6


def handler(answer, asked, sent=None):
    class Answer(http.server.BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def do_GET(self):
            self.reply()

        def do_POST(self):
            self.reply()

        def do_PUT(self):
            self.reply()

        def do_DELETE(self):
            self.reply()

        def reply(self):
            asked.append((self.command, self.path))
            length = int(self.headers.get('Content-Length') or 0)
            if sent is not None:
                sent.append((self.command, self.path, self.rfile.read(length), dict(self.headers)))
            elif length:
                self.rfile.read(length)
            status, body = answer(self.command, self.path)
            if isinstance(body, Raw):
                self.wfile.write(body)
                self.close_connection = True
                return
            if isinstance(body, Hijack):
                self.send_response(101)
                self.send_header('Connection', 'Upgrade')
                self.send_header('Upgrade', 'tcp')
                self.end_headers()
                self.wfile.flush()
                if isinstance(body, HijackReadingStdin) and sent is not None:
                    sent.append(('STDIN', self.path, self.rfile.read(), {}))
                self.wfile.write(body)
                if isinstance(body, HijackUntilClosed) and sent is not None:
                    sent.append(('CLOSED', self.path, self.rfile.read(), {}))
                if isinstance(body, HijackHolding):
                    self.wfile.flush()
                    time.sleep(HOLD_SECONDS)
                self.close_connection = True
                return
            self.send_response(status)
            if isinstance(body, Chunks):
                self.send_header('Transfer-Encoding', 'chunked')
                self.end_headers()
                for chunk in body:
                    self.wfile.write(b'%x\r\n%s\r\n' % (len(chunk), chunk))
                self.wfile.write(b'0\r\n\r\n')
                return
            payload = body if isinstance(body, bytes) else json.dumps(body).encode()
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def address_string(self):
            return 'docker'

        def log_message(self, *args):
            pass

    return Answer


def serve(path, answer, asked, sent=None):
    daemon = Daemon(path, handler(answer, asked, sent))
    threading.Thread(target=daemon.serve_forever, daemon=True).start()
    return daemon


def frame(stream, data):
    return struct.pack('>BxxxI', stream, len(data)) + data


class AccountDockerTest(support.Serving):
    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-daemon-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked)
        self.original_open = accountdaemon.open_daemon
        accountdaemon.open_daemon = self.open_daemon
        super().setUp()

    def tearDown(self):
        super().tearDown()
        accountdaemon.open_daemon = self.original_open
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def open_daemon(self, account, accounts_directory):
        """In the forked broker: the fake daemon, or the socket a test named in `target` after the fork."""
        with open(os.path.join(self.scratch, 'accounts-directory'), 'w') as f:
            f.write(accounts_directory)
        try:
            with open(os.path.join(self.scratch, 'target')) as f:
                target = f.read()
        except FileNotFoundError:
            target = self.daemon_socket
        return accountdaemon.Daemon(account.name, os.open(target, os.O_PATH | os.O_CLOEXEC))

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def ask(self, action, *values):
        return self.pa_root(f'account-docker.{action}', 'u', *values)

    def decoded(self, result):
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_containers_keep_only_the_fields_the_engine_reads_and_null_for_a_missing_one(self):
        self.routes[('GET', f'/containers/{CONTAINER_ID}/json')] = (200, RUNNING)
        found = self.decoded(self.ask('containers', CONTAINER_ID, 'gone'))
        self.assertIsNone(found[1])
        self.assertEqual(found[0]['State']['Pid'], 812)
        self.assertEqual(found[0]['NetworkSettings']['Ports'], {'3000/tcp': [{'HostIp': '0.0.0.0', 'HostPort': '32768'}],
                                                                '9229/tcp': None})
        self.assertNotIn('Env', found[0]['Config'])
        self.assertNotIn('HostConfig', found[0])
        self.assertNotIn('RW', found[0]['Mounts'][0])

    def test_a_field_of_the_wrong_type_fails_the_reply(self):
        self.routes[('GET', '/containers/x/json')] = (200, {**RUNNING, 'State': {**RUNNING['State'], 'Pid': '812'}})
        result = self.ask('containers', 'x')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'answered with an unexpected State.Pid', result.stderr)
        self.assertEqual(result.stdout, b'')

    def test_a_reply_over_the_limit_is_refused_without_reading_it_all(self):
        self.routes[('GET', '/images/json')] = (200, b'[' + b' ' * (5 << 20) + b']')
        result = self.ask('listImages')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'larger than', result.stderr)

    def test_a_reply_that_is_no_json_fails(self):
        self.routes[('GET', '/images/json')] = (200, b'[[[[')
        self.assertIn(b'no JSON', self.ask('listImages').stderr)

    def test_list_containers_sends_the_filters_grouped_by_name(self):
        self.routes[('GET', '/containers/json')] = (200, [{'Id': CONTAINER_ID, 'Names': ['/a'], 'Image': 'x',
                                                           'ImageID': IMAGE_ID, 'State': 'running', 'Status': 'Up',
                                                           'Labels': {}, 'Ports': []}])
        found = self.decoded(self.ask('listContainers', 'all', '3', 'label', 'com.docker.compose.project=p',
                                      'label', 'com.docker.compose.service=app', 'publish', '8080'))
        self.assertEqual(found[0]['Id'], CONTAINER_ID)
        self.assertNotIn('Ports', found[0])
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query['all'], ['1'])
        self.assertEqual(json.loads(query['filters'][0]), {
            'label': ['com.docker.compose.project=p', 'com.docker.compose.service=app'], 'publish': ['8080'],
        })

    def test_list_containers_keep_what_compose_ps_prints_of_each(self):
        self.routes[('GET', '/containers/json')] = (200, [{
            'Id': CONTAINER_ID, 'Names': ['/a'], 'Image': 'x', 'ImageID': IMAGE_ID, 'Command': "sh -c 'exit 0'",
            'Created': 1794325201, 'Ports': [{'PrivatePort': 80, 'Type': 'tcp'}], 'State': 'running', 'Status': 'Up',
            'Labels': {}, 'HostConfig': {'NetworkMode': 'bridge'},
            'Mounts': [{'Type': 'volume', 'Name': 'project_data', 'Source': '/home/u/docker/volumes/project_data/_data',
                        'Destination': '/data', 'Driver': 'local', 'Mode': 'rw', 'RW': True},
                       {'Type': 'bind', 'Source': '/home/u/project', 'Destination': '/app', 'RW': False}],
            'NetworkSettings': {'Networks': {'project_default': {'IPAddress': '172.18.0.2', 'MacAddress': 'x'}}},
        }])
        found = self.decoded(self.ask('listContainers', 'all', '0'))[0]
        self.assertEqual(found['Command'], "sh -c 'exit 0'")
        self.assertEqual(found['Created'], 1794325201)
        self.assertEqual(found['Mounts'], [
            {'Name': 'project_data', 'Source': '/home/u/docker/volumes/project_data/_data', 'Driver': 'local'},
            {'Source': '/home/u/project'},
        ])
        self.assertEqual(found['NetworkSettings'], {'Networks': {'project_default': {}}})
        self.assertNotIn('Ports', found)
        self.assertNotIn('HostConfig', found)

    def test_a_listed_container_created_at_no_unix_time_fails_the_listing(self):
        self.routes[('GET', '/containers/json')] = (200, [{'Id': CONTAINER_ID, 'Created': '2026-11-10T15:40:01Z'}])
        result = self.ask('listContainers', 'all', '0')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'answered with an unexpected 0.Created', result.stderr)

    def test_list_containers_refuses_a_filter_it_does_not_know_before_asking(self):
        for pair in (('before', 'x'), ('status', 'asleep'), ('publish', '80/http'), ('label', '-x')):
            with self.subTest(pair=pair):
                result = self.ask('listContainers', '', '1', *pair)
                self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertEqual(self.asked, [])

    def test_images_keep_the_config_the_engine_reads(self):
        self.routes[('GET', '/images/node%3A22/json')] = (200, IMAGE)
        found = self.decoded(self.ask('images', 'node:22', 'missing:1'))
        self.assertEqual(found[0]['Config']['ExposedPorts'], {'3000/tcp': {}})
        self.assertEqual(found[0]['Config']['Cmd'], ['node', 'index.js'])
        self.assertNotIn('OnBuild', found[0]['Config'])
        self.assertIsNone(found[1])

    def test_has_image_asks_for_the_history(self):
        self.routes[('GET', '/images/node%3A22/history')] = (200, [{'Id': IMAGE_ID}, {'Id': '<missing>'}])
        self.assertEqual(self.ask('hasImage', 'node:22').returncode, 0)
        missing = self.ask('hasImage', 'node:20')
        self.assertEqual(missing.returncode, 1)
        self.assertEqual(missing.stderr, b'Error response from daemon: No such image: node:20\n')

    def test_tag_splits_the_target_into_repository_and_tag(self):
        self.routes[('POST', '/images/sha256%3A' + 'e' * 64 + '/tag')] = (201, b'')
        self.assertEqual(self.ask('tag', IMAGE_ID, 'panelalpha-serving:eeeeeeeeeeee').returncode, 0)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'repo': ['panelalpha-serving'], 'tag': ['eeeeeeeeeeee']})
        self.ask('tag', IMAGE_ID, 'panelalpha-cache-registry:5000/node')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'repo': ['panelalpha-cache-registry:5000/node'], 'tag': ['latest']})

    def test_tag_reports_the_daemon_s_message(self):
        result = self.ask('tag', 'nope:1', 'x:2')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, b'Error response from daemon: No such object: /images/nope%3A1/tag\n')

    def test_tag_refuses_a_digest_target(self):
        self.assertEqual(self.ask('tag', 'a:1', 'b@sha256:' + 'a' * 64).returncode, protocol.EXIT_REFUSED)

    def test_remove_images_tries_each_and_force_ignores_a_missing_one(self):
        self.routes[('DELETE', '/images/a%3A1')] = (200, [{'Untagged': 'a:1'}])
        self.routes[('DELETE', '/images/busy%3A1')] = (409, {'message': 'image is being used by running container'})
        self.assertEqual(self.ask('removeImages', 'force', 'a:1', 'gone:1').returncode, 0)
        result = self.ask('removeImages', '', 'busy:1', 'a:1')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'image is being used', result.stderr)
        self.assertEqual(self.asked[-1], ('DELETE', '/images/a%3A1?force=0'))

    def test_a_refusal_the_daemon_words_with_a_lone_surrogate_is_still_told(self):
        self.routes[('DELETE', '/images/busy%3A1')] = (409, b'{"message": "in use by \\ud800"}')
        result = self.ask('removeImages', '', 'busy:1')
        self.assertEqual((result.returncode, result.stderr), (1, b'Error response from daemon: in use by ?\n'))

    def test_pull_reads_the_progress_to_the_end(self):
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"Pulling from library/node"}\r\n',
                                                         b'{"status":"Downloaded newer image for node:22"}\r\n']))
        self.assertEqual(self.ask('pull', 'node:22', '').returncode, 0)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'fromImage': ['node'], 'tag': ['22']})

    def test_pull_fails_on_an_error_in_the_progress(self):
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"Pulling"}\n',
                                                         b'{"errorDetail":{"message":"no space"},"error":"no space\\nleft"}\n']))
        result = self.ask('pull', 'node:22', '')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, b'no space left\n')

    def test_pull_fails_on_the_daemon_s_status(self):
        self.routes[('POST', '/images/create')] = (404, {'message': 'manifest for x:1 not found'})
        result = self.ask('pull', 'x:1', '')
        self.assertEqual(result.stderr, b'Error response from daemon: manifest for x:1 not found\n')

    def test_pull_refuses_an_overlong_progress_line(self):
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"' + b'x' * (70 << 10) + b'"}\n']))
        self.assertIn(b'progress line over', self.ask('pull', 'x:1', '').stderr)

    def test_pull_by_digest_asks_for_the_digest(self):
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"ok"}\n']))
        self.ask('pull', 'reg:5000/a/b:1@sha256:' + 'a' * 64, '')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'fromImage': ['reg:5000/a/b'], 'tag': ['sha256:' + 'a' * 64]})

    def test_log_tail_merges_both_streams_in_the_order_they_came(self):
        self.routes[('GET', '/containers/app/json')] = (200, RUNNING)
        self.routes[('GET', '/containers/app/logs')] = (200, Chunks([frame(1, b'listening\n'), frame(2, b'boom\n') + frame(1, b'bye\n')]))
        result = self.ask('logTail', 'app', '50', '')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, b'listening\nboom\nbye\n')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'stdout': ['1'], 'stderr': ['1'], 'tail': ['50']})

    def test_log_tail_with_timestamps_asks_the_daemon_to_lead_each_line_with_its_time(self):
        self.routes[('GET', '/containers/app/json')] = (200, RUNNING)
        self.routes[('GET', '/containers/app/logs')] = (200, Chunks([frame(1, b'2026-11-10T08:00:00.000000001Z listening\n')]))
        result = self.ask('logTail', 'app', '50', 'timestamps')
        self.assertEqual(result.stdout, b'2026-11-10T08:00:00.000000001Z listening\n')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'stdout': ['1'], 'stderr': ['1'], 'tail': ['50'], 'timestamps': ['1']})

    def test_log_tail_refuses_a_flag_it_does_not_know(self):
        self.assertEqual(self.ask('logTail', 'app', '50', 'details').returncode, protocol.EXIT_REFUSED)

    def test_log_tail_of_a_tty_container_is_the_raw_stream(self):
        self.routes[('GET', '/containers/app/json')] = (200, {**RUNNING, 'Config': {**RUNNING['Config'], 'Tty': True}})
        self.routes[('GET', '/containers/app/logs')] = (200, Chunks([b'\x01\x00raw\r\n']))
        self.assertEqual(self.ask('logTail', 'app', '5', '').stdout, b'\x01\x00raw\r\n')

    def test_log_tail_is_cut_at_the_limit(self):
        self.routes[('GET', '/containers/app/json')] = (200, RUNNING)
        self.routes[('GET', '/containers/app/logs')] = (200, Chunks([frame(1, b'x' * (3 << 20)), frame(1, b'y' * (3 << 20))]))
        result = self.ask('logTail', 'app', '10', '')
        self.assertEqual(len(result.stdout), accountdocker.LOG_LIMIT + len(accountdocker.LOG_CUT))
        self.assertTrue(result.stdout.endswith(accountdocker.LOG_CUT))

    def test_log_tail_of_a_missing_container(self):
        result = self.ask('logTail', 'gone', '10', '')
        self.assertEqual((result.returncode, result.stderr), (1, b'Error response from daemon: No such container: gone\n'))

    def test_a_streamed_refusal_over_the_limit_is_told_in_the_daemon_s_words(self):
        self.routes[('GET', '/containers/app/json')] = (200, RUNNING)
        self.routes[('GET', '/containers/app/logs')] = (500, {'message': 'x' * (1 << 17)})
        result = self.ask('logTail', 'app', '10', '')
        stderr = b'the Docker daemon of u answered /containers/app/logs with the reply is larger than 65536 bytes\n'
        self.assert_answered(result, 1, stderr)

    def test_log_tail_refuses_more_lines_than_it_reads(self):
        self.assertEqual(self.ask('logTail', 'app', '10001', '').returncode, protocol.EXIT_REFUSED)

    def test_ping(self):
        self.routes[('GET', '/_ping')] = (200, b'OK')
        self.assertEqual(self.ask('ping').returncode, 0)

    def test_the_account_s_compose_project_is_below_the_engine_s_users(self):
        self.ask('ping')
        with open(os.path.join(self.scratch, 'accounts-directory')) as f:
            self.assertEqual(f.read(), os.path.join(self.roots['engine'], 'users'))

    def test_a_daemon_that_does_not_listen_is_unavailable_in_docker_s_words(self):
        stopped_path = os.path.join(self.scratch, 'stopped.sock')
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as stopped:
            stopped.bind(stopped_path)
        with open(os.path.join(self.scratch, 'target'), 'w') as f:
            f.write(stopped_path)
        result = self.ask('ping')
        self.assertEqual(result.returncode, protocol.EXIT_UNAVAILABLE)
        self.assertEqual(result.stderr, b'the Docker daemon of container u is not running\n')

    def test_only_a_hosting_account_is_asked_about(self):
        for name in ('root', 'low', 'nobody', 'nosuch', '../u'):
            with self.subTest(name=name):
                result = self.pa_root('account-docker.ping', name)
                self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertEqual(self.asked, [])


ACCOUNT = accounts.Account('alice', 1001, 1001, '/home/alice', '/usr/sbin/nologin')
USERS = '/opt/panelalpha/shared-hosting/users'


def account_container(pid, **changes):
    container = {
        'Name': '/alice', 'HostConfig': {'Runtime': 'sysbox-runc'},
        'Config': {'Labels': {'com.docker.compose.project': 'alice',
                              'com.docker.compose.project.working_dir': f'{USERS}/alice'}},
        'State': {'Running': True, 'Pid': pid, 'StartedAt': '2026-11-09T09:55:51Z'},
    }
    container.update(changes)
    return container


class OpenDaemonTest(unittest.TestCase):
    def setUp(self):
        self.base = tempfile.mkdtemp(prefix='broker-locate-')
        self.host_socket = os.path.join(self.base, 'host.sock')
        self.proc = os.path.join(self.base, 'proc')
        self.pid = os.getpid()
        self.root = os.path.join(self.proc, str(self.pid), 'root')
        os.makedirs(os.path.join(self.root, 'run'))
        os.makedirs(os.path.join(self.proc, str(self.pid), 'ns'))
        os.symlink(f'/proc/{self.pid}/ns/net', os.path.join(self.proc, str(self.pid), 'ns', 'net'))
        self.inner = os.path.join(self.root, 'run', 'docker.sock')
        self.listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.listener.bind(self.inner)
        self.listener.listen(1)
        self.containers = [account_container(self.pid)]
        self.asked = []
        self.host = serve(self.host_socket, self.host_answer, self.asked)

    def tearDown(self):
        self.host.shutdown()
        self.host.server_close()
        self.listener.close()
        shutil.rmtree(self.base, ignore_errors=True)

    def host_answer(self, method, path):
        if path != '/containers/alice/json':
            return 404, {'message': 'no such container'}
        return 200, self.containers[min(len(self.asked), len(self.containers)) - 1]

    def open(self):
        return accountdaemon.open_daemon(ACCOUNT, USERS, self.host_socket, self.proc)

    def test_the_socket_below_the_account_init_s_root(self):
        daemon = self.open()
        try:
            self.assertEqual(os.fstat(daemon.socket_fd).st_ino, os.stat(self.inner).st_ino)
        finally:
            daemon.close()
        self.assertEqual(len(self.asked), 2)

    def test_the_network_namespace_of_the_account_init_held_until_the_daemon_is_closed(self):
        daemon = self.open()
        held = daemon.network
        try:
            self.assertEqual(os.fstat(held).st_ino, os.stat(f'/proc/{self.pid}/ns/net').st_ino)
            self.assertIsNone(daemon.bounded(5).network_fd)
            self.assertEqual(daemon.bounded(5).network, held)
        finally:
            daemon.close()
        self.assertIsNone(daemon.network_fd)
        with self.assertRaises(OSError):
            os.fstat(held)

    def test_a_container_whose_namespace_is_gone_is_restarting(self):
        os.unlink(os.path.join(self.proc, str(self.pid), 'ns', 'net'))
        with self.assertRaisesRegex(accountdaemon.Unavailable, 'container alice is restarting'):
            self.open()

    def test_a_link_on_the_way_is_refused(self):
        os.unlink(self.inner)
        os.symlink(self.host_socket, self.inner)
        with self.assertRaisesRegex(accountdaemon.Failed, 'not a socket|is a link'):
            self.open()
        os.unlink(self.inner)
        os.rename(os.path.join(self.root, 'run'), os.path.join(self.root, 'real-run'))
        os.symlink('real-run', os.path.join(self.root, 'run'))
        with self.assertRaisesRegex(accountdaemon.Failed, 'is a link'):
            self.open()

    def test_a_file_that_is_no_socket_is_refused(self):
        os.unlink(self.inner)
        open(self.inner, 'w').close()
        with self.assertRaisesRegex(accountdaemon.Failed, 'not a socket'):
            self.open()

    def test_a_daemon_not_started_yet_is_unavailable(self):
        os.unlink(self.inner)
        with self.assertRaisesRegex(accountdaemon.Unavailable, 'daemon of container alice is not running'):
            self.open()

    def test_only_the_account_s_own_dind_container(self):
        labels = account_container(self.pid)['Config']['Labels']
        for changed in (
            {'Name': '/alice-2'},
            {'HostConfig': {'Runtime': 'runc'}},
            {'HostConfig': {'Runtime': 'runc', 'Privileged': True}},
            {'HostConfig': {'Runtime': 'runc', 'Privileged': True},
             'Config': {'Labels': {**labels, 'com.docker.compose.service': 'php'}}},
            {'Config': {'Labels': {**labels, 'com.docker.compose.project': 'bob'}}},
            {'Config': {'Labels': {**labels, 'com.docker.compose.project.working_dir': '/home/alice'}}},
        ):
            with self.subTest(changed=changed):
                self.containers = [account_container(self.pid, **changed)]
                with self.assertRaisesRegex(accountdaemon.Failed, 'alice has no Docker daemon of its own'):
                    self.open()

    def test_the_privileged_dind_service_of_an_engine_without_sysbox_is_the_account_s_container(self):
        labels = {**account_container(self.pid)['Config']['Labels'], 'com.docker.compose.service': 'dind'}
        self.containers = [account_container(self.pid, HostConfig={'Runtime': 'runc', 'Privileged': True},
                                             Config={'Labels': labels})]

        daemon = self.open()
        self.addCleanup(daemon.close)
        self.assertEqual(daemon.name, 'alice')
        self.assertEqual(accountdaemon.open_dind_container(ACCOUNT, USERS, self.host_socket).name, 'alice')

    def test_a_classic_php_container_takes_an_exec_but_has_no_daemon_of_its_own(self):
        labels = account_container(self.pid)['Config']['Labels']
        self.containers = [account_container(self.pid, HostConfig={'Runtime': 'runc'},
                                             Config={'Labels': {**labels, 'com.docker.compose.service': 'php'}})]

        self.assertEqual(accountdaemon.open_container(ACCOUNT, USERS, self.host_socket).name, 'alice')
        with self.assertRaisesRegex(accountdaemon.Failed, 'alice has no Docker daemon of its own'):
            self.open()

    def test_an_exec_goes_only_to_the_account_s_own_container(self):
        labels = {**account_container(self.pid)['Config']['Labels'], 'com.docker.compose.service': 'php'}
        for changed in (
            {'Name': '/alice-2', 'Config': {'Labels': labels}},
            {'HostConfig': {'Runtime': 'runc'}, 'Config': {'Labels': {**labels, 'com.docker.compose.service': 'web'}}},
            {'HostConfig': {'Runtime': 'runc'}, 'Config': {'Labels': {**labels, 'com.docker.compose.project': 'bob'}}},
            {'HostConfig': {'Runtime': 'runc'}, 'Config': {'Labels': {**labels, 'com.docker.compose.project.working_dir': '/home/alice'}}},
        ):
            with self.subTest(changed=changed):
                self.containers = [account_container(self.pid, **changed)]
                with self.assertRaisesRegex(accountdaemon.Failed, "container alice is not the account's own container"):
                    accountdaemon.open_container(ACCOUNT, USERS, self.host_socket)

    def test_a_stopped_container_is_unavailable(self):
        self.containers = [account_container(self.pid, State={'Running': False, 'Pid': 0})]
        with self.assertRaisesRegex(accountdaemon.Unavailable, 'container alice is not running'):
            self.open()

    def test_a_container_restarted_while_its_socket_was_opened_is_refused(self):
        self.containers = [account_container(self.pid),
                           account_container(self.pid, State={'Running': True, 'Pid': self.pid, 'StartedAt': 'later'})]
        with self.assertRaisesRegex(accountdaemon.Unavailable, 'restarting'):
            self.open()

    def test_a_missing_container_is_unavailable(self):
        with self.assertRaisesRegex(accountdaemon.Unavailable, 'container bob is not running'):
            accountdaemon.open_daemon(accounts.Account('bob', 1002, 1002, '/home/bob', ''), USERS, self.host_socket, self.proc)

    def test_a_host_reply_that_is_no_json_object_fails_in_its_words(self):
        for reply in (b'', b'[]', b'{"Name"'):
            with self.subTest(reply=reply):
                self.containers = [reply]
                with self.assertRaisesRegex(accountdaemon.Failed,
                                            "^the host's Docker answered /containers/alice/json with no JSON object$"):
                    self.open()


class ReadValuesTest(unittest.TestCase):
    def test_the_account_is_a_username(self):
        for name in ('', '-u', 'A', 'u/../v'):
            with self.subTest(name=name):
                with self.assertRaises(Refused):
                    accountdocker.ACTIONS['ping'].check([name])


if __name__ == '__main__':
    unittest.main()
