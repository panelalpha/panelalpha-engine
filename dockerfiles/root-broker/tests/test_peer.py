import http.server
import json
import os
import shutil
import socket
import socketserver
import tempfile
import threading
import unittest
import urllib.parse

import support
from rootbroker import dockerapi, peer
from support import needs_root

OWN = 'a' * 64
CORE = 'c' * 64
MOUNTINFO = (
    '1 0 8:1 / / rw - ext4 /dev/sda1 rw\n'
    f'2 1 8:1 /var/lib/docker/containers/{OWN}/resolv.conf /etc/resolv.conf rw - ext4 /dev/sda1 rw\n'
    f'3 1 8:1 /var/lib/docker/containers/{OWN}/hostname /etc/hostname rw - ext4 /dev/sda1 rw\n'
)


class Daemon(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True


def handler(answers, asked):
    class Answer(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            asked.append(self.path)
            status, body = answers(self.path)
            payload = json.dumps(body).encode()
            self.send_response(status)
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

        def address_string(self):
            return 'docker'

        def log_message(self, *args):
            pass

    return Answer


class CorePidTest(unittest.TestCase):
    def setUp(self):
        self.base = tempfile.mkdtemp(prefix='broker-docker-')
        self.socket = os.path.join(self.base, 'docker.sock')
        self.mountinfo = os.path.join(self.base, 'mountinfo')
        with open(self.mountinfo, 'w') as f:
            f.write(MOUNTINFO)
        self.asked = []
        self.listing = [{'Id': CORE}]
        self.daemon = Daemon(self.socket, handler(self.answer, self.asked))
        threading.Thread(target=self.daemon.serve_forever, daemon=True).start()

    def tearDown(self):
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.base, ignore_errors=True)

    def answer(self, path):
        if path == f'/containers/{OWN}/json':
            return 200, {'Config': {'Labels': {dockerapi.PROJECT: 'shared-hosting'}}}
        if path.startswith('/containers/json?filters='):
            return 200, self.listing
        if path == f'/containers/{CORE}/json':
            return 200, {'State': {'Pid': 4321}}
        return 404, {'message': 'no such container'}

    def core_pid(self):
        return peer.core_pid(self.socket, self.mountinfo)

    def test_core_is_the_running_core_service_of_the_broker_s_own_project(self):
        self.assertEqual(self.core_pid(), 4321)
        filters = json.loads(urllib.parse.unquote(self.asked[1].split('=', 1)[1]))
        self.assertEqual(sorted(filters['label']), sorted([
            'com.docker.compose.project=shared-hosting', 'com.docker.compose.service=core',
            'com.docker.compose.oneoff=False',
        ]))
        self.assertEqual(filters['status'], ['running'])

    def test_no_core_or_more_than_one_is_none(self):
        for listing in ([], [{'Id': CORE}, {'Id': 'd' * 64}]):
            self.listing = listing
            self.assertIsNone(self.core_pid())

    def test_a_broker_that_cannot_tell_its_own_container_finds_none(self):
        with open(self.mountinfo, 'w') as f:
            f.write('1 0 8:1 / / rw - ext4 /dev/sda1 rw\n')
        self.assertIsNone(self.core_pid())
        self.assertEqual(self.asked, [])

    def test_a_docker_that_does_not_answer_is_none(self):
        self.assertIsNone(peer.core_pid(os.path.join(self.base, 'missing.sock'), self.mountinfo))


class RunsInTest(unittest.TestCase):
    def test_a_peer_in_the_namespace_named_runs_in_it(self):
        ours, theirs = socket.socketpair(socket.AF_UNIX)
        with ours, theirs:
            self.assertTrue(peer.runs_in(ours, os.getpid(), peer.mount_namespace('self')))

    def test_no_peer_runs_in_no_namespace(self):
        ours, theirs = socket.socketpair(socket.AF_UNIX)
        with ours, theirs:
            self.assertFalse(peer.runs_in(ours, os.getpid(), None))

    def test_a_peer_in_another_namespace_does_not(self):
        ours, theirs = socket.socketpair(socket.AF_UNIX)
        with ours, theirs:
            self.assertFalse(peer.runs_in(ours, os.getpid(), 'mnt:[1]'))

    def test_a_peer_whose_process_has_gone_is_not(self):
        pid = os.fork()
        if pid == 0:
            os._exit(0)
        pidfd = os.pidfd_open(pid)
        os.waitpid(pid, 0)
        try:
            self.assertFalse(peer.still_alive(pidfd))
        finally:
            os.close(pidfd)

    def test_a_pid_that_does_not_exist_is_not(self):
        ours, theirs = socket.socketpair(socket.AF_UNIX)
        with ours, theirs:
            self.assertFalse(peer.runs_in(ours, 2 ** 22 + 1, peer.mount_namespace('self')))

    @needs_root
    def test_a_peer_in_another_mount_namespace_is_not(self):
        ours, theirs = socket.socketpair(socket.AF_UNIX)
        ready_r, ready_w = os.pipe()
        done_r, done_w = os.pipe()
        pid = os.fork()
        if pid == 0:
            os.unshare(os.CLONE_NEWNS)
            os.write(ready_w, b'x')
            os.read(done_r, 1)
            os._exit(0)
        try:
            os.read(ready_r, 1)
            self.assertFalse(peer.runs_in(ours, pid, peer.mount_namespace('self')))
        finally:
            os.write(done_w, b'x')
            os.waitpid(pid, 0)
            for fd in (ready_r, ready_w, done_r, done_w):
                os.close(fd)
            ours.close()
            theirs.close()


class Clock:
    def __init__(self):
        self.now = 100.0

    def __call__(self):
        return self.now


class CoreTest(unittest.TestCase):
    def setUp(self):
        self.asked = []
        self.answers = []
        self.children = []

    def tearDown(self):
        for child in self.children:
            child.close()

    def locate(self):
        self.asked.append(True)
        return self.answers.pop(0) if self.answers else None

    def child(self):
        child = support.Sleeper()
        self.children.append(child)
        return child

    def test_core_is_found_once_and_held_while_its_first_process_lives(self):
        core = peer.Core(self.locate)
        self.answers = [os.getpid()]
        self.assertEqual(core.current(), peer.mount_namespace('self'))
        self.assertEqual(core.current(), peer.mount_namespace('self'))
        self.assertEqual(len(self.asked), 1)

    def test_a_recreated_core_is_found_again_once_its_first_process_has_gone(self):
        first, second = self.child(), self.child()
        core = peer.Core(self.locate)
        self.answers = [first.pid, second.pid]
        self.assertIsNotNone(core.current())
        self.assertEqual(core.pid, first.pid)
        first.close()
        self.assertIsNotNone(core.current())
        self.assertEqual(core.pid, second.pid)
        self.assertEqual(len(self.asked), 2)

    def test_while_there_is_no_core_docker_is_asked_at_most_once_a_second(self):
        clock = Clock()
        core = peer.Core(self.locate, clock)
        self.assertIsNone(core.current())
        clock.now += peer.RETRY_SECONDS / 2
        self.assertIsNone(core.current())
        self.assertEqual(len(self.asked), 1)
        clock.now += peer.RETRY_SECONDS
        self.answers = [os.getpid()]
        self.assertEqual(core.current(), peer.mount_namespace('self'))
        self.assertEqual(len(self.asked), 2)

    def test_a_pid_that_has_gone_before_it_is_held_is_no_core(self):
        gone = self.child()
        pid = gone.pid
        gone.close()
        core = peer.Core(self.locate)
        self.answers = [pid]
        self.assertIsNone(core.current())


class OtherContainerTest(support.Serving):
    """Without root, in a user namespace: the uid the broker reads is the caller's, so only the namespace refuses."""

    def setUp(self):
        self.core_process = support.Container()
        if not self.core_process.isolated:
            self.core_process.close()
            self.skipTest('this host allows no unprivileged user namespace')
        self.addCleanup(self.core_process.close)
        super().setUp()

    def core(self):
        pid = self.core_process.pid
        return peer.Core(locate=lambda: pid)

    def test_core_s_own_process_is_served(self):
        self.directory('homes', 'u')
        code, err = self.core_process.pa_root(self.socket, 'files.removeTree', self.path('homes', 'u'))
        self.assertEqual(code, 0, err)
        self.assertFalse(os.path.isdir(self.path('homes', 'u')))

    def test_a_host_process_reaching_the_socket_through_core_s_root_is_refused(self):
        self.directory('homes', 'u')
        through_core = f'/proc/{self.core_process.pid}/root{self.socket}'
        result = self.pa_root_at(through_core, 'files.removeTree', self.path('homes', 'u'))
        self.assertEqual(result.returncode, 77, result.stderr)
        self.assertIn(b'does not run in the core container', result.stderr)
        self.assertTrue(os.path.isdir(self.path('homes', 'u')))

    def test_a_process_of_another_container_with_the_socket_mounted_is_refused(self):
        self.directory('homes', 'u')
        other = support.Container()
        self.addCleanup(other.close)
        code, err = other.pa_root(self.socket, 'files.removeTree', self.path('homes', 'u'))
        self.assertEqual(code, 77, err)
        self.assertIn('does not run in the core container', err)
        self.assertTrue(os.path.isdir(self.path('homes', 'u')))


@needs_root
class WwwDataInAnotherContainerTest(support.Serving):
    """As root on the test host."""

    allowed = {33}

    def setUp(self):
        self.core_process = support.Container(uid=33, user_namespace=False)
        self.addCleanup(self.core_process.close)
        super().setUp()
        os.chmod(self.base, 0o755)
        os.chmod(self.socket, 0o666)

    def core(self):
        pid = self.core_process.pid
        return peer.Core(locate=lambda: pid)

    def test_a_www_data_process_of_another_container_is_refused(self):
        other = support.Container(uid=33, user_namespace=False)
        self.addCleanup(other.close)
        code, err = other.pa_root(self.socket, 'files.read', self.make('engine', 'a', b'secret'))
        self.assertEqual(code, 77, err)
        self.assertIn('does not run in the core container', err)

    def test_core_s_www_data_is_served(self):
        os.chmod(self.make('engine', 'a', b'secret'), 0o644)
        code, err = self.core_process.pa_root(self.socket, 'files.read', self.path('engine', 'a'))
        self.assertEqual(code, 0, err)


if __name__ == '__main__':
    unittest.main()
