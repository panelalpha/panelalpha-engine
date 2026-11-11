"""`rootbroker.enginecommand`: a command in an engine service's running container through a fake host daemon, run as
the webserver switch runs it for the rebuild in core."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import enginecommand, hostdaemon
from test_account_docker import Hijack, frame, serve

EXEC_ID = 'e' * 64
CORE = 'c' * 64
PROJECT = 'shared-hosting'
RUN = ('import sys\n'
       'from rootbroker import enginecommand, hostdaemon\n'
       'hostdaemon.SOCKET = sys.argv[1]\n'
       'hostdaemon.engine_project = lambda daemon: sys.argv[2]\n'
       'sys.exit(enginecommand.main(sys.argv[3:]))\n')


class EngineCommandTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-engine-command-')
        self.socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {('GET', '/containers/json'): (200, [{'Id': CORE, 'Labels': {hostdaemon.CONTAINER_NUMBER: '1'}}])}
        self.daemon = serve(self.socket, self.answer, self.asked, self.sent)

    def tearDown(self):
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def exec_answers(self, stream, code):
        self.routes[('POST', f'/containers/{CORE}/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(stream))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': code, 'Running': False})

    def run_module(self, *arguments):
        env = {**os.environ, 'PYTHONPATH': os.path.dirname(os.path.dirname(os.path.abspath(enginecommand.__file__)))}
        return subprocess.run([sys.executable, '-c', RUN, self.socket, PROJECT, *arguments], capture_output=True,
                              env=env, timeout=support.SECONDS)

    def test_the_command_runs_in_the_service_s_container_its_streams_and_code_the_process_s(self):
        self.exec_answers(frame(1, b'rebuilt\n') + frame(2, b'note\n'), 4)
        result = self.run_module('core', 'php', 'artisan', 'users:rebuild', '--all', '--wipe-vhosts-dir', '-v')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (4, b'rebuilt\n', b'note\n'))
        created = next(json.loads(body) for _, path, body, _ in self.sent if path == f'/containers/{CORE}/exec')
        self.assertEqual(created['Cmd'], ['php', 'artisan', 'users:rebuild', '--all', '--wipe-vhosts-dir', '-v'])
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[0][1]).query)
        self.assertIn('com.docker.compose.service=core', json.loads(query['filters'][0])['label'])

    def test_a_service_that_is_not_running_is_told_as_compose_tells_it(self):
        self.routes[('GET', '/containers/json')] = (200, [])
        result = self.run_module('core', 'true')
        self.assertEqual((result.returncode, result.stderr), (1, b'service "core" is not running\n'))

    def test_without_a_service_and_a_command_it_is_its_usage(self):
        for arguments in ((), ('core',)):
            with self.subTest(arguments=arguments):
                result = self.run_module(*arguments)
                self.assertEqual((result.returncode, result.stderr), (2, (enginecommand.USAGE + '\n').encode()))
        self.assertEqual(self.asked, [])


if __name__ == '__main__':
    unittest.main()
