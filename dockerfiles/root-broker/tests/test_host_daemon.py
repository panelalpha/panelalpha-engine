import json
import os
import shutil
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import dockerapi, hostdaemon, peer, values
from rootbroker.actions import accountrun, hostapi
from test_account_docker import Hijack, HijackReadingStdin, frame, serve

EXEC_ID = 'e' * 64
OWN_ID = 'b' * 64


def exec_with_stdin(daemon, output, container, argv):
    return accountrun.exec_in(daemon, output, container, '', '', [], argv, dockerapi.CLIENT)


def exec_without_stdin(daemon, output, container, argv):
    return accountrun.exec_in(daemon, output, container, '', '', [], argv)


def service(daemon, output, name):
    output.stdout(hostdaemon.engine_service(daemon, name).encode() + b'\n')
    return 0


ACTIONS = {
    'test.exec': hostapi.asking(lambda given: (given.one(values.name), given.rest(values.operand)), exec_with_stdin),
    'test.execWithoutStdin': hostapi.asking(lambda given: (given.one(values.name), given.rest(values.operand)),
                                            exec_without_stdin),
    'test.service': hostapi.asking(lambda given: (given.one(values.name),), service),
}


class HostDaemonTest(support.Serving):
    actions = ACTIONS

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-host-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
        self.original = (hostdaemon.SOCKET, hostdaemon.engine_project)
        hostdaemon.SOCKET = self.daemon_socket
        hostdaemon.engine_project = lambda daemon: 'shared-hosting'
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET, hostdaemon.engine_project = self.original
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        answer = self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))
        return answer(path) if callable(answer) else answer

    def exec_routes(self, hijack, code=0):
        self.routes[('POST', '/containers/sites-http/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, hijack)
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': code, 'Running': False})

    def test_the_client_s_stdin_reaches_the_command_and_its_output_comes_back(self):
        self.exec_routes(HijackReadingStdin(frame(1, b'restored\n') + frame(2, b'note\n')), code=4)
        dump = b''.join(b'INSERT INTO t VALUES (%d);\n' % i for i in range(150000))
        result = self.pa_root('test.exec', 'sites-http', 'mariadb', stdin=dump)
        self.assertEqual(result.returncode, 4, result.stderr)
        self.assertEqual((result.stdout, result.stderr), (b'restored\n', b'note\n'))
        self.assertEqual(next(body for kind, _, body, _ in self.sent if kind == 'STDIN'), dump)
        created = next(json.loads(body) for kind, path, body, _ in self.sent if path == '/containers/sites-http/exec')
        self.assertIs(created['AttachStdin'], True)

    def test_an_exec_that_takes_no_stdin_drops_the_client_s(self):
        self.exec_routes(Hijack(frame(1, b'ok\n')))
        result = self.pa_root('test.execWithoutStdin', 'sites-http', 'nginx', stdin=b'ignored\n')
        self.assertEqual((result.returncode, result.stdout), (0, b'ok\n'), result.stderr)
        created = next(json.loads(body) for kind, path, body, _ in self.sent if path == '/containers/sites-http/exec')
        self.assertIs(created['AttachStdin'], False)

    def test_an_engine_service_is_its_first_running_container_in_the_broker_s_project(self):
        self.routes[('GET', '/containers/json')] = (200, [
            {'Id': '2' * 64, 'Labels': {hostdaemon.CONTAINER_NUMBER: '2'}},
            {'Id': '1' * 64, 'Labels': {hostdaemon.CONTAINER_NUMBER: '1'}},
        ])
        result = self.pa_root('test.service', 'sites-http')
        self.assertEqual((result.returncode, result.stdout), (0, b'1' * 64 + b'\n'), result.stderr)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(json.loads(query['filters'][0]), {
            'label': ['com.docker.compose.project=shared-hosting', 'com.docker.compose.service=sites-http'],
            'status': ['running'],
        })

    def test_an_engine_service_is_picked_in_compose_exec_s_order(self):
        one_off = {'Id': 'o' * 64, 'Labels': {dockerapi.ONEOFF: 'True', hostdaemon.CONTAINER_NUMBER: '1'}}
        for running, picked in (
            ([one_off, {'Id': '2' * 64, 'Labels': {hostdaemon.CONTAINER_NUMBER: '2'}}], '2' * 64),
            ([one_off], 'o' * 64),
            ([{'Id': '1' * 64, 'Labels': {hostdaemon.CONTAINER_NUMBER: '1'}},
              {'Id': 'n' * 64, 'Labels': {hostdaemon.CONTAINER_NUMBER: 'x'}}], 'n' * 64),
        ):
            with self.subTest(picked=picked[0]):
                self.routes[('GET', '/containers/json')] = (200, running)
                result = self.pa_root('test.service', 'sites-http')
                self.assertEqual((result.returncode, result.stdout), (0, picked.encode() + b'\n'), result.stderr)

    def test_an_exec_the_host_s_daemon_answers_in_an_unexpected_shape_is_told(self):
        self.exec_routes(Hijack(frame(1, b'ok\n')))
        self.routes[('POST', '/containers/sites-http/exec')] = (201, {'Id': 'not an id'})
        result = self.pa_root('test.execWithoutStdin', 'sites-http', 'nginx')
        self.assertEqual((result.returncode, result.stderr), (1, b"the host's Docker answered with an unexpected Id\n"))

    def test_an_exec_start_the_host_s_daemon_refuses_is_its_error(self):
        self.exec_routes(Hijack(frame(1, b'ok\n')))
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (409, {'message': 'Container sites-http is paused'})
        result = self.pa_root('test.execWithoutStdin', 'sites-http', 'nginx')
        self.assertEqual((result.returncode, result.stdout, result.stderr),
                         (1, b'', b'Error response from daemon: Container sites-http is paused\n'))

    def test_a_service_with_no_running_container_fails_as_compose_does(self):
        self.routes[('GET', '/containers/json')] = (200, [])
        result = self.pa_root('test.service', 'mail')
        self.assertEqual((result.returncode, result.stderr), (1, b'service "mail" is not running\n'))

    def test_a_daemon_error_is_told_in_the_cli_s_words(self):
        self.routes[('POST', '/containers/sites-http/exec')] = (409, {'message': 'container  is   paused'})
        result = self.pa_root('test.execWithoutStdin', 'sites-http', 'true')
        self.assertEqual((result.returncode, result.stderr), (1, b'Error response from daemon: container is paused\n'))


class NoDaemonTest(support.Serving):
    actions = ACTIONS

    def setUp(self):
        self.original = hostdaemon.SOCKET
        hostdaemon.SOCKET = os.path.join(tempfile.gettempdir(), 'no-such-docker.sock')
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET = self.original

    def test_a_daemon_that_is_not_there_is_told_as_the_cli_tells_it(self):
        result = self.pa_root('test.execWithoutStdin', 'sites-http', 'true')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, (hostdaemon.CANNOT_CONNECT + '\n').encode())


class EngineProjectTest(unittest.TestCase):
    def test_the_project_is_the_label_of_the_container_the_broker_runs_in(self):
        class Daemon:
            def json(self, method, path):
                self.asked = path
                return {'Config': {'Labels': {'com.docker.compose.project': 'shared-hosting'}}}

        daemon = Daemon()
        self.assertEqual(hostdaemon.engine_project(daemon, OWN_ID), 'shared-hosting')
        self.assertEqual(daemon.asked, f'/containers/{OWN_ID}/json')

    def test_the_container_is_the_one_whose_mounts_the_broker_saw_when_it_loaded(self):
        scratch = tempfile.mkdtemp(prefix='broker-mountinfo-')
        self.addCleanup(shutil.rmtree, scratch, True)
        mountinfo = os.path.join(scratch, 'mountinfo')
        with open(mountinfo, 'w') as f:
            f.write(f'1 2 0:1 /var/lib/docker/containers/{OWN_ID}/hostname /etc/hostname rw - ext4 /dev/sda rw\n')
        saved = peer.MOUNTINFO
        peer.MOUNTINFO = mountinfo
        self.addCleanup(setattr, peer, 'MOUNTINFO', saved)
        self.assertEqual(hostdaemon.own_container(), OWN_ID)

    def test_a_broker_that_saw_no_container_of_its_own_names_no_project(self):
        saved = hostdaemon.OWN
        hostdaemon.OWN = None
        self.addCleanup(setattr, hostdaemon, 'OWN', saved)
        with self.assertRaises(hostdaemon.Failed):
            hostdaemon.engine_project(object())


if __name__ == '__main__':
    unittest.main()
