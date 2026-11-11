"""`rootbroker.logrotation`: what the logrotate service's postrotate runs in `sites-http` through a fake host daemon,
and the module run as the postrotate runs it."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import dockerapi, hostdaemon, logrotation
from rootbroker.actions import hostapi
from rootbroker.output import Output
from test_account_docker import Hijack, frame, serve

EXEC_ID = 'e' * 64
SITES_HTTP = 'c' * 64
PROJECT = 'shared-hosting'
RUN = ('import sys\n'
       'from rootbroker import hostdaemon, logrotation\n'
       'hostdaemon.SOCKET = sys.argv[1]\n'
       'hostdaemon.engine_project = lambda daemon: sys.argv[2]\n'
       'sys.exit(logrotation.main(sys.argv[3:]))\n')


class Kept(Output):
    def __init__(self):
        self.out = bytearray()
        self.err = bytearray()

    def stdout(self, data):
        self.out += data

    def stderr(self, data):
        self.err += data


class RotationTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-rotation-')
        self.socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(self.socket, self.answer, self.asked, self.sent)
        self.original = (hostdaemon.SOCKET, hostdaemon.engine_project)
        hostdaemon.SOCKET = self.socket
        hostdaemon.engine_project = lambda daemon: PROJECT
        self.containers([{'Id': SITES_HTTP, 'Labels': {logrotation.WEBSERVER_LABEL: 'nginx-proxy'}}])
        self.exec_answers(b'', 0)

    def tearDown(self):
        hostdaemon.SOCKET, hostdaemon.engine_project = self.original
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def containers(self, listed):
        self.routes[('GET', '/containers/json')] = (200, listed)

    def exec_answers(self, stream, code):
        self.routes[('POST', f'/containers/{SITES_HTTP}/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(stream))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': code, 'Running': False})

    def run_work(self, work, *values):
        output = Kept()
        return hostapi.run_on_host(work, values, output), bytes(output.out), bytes(output.err)

    def commands(self):
        return [json.loads(body)['Cmd'] for method, path, body, _ in self.sent
                if urllib.parse.urlsplit(path).path == f'/containers/{SITES_HTTP}/exec']

    def filters(self):
        listed = [path for method, path in self.asked if urllib.parse.urlsplit(path).path == '/containers/json']
        return [json.loads(urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)['filters'][0]) for path in listed]


class ReopenTest(RotationTest):
    def test_nginx_reopens_its_logs_in_the_running_sites_http_its_output_and_code_the_postrotate_s(self):
        self.exec_answers(frame(2, b'nginx: [notice] signal process started\n'), 0)
        self.assertEqual(self.run_work(logrotation.reopen, 'nginx'),
                         (0, b'', b'nginx: [notice] signal process started\n'))
        self.assertEqual(self.commands(), [['nginx', '-s', 'reopen']])
        self.assertEqual(self.filters(), [{
            'label': [f'{dockerapi.PROJECT}={PROJECT}', f'{dockerapi.SERVICE}=sites-http'], 'status': ['running'],
        }])

    def test_apache_is_restarted_gracefully(self):
        self.exec_answers(b'', 1)
        self.assertEqual(self.run_work(logrotation.reopen, 'apache')[0], 1)
        self.assertEqual(self.commands(), [['apachectl', '-k', 'graceful']])

    def test_a_sites_http_that_is_not_running_fails_as_compose_exec_did(self):
        self.containers([])
        self.assertEqual(self.run_work(logrotation.reopen, 'nginx'), (1, b'', b'service "sites-http" is not running\n'))
        self.assertEqual(self.commands(), [])


class AuditRotationTest(RotationTest):
    def test_the_web_server_the_label_names_is_restarted(self):
        for label, command in (('nginx-proxy', ['nginx', '-s', 'reload']),
                               ('nginx', ['service', 'nginx', 'restart']),
                               ('apache', ['apachectl', 'restart'])):
            with self.subTest(label=label):
                self.sent.clear()
                self.containers([{'Id': SITES_HTTP, 'Labels': {logrotation.WEBSERVER_LABEL: label}}])
                self.assertEqual(self.run_work(logrotation.restart_after_audit_rotation)[0], 0)
                self.assertEqual(self.commands(), [command])

    def test_the_label_is_read_off_every_container_of_the_service_as_compose_ps_a_lists_them(self):
        self.run_work(logrotation.restart_after_audit_rotation)
        self.assertEqual(self.filters()[0], {
            'label': [f'{dockerapi.PROJECT}={PROJECT}', f'{dockerapi.ONEOFF}=False', f'{dockerapi.SERVICE}=sites-http'],
        })

    def test_nothing_restarts_for_another_web_server_for_no_container_or_for_several(self):
        for listed in ([{'Id': SITES_HTTP, 'Labels': {logrotation.WEBSERVER_LABEL: 'litespeed'}}],
                       [{'Id': SITES_HTTP, 'Labels': {}}],
                       [],
                       [{'Id': SITES_HTTP, 'Labels': {logrotation.WEBSERVER_LABEL: 'nginx'}}] * 2):
            with self.subTest(listed=listed):
                self.containers(listed)
                self.assertEqual(self.run_work(logrotation.restart_after_audit_rotation), (0, b'', b''))
                self.assertEqual(self.commands(), [])


    def test_a_daemon_that_does_not_answer_the_label_read_restarts_nothing_as_the_script_did(self):
        self.routes[('GET', '/containers/json')] = (500, {'message': 'boom'})
        self.assertEqual(self.run_work(logrotation.restart_after_audit_rotation), (0, b'', b''))
        self.assertEqual(self.commands(), [])


class MainTest(RotationTest):
    def run_module(self, *arguments):
        env = {**os.environ, 'PYTHONPATH': os.path.join(support.HERE, '..')}
        return subprocess.run([sys.executable, '-c', RUN, self.socket, PROJECT, *arguments], capture_output=True,
                              env=env, timeout=support.SECONDS)

    def test_the_command_s_streams_and_exit_code_are_the_process_s(self):
        self.exec_answers(frame(1, b'out\n') + frame(2, b'err\n'), 3)
        result = self.run_module('reopen', 'nginx')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'out\n', b'err\n'))
        self.run_module('modsecurity')
        self.assertEqual(self.commands(), [['nginx', '-s', 'reopen'], ['nginx', '-s', 'reload']])

    def test_anything_else_is_its_usage(self):
        for arguments in ((), ('reopen',), ('reopen', 'litespeed'), ('modsecurity', 'nginx'), ('restart',)):
            with self.subTest(arguments=arguments):
                result = self.run_module(*arguments)
                self.assertEqual((result.returncode, result.stderr), (2, (logrotation.USAGE + '\n').encode()))
        self.assertEqual(self.asked, [])


if __name__ == '__main__':
    unittest.main()
