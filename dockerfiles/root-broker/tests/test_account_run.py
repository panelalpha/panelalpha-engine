import base64
import io
import json
import os
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
import urllib.parse

import support
from rootbroker import accountdaemon, dockerapi, innerprocesses, paths, protocol, registrylogin
from rootbroker.actions import accountcontainer
from test_account_docker import Chunks, Hijack, HijackReadingStdin, HijackUntilClosed, Raw, frame, serve

EXEC_ID = 'e' * 64
CONTAINER_ID = 'c' * 64
PASSWD = b'root:x:0:0:root:/root:/bin/sh\nwww-data:x:33:33::/var/www:/usr/sbin/nologin\n'


def tar_of(name, data, kind=tarfile.REGTYPE):
    out = io.BytesIO()
    with tarfile.open(fileobj=out, mode='w') as tar:
        member = tarfile.TarInfo(name)
        member.type = kind
        member.size = len(data) if kind == tarfile.REGTYPE else 0
        tar.addfile(member, io.BytesIO(data) if kind == tarfile.REGTYPE else None)
    return out.getvalue()


class FakeDaemon(support.Serving):
    """The broker, with the account's daemon a fake that records every request and its body."""

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-run-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
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
        return accountdaemon.Daemon(account.name, os.open(self.daemon_socket, os.O_PATH | os.O_CLOEXEC))

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        answer = self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))
        return answer(path) if callable(answer) else answer

    def ask(self, action, *values):
        return self.pa_root(f'account-docker.{action}', 'u', *values)

    def body_of(self, method, route):
        return next(json.loads(body) for m, path, body, _ in self.sent
                    if m == method and urllib.parse.urlsplit(path).path == route)


class ExecTest(FakeDaemon):
    def test_exec_streams_both_outputs_and_exits_with_the_command_s_code(self):
        self.routes[('POST', '/containers/app/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(frame(1, b'path\n') + frame(2, b'warn\n') + frame(1, b'done\n')))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': 3, 'Running': False})
        result = self.ask('exec', 'app', '33:33', '/var/www/html', '1', 'HOME', '/tmp', 'php', '-v')
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertEqual((result.stdout, result.stderr), (b'path\ndone\n', b'warn\n'))
        self.assertEqual(self.body_of('POST', '/containers/app/exec'), {
            'AttachStdout': True, 'AttachStderr': True, 'AttachStdin': False, 'Tty': False, 'Cmd': ['php', '-v'],
            'Env': ['HOME=/tmp'], 'User': '33:33', 'WorkingDir': '/var/www/html',
        })

    def test_exec_as_the_default_user_sends_no_user_or_directory(self):
        self.routes[('POST', '/containers/app/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(b''))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': 0, 'Running': False})
        self.assertEqual(self.ask('exec', 'app', '', '', '0', 'true').returncode, 0)
        body = self.body_of('POST', '/containers/app/exec')
        self.assertNotIn('User', body)
        self.assertNotIn('WorkingDir', body)

    def test_exec_into_a_container_that_is_not_there(self):
        result = self.ask('exec', 'gone', '', '', '0', 'true')
        self.assertEqual(result.returncode, 1)
        self.assertTrue(result.stderr.startswith(b'Error response from daemon: No such object'), result.stderr)

    def test_exec_needs_a_command_and_refuses_a_relative_directory(self):
        self.assertEqual(self.ask('exec', 'app', '', '', '0').returncode, protocol.EXIT_REFUSED)
        self.assertEqual(self.ask('exec', 'app', '', 'html', '0', 'true').returncode, protocol.EXIT_REFUSED)
        self.assertEqual(self.ask('exec', 'app', 'root user', '', '0', 'true').returncode, protocol.EXIT_REFUSED)

    def test_an_exec_the_daemon_creates_without_its_id_is_an_unexpected_reply(self):
        self.routes[('POST', '/containers/app/exec')] = (201, {'Warnings': []})
        result = self.ask('exec', 'app', '', '', '0', 'true')
        self.assert_answered(result, 1, b'the Docker daemon of u answered with an unexpected Id\n')

    def test_an_exec_the_daemon_creates_with_an_empty_reply_is_an_unexpected_reply(self):
        self.routes[('POST', '/containers/app/exec')] = (201, b'')
        result = self.ask('exec', 'app', '', '', '0', 'true')
        self.assert_answered(result, 1, b'the Docker daemon of u answered with an unexpected reply\n')

    def test_an_exec_start_answered_with_no_status_line_is_a_daemon_that_stopped_answering(self):
        self.routes[('POST', '/containers/app/exec')] = (201, {'Id': EXEC_ID})
        for request, line in enumerate((b'HTTP/1.1 abc', b'HTTP/1.1', b'garbage'), start=1):
            with self.subTest(line=line):
                self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Raw(line + b'\r\n\r\n'))
                result = self.ask('exec', 'app', '', '', '0', 'true')
                stderr = f'the Docker daemon of u stopped answering: BadStatusLine({line.decode()!r})\n'.encode()
                self.assert_answered(result, protocol.EXIT_UNAVAILABLE, stderr, request)


class AccountContainerTest(FakeDaemon):
    """`account-container.exec` asks the host's daemon about the account's own container, which the fake stands for."""

    def setUp(self):
        self.original_container = accountdaemon.open_container
        accountdaemon.open_container = lambda account, directory: accountdaemon.Daemon(account.name, address=self.daemon_socket)
        super().setUp()

    def tearDown(self):
        super().tearDown()
        accountdaemon.open_container = self.original_container

    def test_a_command_runs_in_the_account_s_own_container(self):
        self.routes[('POST', '/containers/u/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(frame(1, b'ok\n')))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': 0, 'Running': False})
        result = self.pa_root('account-container.exec', 'u', '1004:1005', '', '1', 'DOCKER_CONFIG', '/home/u/.panelalpha-docker',
                              'git', 'rev-parse', 'HEAD')
        self.assertEqual((result.returncode, result.stdout), (0, b'ok\n'), result.stderr)
        self.assertEqual(self.body_of('POST', '/containers/u/exec'), {
            'AttachStdout': True, 'AttachStderr': True, 'AttachStdin': True, 'Tty': False,
            'Cmd': ['/bin/bash', '-c', accountcontainer.WATCHED_STDIN, 'bash', 'git', 'rev-parse', 'HEAD'],
            'Env': ['DOCKER_CONFIG=/home/u/.panelalpha-docker'], 'User': '1004:1005',
        })

    def test_a_caller_that_hangs_up_closes_the_command_s_stdin(self):
        self.routes[('POST', '/containers/u/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, HijackUntilClosed(b''))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': 0, 'Running': False})
        with self.assertRaises(subprocess.TimeoutExpired):
            self.pa_root('account-container.exec', 'u', '', '', '0', 'sleep', '30', timeout=2)
        closed = lambda: [entry[:3] for entry in self.sent if entry[0] == 'CLOSED']
        deadline = time.monotonic() + support.SECONDS
        while not closed() and time.monotonic() < deadline:
            time.sleep(0.05)
        self.assertEqual(closed(), [('CLOSED', f'/exec/{EXEC_ID}/start', b'')],
                         'the daemon is still waiting on a stream the caller left')

    def test_only_a_hosting_account_and_something_to_run(self):
        self.assertEqual(self.pa_root('account-container.exec', 'root', '', '', '0', 'id').returncode, protocol.EXIT_REFUSED)
        self.assertEqual(self.pa_root('account-container.exec', 'u', '', '', '0').returncode, protocol.EXIT_REFUSED)
        self.assertEqual(self.asked, [])


class WatchedStdinTest(unittest.TestCase):
    """The shell an exec runs a command under: the command ends when the stream on its stdin does, which is the
    caller's connection, and not before."""

    def started(self, script):
        process = subprocess.Popen(['/bin/bash', '-c', accountcontainer.WATCHED_STDIN, 'bash', '/bin/bash', '-c', script],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(stop, process)
        return process

    def test_the_command_runs_while_the_caller_is_connected(self):
        process = self.started('sleep 30')
        time.sleep(1)
        self.assertIsNone(process.poll(), 'the command ended while its caller was still there')
        process.stdin.close()
        self.assertNotEqual(ended(process), 0)

    def test_the_command_and_its_own_children_end_with_the_caller(self):
        marker = os.path.join(tempfile.mkdtemp(prefix='broker-watch-'), 'grandchild.pid')
        self.addCleanup(shutil.rmtree, os.path.dirname(marker), True)
        process = self.started(f'sleep 60 & echo $! > {marker}; wait')
        deadline = time.monotonic() + support.SECONDS
        while not os.path.exists(marker) and time.monotonic() < deadline:
            time.sleep(0.02)
        with open(marker) as f:
            grandchild = int(f.read())
        self.assertTrue(alive(grandchild))
        process.stdin.close()
        self.assertNotEqual(ended(process), 0)
        deadline = time.monotonic() + support.SECONDS
        while alive(grandchild) and time.monotonic() < deadline:
            time.sleep(0.05)
        self.assertFalse(alive(grandchild), 'the command\'s own child outlived the caller')

    def test_the_command_s_streams_and_exit_code_are_the_caller_s(self):
        process = self.started('echo out; echo err >&2; exit 7')
        self.assertEqual(process.wait(timeout=support.SECONDS), 7)
        self.assertEqual(process.communicate(), (b'out\n', b'err\n'))


def ended(process, seconds=support.SECONDS):
    """The process's exit code, waiting no longer than `seconds`."""
    return process.wait(timeout=seconds)


def stop(process):
    """Ends a wrapper a test started, and closes the streams it was given."""
    if process.poll() is None:
        process.kill()
    try:
        process.communicate(timeout=support.SECONDS)
    except (OSError, ValueError, subprocess.TimeoutExpired):
        pass
    for stream in (process.stdin, process.stdout, process.stderr):
        if not stream.closed:
            stream.close()


def alive(pid):
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    with open(f'/proc/{pid}/stat') as stat:
        return stat.read().split()[2] != 'Z'


class RegistryConfigTest(FakeDaemon):
    """`account-container.daemonConfigMounted` and `reloadDaemon`: the account's own DinD container, whose engine
    renders the daemon.json that mounts into it."""

    def setUp(self):
        self.original_dind = accountdaemon.open_dind_container
        accountdaemon.open_dind_container = self.open_dind_container
        super().setUp()

    def tearDown(self):
        super().tearDown()
        accountdaemon.open_dind_container = self.original_dind

    def open_dind_container(self, account, accounts_directory):
        return self.original_dind(account, accounts_directory, self.daemon_socket)

    def container(self, pid=None, runtime='sysbox-runc', label=dockerapi.PROJECT, mounts=()):
        labels = {label: 'u', dockerapi.WORKING_DIR: os.path.join(self.roots['engine'], 'users', 'u')}
        return {
            'Id': CONTAINER_ID,
            'Name': '/u',
            'State': {'Status': 'running', 'Running': True, 'Pid': os.getpid() if pid is None else pid},
            'Config': {'Labels': labels},
            'HostConfig': {'Runtime': runtime},
            'Mounts': list(mounts),
        }

    def bind(self, destination='/etc/docker/daemon.json', kind='bind'):
        return {'Type': kind, 'Source': '/opt/panelalpha/shared-hosting/users/u/daemon.json', 'Destination': destination}

    def test_the_daemon_json_the_engine_renders_is_reported_as_mounted(self):
        self.routes[('GET', '/containers/u/json')] = (200, self.container(mounts=[self.bind(), self.bind('/home/u', 'bind')]))
        result = self.pa_root('account-container.daemonConfigMounted', 'u')
        self.assertEqual((result.returncode, result.stdout), (0, b'1\n'), result.stderr)

    def test_an_account_carrying_its_own_daemon_json_is_not_mounted(self):
        for mounts in ([], [self.bind('/etc/docker/other.json')], [self.bind(kind='volume')]):
            with self.subTest(mounts=mounts):
                self.routes[('GET', '/containers/u/json')] = (200, self.container(mounts=mounts))
                result = self.pa_root('account-container.daemonConfigMounted', 'u')
                self.assertEqual((result.returncode, result.stdout), (0, b'0\n'), result.stderr)

    def test_only_the_account_s_own_dind_container_is_asked_at_all(self):
        for action in ('daemonConfigMounted', 'reloadDaemon'):
            for container in (self.container(runtime='runc'), self.container(label=dockerapi.SERVICE)):
                with self.subTest(action=action, container=container['HostConfig']):
                    self.routes[('GET', '/containers/u/json')] = (200, container)
                    result = self.pa_root(f'account-container.{action}', 'u')
                    self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
                    self.assertIn(b'is not its DinD account container', result.stderr)

    def named_dockerd(self):
        """A child process the host's /proc names `dockerd`, which a SIGHUP ends."""
        path = os.path.join(self.scratch, 'dockerd')
        os.symlink(sys.executable, path)
        process = subprocess.Popen([path, '-c', 'import time; time.sleep(600)'], stdin=subprocess.DEVNULL,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(process.wait)
        self.addCleanup(process.kill)
        deadline = time.monotonic() + support.SECONDS
        while innerprocesses.process_name(process.pid) != 'dockerd' and time.monotonic() < deadline:
            time.sleep(0.01)
        return process

    def processes(self, *named):
        self.routes[('GET', '/containers/u/top')] = (
            200, {'Titles': ['PID', 'COMMAND'], 'Processes': [['1', 's6-svscan'], *([str(pid), 'dockerd'] for pid in named)]})

    def test_a_reload_hangs_up_the_dockerd_of_the_account_s_own_container(self):
        dockerd = self.named_dockerd()
        self.routes[('GET', '/containers/u/json')] = (200, self.container())
        self.processes(dockerd.pid)
        result = self.pa_root('account-container.reloadDaemon', 'u')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(dockerd.wait(support.SECONDS), -signal.SIGHUP)
        asked = next(path for method, path in self.asked if urllib.parse.urlsplit(path).path == '/containers/u/top')
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(asked).query), {'ps_args': ['-eo pid,comm']})

    def test_a_container_running_no_dockerd_is_an_error(self):
        self.routes[('GET', '/containers/u/json')] = (200, self.container())
        self.processes()
        result = self.pa_root('account-container.reloadDaemon', 'u')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'container u is running no dockerd of its own', result.stderr)

    def test_a_dockerd_that_is_no_longer_below_the_container_is_never_signalled(self):
        dockerd = self.named_dockerd()
        self.routes[('GET', '/containers/u/json')] = (200, self.container(pid=self.server_pid))
        self.processes(dockerd.pid)
        result = self.pa_root('account-container.reloadDaemon', 'u')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'container u is running no dockerd of its own', result.stderr)
        self.assertIsNone(dockerd.poll())

    def test_a_host_reply_about_the_container_that_is_no_json_object_is_refused_in_its_words(self):
        for request, reply in enumerate((b'', b'[]', b'{"Name"'), start=1):
            with self.subTest(reply=reply):
                self.routes[('GET', '/containers/u/json')] = (200, reply)
                result = self.pa_root('account-container.daemonConfigMounted', 'u')
                stderr = b"pa-root: refused: the host's Docker answered /containers/u/json with no JSON object\n"
                self.assert_answered(result, protocol.EXIT_REFUSED, stderr, request)


class RunTest(FakeDaemon):
    def run_routes(self, status=0):
        self.routes[('POST', '/containers/create')] = (201, {'Id': CONTAINER_ID, 'Warnings': []})
        self.routes[('POST', f'/containers/{CONTAINER_ID}/start')] = (204, b'')
        self.routes[('POST', f'/containers/{CONTAINER_ID}/wait')] = (200, {'StatusCode': status})
        self.routes[('GET', f'/containers/{CONTAINER_ID}/logs')] = (200, Chunks([frame(1, b'out\n'), frame(2, b'err\n')]))
        self.routes[('DELETE', f'/containers/{CONTAINER_ID}')] = (204, b'')

    def test_run_binds_a_volume_and_a_path_in_the_home_then_removes_the_container(self):
        self.run_routes(2)
        scratch = self.path('homes', 'u', '.panelalpha-volume-scratch')
        result = self.ask('run', 'busybox:1.37', '2', 'project_db', '/data', 'ro', scratch, '/out', 'rw', 'tar', '-czf', '/out/x', '.')
        self.assertEqual(result.returncode, 2, result.stderr)
        self.assertEqual((result.stdout, result.stderr), (b'out\n', b'err\n'))
        body = self.body_of('POST', '/containers/create')
        self.assertEqual(body['HostConfig']['Binds'], ['project_db:/data:ro', f'{scratch}:/out:rw'])
        self.assertEqual(body['Cmd'], ['tar', '-czf', '/out/x', '.'])
        self.assertIn(('DELETE', f'/containers/{CONTAINER_ID}?force=1&v=1'), self.asked)

    def test_run_refuses_a_mount_outside_the_account_s_home(self):
        self.run_routes()
        for source in (self.path('homes', 'v', 'x'), '/etc', '/var/run/docker.sock', self.path('homes', 'u') + '/../v'):
            with self.subTest(source=source):
                result = self.ask('run', 'busybox:1.37', '1', source, '/x', 'rw', 'true')
                self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertEqual(self.asked, [])

    def test_run_refuses_a_target_holding_a_colon_before_asking_the_daemon(self):
        result = self.ask('run', 'busybox:1.37', '1', 'project_db', '/data:ro', 'rw', 'true')
        self.assertEqual((result.returncode, result.stderr),
                         (protocol.EXIT_REFUSED, b"pa-root: refused: absolute path '/data:ro' is not allowed\n"))
        self.assertEqual(self.asked, [])

    def test_run_pulls_an_image_the_daemon_does_not_have(self):
        self.run_routes()
        created = iter([(404, {'message': 'No such image: busybox:1.37'}), (201, {'Id': CONTAINER_ID})])
        self.routes[('POST', '/containers/create')] = lambda path: next(created)
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"ok"}\n']))
        self.assertEqual(self.ask('run', 'busybox:1.37', '0', 'true').returncode, 0)
        self.assertIn('/images/create?fromImage=busybox&tag=1.37', [path for _, path in self.asked])

    def test_a_run_created_without_its_id_is_an_unexpected_reply_that_starts_nothing(self):
        self.run_routes()
        self.routes[('POST', '/containers/create')] = (201, {'Warnings': []})
        result = self.ask('run', 'busybox:1.37', '0', 'true')
        self.assert_answered(result, 1, b'the Docker daemon of u answered with an unexpected Id\n')
        self.assertEqual([path for _, path in self.asked], ['/containers/create'])

    def test_a_wait_answered_without_a_status_code_is_unexpected_and_the_container_is_removed(self):
        self.run_routes()
        self.routes[('POST', f'/containers/{CONTAINER_ID}/wait')] = (200, {'Error': None})
        result = self.ask('run', 'busybox:1.37', '0', 'true')
        self.assert_answered(result, 1, b'the Docker daemon of u answered with an unexpected StatusCode\n')
        self.assertIn(('DELETE', f'/containers/{CONTAINER_ID}?force=1&v=1'), self.asked)


class FilesTest(FakeDaemon):
    def test_an_engine_file_goes_in_through_cat_on_stdin_as_the_default_user(self):
        self.make('engine', 'templates/user/dind/project/wp-cli/wp-cli.phar', b'<?php wp')
        self.routes[('POST', '/containers/app/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, HijackReadingStdin(b''))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': 0, 'Running': False})
        result = self.ask('putEngineFile', 'app', 'wp-cli', '/tmp/panelalpha-wp-cli.phar')
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.body_of('POST', '/containers/app/exec')
        self.assertEqual(body['Cmd'], ['sh', '-c', 'cat > "$1.$$" && chmod 0644 "$1.$$" && mv -f "$1.$$" "$1"', 'sh', '/tmp/panelalpha-wp-cli.phar'])
        self.assertTrue(body['AttachStdin'])
        self.assertNotIn('User', body)
        self.assertEqual(next(data for kind, _, data, _ in self.sent if kind == 'STDIN'), b'<?php wp')

    def test_only_an_engine_file_the_broker_knows(self):
        self.assertEqual(self.ask('putEngineFile', 'app', 'passwd', '/tmp/x').returncode, protocol.EXIT_REFUSED)

    def test_a_file_is_read_out_of_an_image_and_its_container_removed(self):
        self.routes[('POST', '/containers/create')] = (201, {'Id': CONTAINER_ID})
        self.routes[('GET', f'/containers/{CONTAINER_ID}/archive')] = (200, tar_of('passwd', PASSWD))
        self.routes[('DELETE', f'/containers/{CONTAINER_ID}')] = (204, b'')
        result = self.ask('readImageFile', 'wordpress:6', '/etc/passwd')
        self.assertEqual((result.returncode, result.stdout), (0, PASSWD))
        body = self.body_of('POST', '/containers/create')
        self.assertEqual((body['Entrypoint'], body['NetworkDisabled']), (['/pa-none'], True))
        self.assertIn(('DELETE', f'/containers/{CONTAINER_ID}?force=1&v=1'), self.asked)

    def test_a_link_in_the_image_is_no_file(self):
        self.routes[('POST', '/containers/create')] = (201, {'Id': CONTAINER_ID})
        self.routes[('GET', f'/containers/{CONTAINER_ID}/archive')] = (200, tar_of('passwd', b'', tarfile.SYMTYPE))
        self.routes[('DELETE', f'/containers/{CONTAINER_ID}')] = (204, b'')
        result = self.ask('readImageFile', 'wordpress:6', '/etc/passwd')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'no regular file', result.stderr)
        self.assertIn(('DELETE', f'/containers/{CONTAINER_ID}?force=1&v=1'), self.asked)

    def test_a_container_to_read_from_created_without_its_id_is_an_unexpected_reply(self):
        self.routes[('POST', '/containers/create')] = (201, {})
        result = self.ask('readImageFile', 'wordpress:6', '/etc/passwd')
        self.assert_answered(result, 1, b'the Docker daemon of u answered with an unexpected Id\n')
        self.assertEqual([urllib.parse.urlsplit(path).path for _, path in self.asked], ['/containers/create'])

    def test_a_stopped_container_has_no_sockets_to_read(self):
        self.routes[('GET', '/containers/app/json')] = (200, {'Id': CONTAINER_ID, 'State': {'Running': False, 'Pid': 0}})
        result = self.ask('listeningSockets', 'app')
        self.assertEqual((result.returncode, result.stdout), (0, b''))


class RemoveTest(FakeDaemon):
    def test_force_removal_ignores_a_missing_container_and_reports_a_refusal(self):
        self.routes[('DELETE', '/containers/a')] = (204, b'')
        self.routes[('DELETE', '/containers/busy')] = (409, {'message': 'cannot remove container'})
        self.assertEqual(self.ask('removeContainers', 'force', 'volumes', 'a', 'gone').returncode, 0)
        self.assertIn(('DELETE', '/containers/a?force=1&v=1'), self.asked)
        result = self.ask('removeContainers', '', '', 'busy')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'cannot remove container', result.stderr)


class StorageTest(FakeDaemon):
    def test_volumes(self):
        self.routes[('POST', '/volumes/create')] = (201, {'Name': 'project_db', 'Driver': 'local', 'Mountpoint': '/x'})
        self.routes[('GET', '/volumes/project_db')] = (200, {'Name': 'project_db', 'Driver': 'local', 'Labels': None})
        self.routes[('GET', '/volumes')] = (200, {'Volumes': [{'Name': 'a', 'Driver': 'local'}], 'Warnings': None})
        self.assertEqual(self.ask('createVolume', 'project_db').stdout, b'project_db\n')
        self.assertEqual(self.body_of('POST', '/volumes/create'), {'Name': 'project_db'})
        self.assertEqual(json.loads(self.ask('volumes', 'project_db', 'gone').stdout),
                         [{'Name': 'project_db', 'Driver': 'local', 'Labels': None}, None])
        self.assertEqual(json.loads(self.ask('listVolumes').stdout), [{'Name': 'a', 'Driver': 'local'}])

    def test_system_prune_asks_what_the_cli_does_in_its_order(self):
        for path, freed in (('/containers/prune', 1), ('/networks/prune', None), ('/volumes/prune', 2),
                            ('/images/prune', 3), ('/build/prune', 4)):
            self.routes[('POST', path)] = (200, {'SpaceReclaimed': freed} if freed is not None else {})
        result = self.ask('pruneSystem', 'volumes')
        self.assertEqual((result.returncode, result.stdout), (0, b'Total reclaimed space: 10B\n'))
        self.assertEqual([urllib.parse.unquote_plus(path) for _, path in self.asked], [
            '/containers/prune', '/networks/prune', '/volumes/prune', '/images/prune?filters={"dangling": ["false"]}',
            '/build/prune?all=1',
        ])
        self.ask('pruneSystem', '')
        self.assertNotIn('/volumes/prune', [path for _, path in self.asked[5:]])

    def test_the_other_prunes(self):
        for path in ('/volumes/prune', '/images/prune', '/build/prune'):
            self.routes[('POST', path)] = (200, {'SpaceReclaimed': 5})
        for action in ('pruneAnonymousVolumes', 'pruneImages', 'pruneBuildCache'):
            self.assertEqual(self.ask(action).returncode, 0)
        self.assertEqual(urllib.parse.unquote_plus(self.asked[0][1]), '/volumes/prune?filters={"label": ["com.docker.volume.anonymous"]}')

    def test_build_cache_bytes_total_every_record(self):
        self.routes[('GET', '/system/df')] = (200, {'BuildCache': [{'ID': 'a', 'Size': 100}, {'ID': 'b', 'Size': 23}]})
        self.assertEqual(self.ask('buildCacheBytes').stdout, b'123\n')
        self.routes[('GET', '/system/df')] = (200, {'BuildCache': None})
        self.assertEqual(self.ask('buildCacheBytes').stdout, b'0\n')


class LoggedInPullTest(FakeDaemon):
    def login(self, auths):
        directory = self.directory('homes', 'u/.panelalpha-registry-0123456789abcdef')
        with open(os.path.join(directory, 'config.json'), 'w') as f:
            json.dump({'auths': auths}, f)
        return directory

    def test_the_login_for_the_image_s_registry_goes_as_x_registry_auth(self):
        directory = self.login({'ghcr.io': {'auth': base64.b64encode(b'bot:s3cret').decode()},
                                'https://index.docker.io/v1/': {'auth': base64.b64encode(b'hub:other').decode()}})
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"ok"}\n']))
        self.assertEqual(self.ask('pull', 'ghcr.io/acme/app:1', directory).returncode, 0)
        header = next(headers for method, _, _, headers in self.sent if method == 'POST').get('X-Registry-Auth')
        self.assertEqual(json.loads(base64.urlsafe_b64decode(header)),
                         {'username': 'bot', 'password': 's3cret', 'serveraddress': 'ghcr.io'})
        self.assertNotIn('s3cret', ''.join(self.audit_lines()))

    def test_a_docker_hub_image_takes_the_hub_s_login(self):
        directory = self.login({'https://index.docker.io/v1/': {'auth': base64.b64encode(b'hub:other').decode()}})
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"ok"}\n']))
        self.ask('pull', 'acme/app:1', directory)
        header = next(headers for method, _, _, headers in self.sent if method == 'POST').get('X-Registry-Auth')
        self.assertEqual(json.loads(base64.urlsafe_b64decode(header))['serveraddress'], 'https://index.docker.io/v1/')

    def test_only_the_account_s_own_login_directory(self):
        for directory in (self.path('homes', 'v', '.panelalpha-registry-0123456789abcdef'),
                          self.path('homes', 'u', '.docker'), '/root/.docker'):
            with self.subTest(directory=directory):
                self.assertEqual(self.ask('pull', 'acme/app:1', directory).returncode, protocol.EXIT_REFUSED)

    def test_a_linked_config_is_not_read(self):
        directory = self.directory('homes', 'u/.panelalpha-registry-0123456789abcdef')
        secret = self.make('homes', 'v/config.json', b'{"auths":{"ghcr.io":{"auth":"Ym90OnN0b2xlbg=="}}}')
        os.symlink(secret, os.path.join(directory, 'config.json'))
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"ok"}\n']))
        self.assertEqual(self.ask('pull', 'ghcr.io/acme/app:1', directory).returncode, 0)
        self.assertNotIn('X-Registry-Auth', next(headers for method, _, _, headers in self.sent if method == 'POST'))

    def test_a_login_through_a_linked_home_is_not_read(self):
        elsewhere = self.directory('homes', 'elsewhere/.panelalpha-registry-0123456789abcdef')
        with open(os.path.join(elsewhere, 'config.json'), 'w') as f:
            json.dump({'auths': {'ghcr.io': {'auth': base64.b64encode(b'bot:stolen').decode()}}}, f)
        os.symlink(self.path('homes', 'elsewhere'), self.path('homes', 'u'))
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"ok"}\n']))
        directory = self.path('homes', 'u', '.panelalpha-registry-0123456789abcdef')
        self.assertEqual(self.ask('pull', 'ghcr.io/acme/app:1', directory).returncode, 0)
        self.assertNotIn('X-Registry-Auth', next(headers for method, _, _, headers in self.sent if method == 'POST'))

    def test_registry_of_a_reference(self):
        self.assertEqual(registrylogin.registry_of('node:22'), 'docker.io')
        self.assertEqual(registrylogin.registry_of('acme/app'), 'docker.io')
        self.assertEqual(registrylogin.registry_of('GHCR.io/acme/app'), 'ghcr.io')
        self.assertEqual(registrylogin.registry_of('localhost/app'), 'localhost')
        self.assertEqual(registrylogin.registry_of('reg:5000/app'), 'reg:5000')


class LoggedInPullWithoutOpenat2Test(LoggedInPullTest):
    """The same logins on a kernel without openat2, every component opened on its own with O_NOFOLLOW."""

    def setUp(self):
        self.saved = paths.openat2_available
        paths.openat2_available = False
        super().setUp()

    def tearDown(self):
        super().tearDown()
        paths.openat2_available = self.saved


UNSHARE = ('unshare', '--user', '--map-root-user', '--pid', '--fork', '--mount-proc')


def can_unshare():
    return shutil.which('unshare') is not None and subprocess.run([*UNSHARE, 'true'], capture_output=True).returncode == 0


@unittest.skipUnless(can_unshare(), 'needs unprivileged user and pid namespaces')
class InnerProcessTest(unittest.TestCase):
    def setUp(self):
        self.child = subprocess.Popen([*UNSHARE, 'sh', '-c', 'sleep 60 & wait'])
        self.addCleanup(self.child.wait)
        self.addCleanup(self.child.kill)
        deadline = time.monotonic() + 10
        self.init = None
        while self.init is None and time.monotonic() < deadline:
            self.init = next((int(pid) for pid in os.listdir('/proc') if pid.isdigit() and self.nspid(pid) == ['1']
                              and self.parent(pid) == self.child.pid), None)
            time.sleep(0.05)

    @staticmethod
    def nspid(pid):
        try:
            with open(f'/proc/{pid}/status') as f:
                return next((line.split()[2:] for line in f if line.startswith('NSpid:')), None)
        except OSError:
            return None

    @staticmethod
    def parent(pid):
        with open(f'/proc/{pid}/status') as f:
            return int(next(line.split()[1] for line in f if line.startswith('PPid:')))

    def test_the_account_s_pid_names_one_host_process_in_its_namespace(self):
        self.assertIsNotNone(self.init)
        sleeper = None
        deadline = time.monotonic() + 10
        while sleeper is None and time.monotonic() < deadline:
            sleeper = innerprocesses.host_process('/proc', self.init, 2)
            time.sleep(0.05)
        self.assertIsNotNone(sleeper)
        try:
            self.assertIn(b'local_address', innerprocesses.socket_tables(sleeper))
        finally:
            os.close(sleeper)

    def test_a_pid_from_outside_the_account_names_nothing(self):
        self.assertIsNone(innerprocesses.host_process('/proc', self.init, os.getpid()))


if __name__ == '__main__':
    unittest.main()
