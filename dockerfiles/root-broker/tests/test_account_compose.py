import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest

import support  # noqa: F401
from rootbroker import accountdaemon, protocol, sandbox
from test_account_run import FakeDaemon

CLIENT = f'''#!{sys.executable}
import os, resource, socket, sys, time
def connected():
    conn = socket.socket(socket.AF_UNIX)
    conn.connect(os.environ['DOCKER_HOST'][len('unix://'):])
    return conn
def pinged(conn):
    conn.sendall(b'GET /_ping HTTP/1.1\\r\\nHost: docker\\r\\nConnection: close\\r\\n\\r\\n')
    reply = b''
    while chunk := conn.recv(65536):
        reply += chunk
    return reply.split(b'\\r\\n')[0].decode() + ' ' + reply.rsplit(b'\\r\\n', 1)[-1].decode()
if 'sleep' in sys.argv:
    with open(os.path.join(os.environ['HOME'], 'client.pid'), 'w') as f:
        f.write(str(os.getpid()))
    time.sleep(60)
if 'limits' in sys.argv:
    print(*(resource.getrlimit(kind) for kind in {tuple(kind for kind, _ in sandbox.LIMITS)}))
    print(open('/proc/self/oom_score_adj').read().strip())
    sys.exit(0)
while 'chatty' in sys.argv:
    print('line', flush=True)
    time.sleep(0.05)
if 'crowd' in sys.argv:
    crowd = [connected() for _ in range(3)]
    print('third closed', crowd[2].recv(1) == b'')
    for conn in crowd[:2]:
        print('daemon', pinged(conn))
    sys.exit(0)
reply = pinged(connected())
print('argv', ' '.join(sys.argv[1:]))
print('cwd', os.getcwd())
print('config', os.environ.get('DOCKER_CONFIG', '-'))
print('daemon', reply)
print('progress', file=sys.stderr)
sys.exit(7 if 'fail' in sys.argv else 0)
'''


class Compose(FakeDaemon):
    """The docker client against the account's daemon, with a fake client that pings what DOCKER_HOST names, and the
    sandbox's `settings` for the broker the test forks."""

    settings = {}

    def setUp(self):
        self.client_dir = tempfile.mkdtemp(prefix='broker-client-')
        self.client = os.path.join(self.client_dir, 'docker')
        with open(self.client, 'w') as f:
            f.write(CLIENT)
        os.chmod(self.client, 0o755)
        for name, value in {'CONFINED': False, 'RUN': sandbox.RUN, 'DOCKER': self.client, **self.settings}.items():
            self.addCleanup(setattr, sandbox, name, getattr(sandbox, name))
            setattr(sandbox, name, value)
        super().setUp()
        self.home = self.directory('homes', 'u')
        self.directory('homes', 'u/project')
        self.routes[('GET', '/_ping')] = (200, b'OK')

    def tearDown(self):
        super().tearDown()
        shutil.rmtree(self.client_dir, ignore_errors=True)

    def broker_policy(self):
        sandbox.RUN = os.path.join(self.base, 'run')
        os.makedirs(sandbox.RUN, exist_ok=True)
        return super().broker_policy()

    def compose(self, workdir, environment, *argv, timeout=30, as_whom=''):
        pairs = [value for pair in environment for value in pair]
        return self.pa_root('account-compose.run', 'u', as_whom, workdir, str(len(environment)), *pairs, *argv,
                            timeout=timeout)


class ComposeTest(Compose):
    def test_the_client_talks_to_the_account_s_daemon_and_its_output_streams_back(self):
        result = self.compose(f'{self.home}/project', [('DOCKER_CONFIG', f'{self.home}/.panelalpha-docker')], 'compose', 'ps')

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.decode().splitlines(), [
            'argv compose ps',
            f'cwd {self.home}/project',
            f'config {self.home}/.panelalpha-docker',
            'daemon HTTP/1.1 200 OK OK',
        ])
        self.assertEqual(result.stderr, b'progress\n')
        self.assertIn(('GET', '/_ping'), self.asked)

    def test_the_exit_code_is_the_client_s(self):
        self.assertEqual(self.compose('', [], 'compose', 'ps', 'fail').returncode, 7)

    def test_a_client_standing_in_for_root_is_one_it_runs_too(self):
        self.assertEqual(self.compose('', [], 'compose', 'ps', as_whom='root').returncode, 0)
        self.assertEqual(self.compose('', [], 'compose', 'ps', as_whom='admin').returncode, protocol.EXIT_REFUSED)

    def test_without_a_workdir_the_client_starts_in_the_home(self):
        self.assertIn(f'cwd {self.home}', self.compose('', [], 'buildx', 'build', '.').stdout.decode().splitlines())

    def test_what_it_runs_and_where_is_refused_outside_the_account(self):
        for workdir, environment, argv in (
            ('', [], ['ps']),
            ('', [], ['buildx', 'bake']),
            ('/etc', [], ['compose', 'ps']),
            ('', [('DOCKER_HOST', 'tcp://10.0.0.1:2375')], ['compose', 'ps']),
            ('', [('DOCKER_CONTEXT', 'remote')], ['compose', 'ps']),
            ('', [('PATH', '/tmp')], ['compose', 'ps']),
            ('', [('DOCKER_CLI_PLUGIN_ORIGINAL_CLI_COMMAND', 'x')], ['compose', 'ps']),
            ('', [('DOCKER_CONFIG', '/root/.docker')], ['compose', 'ps']),
            ('', [('PWD', f'{self.home}/../v')], ['compose', 'ps']),
        ):
            with self.subTest(workdir=workdir, environment=environment, argv=argv):
                result = self.compose(workdir, environment, *argv)
                self.assertEqual(result.returncode, protocol.EXIT_REFUSED, result.stderr)
        self.assertNotIn(('GET', '/_ping'), self.asked)

    def test_only_the_variables_the_engine_sets_are_taken(self):
        project = f'{self.home}/project'
        result = self.compose('', [('PWD', project), ('DOCKER_CONFIG', f'{self.home}/.docker')], 'compose', 'ps')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f'config {self.home}/.docker', result.stdout.decode().splitlines())
        for name in ('LD_AUDIT', 'DOCKER_API_VERSION', 'COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'BUILDX_CONFIG',
                     'BUILDX_BUILDER', 'HOME', 'Pwd'):
            with self.subTest(name=name):
                result = self.compose('', [('PWD', project), (name, project)], 'compose', 'ps')
                refusal = f"pa-root: refused: client environment variable '{name}' is not allowed\n"
                self.assertEqual((result.returncode, result.stderr), (protocol.EXIT_REFUSED, refusal.encode()))

    def test_every_compose_subcommand_the_engine_sends_runs(self):
        project = f'{self.home}/project'
        env, file = f'{project}/.env', f'{project}/docker-compose.yml'
        override = f'{project}/docker-compose.override.yml'
        for argv in (
            ['--project-directory', project, '--env-file', env, '-f', file, 'up', '-d', '--remove-orphans'],
            ['--project-directory', project, '-f', file, 'down', '--remove-orphans'],
            ['--project-directory', project, '-f', file, 'down', '-v', '--remove-orphans'],
            ['--project-directory', project, '-f', file, 'stop'],
            ['--project-directory', project, '-f', file, 'start'],
            ['--project-directory', project, '-f', file, 'restart', '--', 'app'],
            ['--project-directory', project, '-f', file, 'pull'],
            ['--project-directory', project, '-f', file, 'pull', '--policy', 'missing', 'app'],
            ['--project-directory', project, '-f', file, 'build', 'app'],
            ['--project-directory', project, '-f', file, 'run', '--rm', '-T', 'app'],
            ['--project-directory', project, '-f', file, 'create'],
            ['--project-directory', project, '-f', file, 'ps', '--format', 'json', '--all'],
            ['--project-directory', project, '-f', file, 'logs', '--tail=200', '--no-color', '--', 'app'],
            ['--project-directory', project, '-f', file, 'config', '--format', 'json'],
            ['--project-directory', project, '--profile', '*', '-f', file, 'config', '--services'],
            ['--project-directory', project, '-f', file, '-f', override, '--project-name', 'app-next', 'up',
             '-d', '--no-deps', '--no-build', '--pull', 'never', 'app'],
            ['--project-directory=' + project, '--env-file=' + env, '--ansi', 'never', '--progress', 'plain',
             '--parallel', '2', '--compatibility', '--dry-run', '-p', 'app', '-f', file, 'ps'],
        ):
            with self.subTest(argv=argv):
                result = self.compose(project, [], 'compose', *argv)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.decode().splitlines()[0], 'argv '+' '.join(['compose', *argv]))

    def test_a_compose_subcommand_the_engine_never_sends_is_refused(self):
        for argv in (
            ['compose'],
            ['compose', 'exec', '-it', 'app', 'sh'],
            ['compose', 'cp', 'app:/etc/passwd', '.'],
            ['compose', 'attach', 'app'],
            ['compose', 'version'],
            ['compose', '--verbose', 'ps'],
            ['compose', '--project-directory'],
        ):
            with self.subTest(argv=argv):
                self.assertEqual(self.compose('', [], *argv).returncode, protocol.EXIT_REFUSED)
        self.assertNotIn(('GET', '/_ping'), self.asked)

    def test_the_client_runs_under_its_limits_and_is_the_first_the_oom_killer_ends(self):
        result = self.compose('', [], 'compose', 'ps', 'limits')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.decode().splitlines(), [
            ' '.join(str((value, value)) for _, value in sandbox.LIMITS), str(sandbox.OOM_SCORE_ADJ),
        ])

    def test_a_caller_that_hangs_up_stops_the_client(self):
        with self.assertRaises(subprocess.TimeoutExpired):
            self.compose('', [], 'compose', 'logs', 'sleep', timeout=2)

        pid = int(open(os.path.join(self.home, 'client.pid')).read())
        deadline = time.monotonic() + sandbox.STOP_SECONDS + 5
        while time.monotonic() < deadline and alive(pid):
            time.sleep(0.2)
        self.assertFalse(alive(pid), 'the client still runs')


class CrowdedRelayTest(Compose):
    """A relay that takes two of the client's connections at once."""

    settings = {'CONNECTIONS': 2}

    def test_a_connection_past_the_cap_is_closed_and_the_caller_told(self):
        result = self.compose('', [], 'compose', 'ps', 'crowd')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.decode().splitlines(), [
            'third closed True', 'daemon HTTP/1.1 200 OK OK', 'daemon HTTP/1.1 200 OK OK',
        ])
        self.assertEqual(result.stderr, b'docker compose for u opened more than 2 connections to its daemon at once; '
                                        b'the broker closed 1 of them\n')


class SilentDaemon(Compose):
    """An account daemon that queues connections and never takes or answers one: `backlog` sizes its queue, and
    `waiting` connections of the test's own wait in it."""

    backlog = 8
    waiting = 0

    def setUp(self):
        super().setUp()
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.addCleanup(listener.close)
        listener.bind(self.silent)
        listener.listen(self.backlog)
        for _ in range(self.waiting):
            conn = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            self.addCleanup(conn.close)
            conn.connect(self.silent)

    @property
    def silent(self):
        return os.path.join(self.scratch, 'silent.sock')

    def open_daemon(self, account, accounts_directory):
        return accountdaemon.Daemon(account.name, os.open(self.silent, os.O_PATH | os.O_CLOEXEC))


class DeadlineTest(SilentDaemon):
    """A request under a deadline of two seconds."""

    settings = {'DEADLINE_SECONDS': 2}

    def test_the_deadline_stops_the_client_and_says_so(self):
        started = time.monotonic()
        result = self.compose('', [], 'compose', 'up', '-d')
        self.assertLess(time.monotonic() - started, 2 + sandbox.STOP_SECONDS)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (
            protocol.EXIT_UNAVAILABLE, b'', b'docker compose for u did not finish within 2s; the broker stopped it\n'))

    def test_a_client_that_keeps_writing_is_stopped_at_the_deadline_too(self):
        started = time.monotonic()
        result = self.compose('', [], 'compose', 'logs', 'chatty')
        self.assertLess(time.monotonic() - started, 2 + sandbox.STOP_SECONDS)
        self.assertEqual((result.returncode, result.stderr), (
            protocol.EXIT_UNAVAILABLE, b'docker compose for u did not finish within 2s; the broker stopped it\n'))
        self.assertTrue(result.stdout.startswith(b'line\nline\n'), result.stdout)


class FullQueueTest(SilentDaemon):
    """A daemon whose queue is full."""

    backlog = 0
    waiting = 1

    def test_the_relay_hangs_up_on_the_client_at_once(self):
        result = self.compose('', [], 'compose', 'ps', timeout=10)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertTrue(result.stderr.endswith(b'ConnectionResetError: [Errno 104] Connection reset by peer\n'),
                        result.stderr)


def alive(pid):
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    with open(f'/proc/{pid}/stat') as stat:
        return stat.read().split()[2] != 'Z'


if __name__ == '__main__':
    unittest.main()
