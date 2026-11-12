"""pa-broker runs an action on a request process of its own, checked and audited as a socket request is, on no socket."""

import json
import os
import subprocess
import sys
import tempfile
import time
import unittest

import support
from rootbroker import direct, peer, values
from rootbroker.actions import Action, Param, registry
from rootbroker.actions.sequence import Command, reads
from rootbroker.audit import PREFIX

PA_BROKER = os.path.join(os.path.dirname(support.HERE), 'pa-broker')
ACTIONS = {
    **registry(),
    'test.cat': Action((), lambda: ['cat']),
    'test.exit': Action((Param(values.integer),), lambda code: ['sh', '-c', f'echo out; echo err >&2; exit {code}']),
    'test.secret': Command(reads(values.name, values.name), lambda context, name, secret: ['true'], secret=(1,)),
    'test.net': Command(lambda given: (), lambda context: ['true'], host_only=True, namespaces=('net',)),
}


class Cli:
    """pa-broker run by the test as the broker's container runs it, its request processes' audit lines in a log here."""

    direct_uids = None

    def setUp(self):
        super().setUp()
        self.log = os.path.join(self.base, 'broker.log')
        self.entered = os.path.join(self.base, 'entered')

    def broker(self):
        uids = {os.getuid()} if self.direct_uids is None else self.direct_uids
        return direct.Direct(uids, policy=self.policy, actions=ACTIONS, enter_host=self.enter)

    def enter(self, namespaces):
        with open(self.entered, 'a') as f:
            f.write(json.dumps(namespaces) + '\n')

    def container_log(self):
        fd = os.open(self.log, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        return fd, os.dup(fd)

    def pa_broker(self, *argv, stdin=b''):
        """pa-broker on `argv` with this test's stdin, its stdout and stderr read back, its request process's in the log."""
        streams = [tempfile.TemporaryFile(dir=self.base) for _ in range(3)]
        streams[0].write(stdin)
        streams[0].seek(0)
        pid = os.fork()
        if pid == 0:
            self.run_cli(argv, streams)
        _, status = os.waitpid(pid, 0)
        out, err = (self.read(stream) for stream in streams[1:])
        streams[0].close()
        return subprocess.CompletedProcess(argv, os.waitstatus_to_exitcode(status), out, err)

    def run_cli(self, argv, streams):
        code = 99
        try:
            for standard, stream in enumerate(streams):
                os.dup2(stream.fileno(), standard)
            code = direct.main(list(argv), broker=self.broker, log=self.container_log)
        finally:
            os._exit(code)

    @staticmethod
    def read(stream):
        stream.seek(0)
        with stream:
            return stream.read()

    def logged(self, count=1):
        """The audit entries pa-broker's request processes wrote, once there are `count` of them."""
        deadline = time.monotonic() + support.SECONDS
        while time.monotonic() < deadline:
            entries = self.log_entries()
            if len(entries) >= count:
                return entries
            time.sleep(0.02)
        self.fail(f'{count} audit lines expected, {len(self.log_entries())} written')

    def log_entries(self):
        try:
            with open(self.log) as f:
                return [json.loads(line.removeprefix(PREFIX)) for line in f if line.startswith(PREFIX)]
        except FileNotFoundError:
            return []


class Running(Cli, support.Serving):
    """pa-broker beside a broker serving the same table on its socket, both entering no namespace."""

    actions = ACTIONS

    def serve(self, server):
        server.broker.enter_host = lambda namespaces: None
        super().serve(server)


class ActionTest(Running):
    def test_the_action_s_output_and_exit_code_are_the_caller_s(self):
        result = self.pa_broker('test.exit', '--', '3')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'out\n', b'err\n'))
        self.assertEqual(self.pa_broker('test.exit', '--', '0').returncode, 0)

    def test_stdin_reaches_the_action(self):
        data = os.urandom(1 << 20)
        result = self.pa_broker('test.cat', '--', stdin=data)
        self.assertEqual((result.returncode, result.stdout), (0, data))

    def test_an_action_of_the_broker_s_table_runs_on_its_roots(self):
        self.directory('homes', 'u')
        result = self.pa_broker('files.listAllNames', '--', self.roots['homes'])
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'u\n', b''))

    def test_a_host_action_enters_the_namespaces_it_names_and_only_with_host(self):
        self.assertEqual(self.pa_broker('--host', 'test.net', '--').returncode, 0)
        with open(self.entered) as f:
            self.assertEqual(f.read(), '["net"]\n')
        refused = self.pa_broker('test.net', '--')
        self.assertEqual(refused.returncode, 77)
        self.assertIn(b"runs only in the host's namespaces", refused.stderr)

    def test_a_wrong_call_is_a_usage_error_and_runs_nothing(self):
        for argv in ([], ['files.read'], ['--', 'files.read'], ['files.read', '/a']):
            with self.subTest(argv=argv):
                result = self.pa_broker(*argv)
                self.assertEqual(result.returncode, 64)
                self.assertIn(b'usage: pa-broker', result.stderr)
        self.assertEqual(self.log_entries(), [])

    def test_a_request_process_that_dies_is_a_protocol_error(self):
        self.broker = broken_broker
        result = self.pa_broker('test.exit', '--', '0')
        self.assertEqual(result.returncode, 76)
        self.assertTrue(result.stderr.startswith(b'pa-broker: '), result.stderr)
        with open(self.log) as f:
            self.assertIn('RuntimeError: broken', f.read())


class CheckTest(Running):
    def test_values_are_refused_as_the_socket_refuses_them(self):
        target = self.directory('homes', 'u')
        for request in [('files.runAnything', 'sh'), ('files.read', '/a', '/b'), ('files.rename', target),
                        ('files.changeMode', '--reference=/etc/shadow', target), ('files.changeOwner', 'root root', target),
                        ('files.removeEntriesNamed', target, '-o'), ('files.removeTree', '/etc'), ('test.exit', 'three')]:
            with self.subTest(action=request[0]):
                socket_answer = self.pa_root(*request)
                cli_answer = self.pa_broker(request[0], '--', *request[1:])
                self.assertEqual(socket_answer.returncode, 77)
                self.assertEqual((cli_answer.returncode, cli_answer.stdout), (77, b''))
                self.assertEqual(cli_answer.stderr.replace(b'pa-broker: ', b'pa-root: '), socket_answer.stderr)
        self.assertTrue(os.path.isdir(target))

    def test_host_is_checked_as_the_socket_checks_it(self):
        for host, name in ((True, 'test.secret'), (False, 'test.net')):
            with self.subTest(action=name):
                socket_answer = self.pa_root(name, *(['a', 'b'] if host else []), host=host)
                cli_answer = self.pa_broker(*(['--host'] if host else []), name, '--', *(['a', 'b'] if host else []))
                self.assertEqual(socket_answer.returncode, 77)
                self.assertEqual((cli_answer.returncode, cli_answer.stderr.replace(b'pa-broker: ', b'pa-root: ')),
                                 (socket_answer.returncode, socket_answer.stderr))


class AuditTest(Running):
    def test_the_audit_line_is_a_socket_request_s_marked_as_pa_broker_s(self):
        self.pa_root('test.secret', 'name', 'hidden')
        self.pa_broker('test.secret', '--', 'name', 'hidden')
        socket_entry, (cli_entry,) = self.audited(), self.logged()
        self.assertEqual(cli_entry['via'], 'pa-broker')
        self.assertEqual(set(cli_entry) - {'via'}, set(socket_entry))
        self.assertEqual((cli_entry['action'], cli_entry['values'], cli_entry['exit']), ('test.secret', ['name', '<secret>'], 0))
        self.assertEqual(cli_entry['peer_uid'], os.getuid())
        self.assertNotIn('via', socket_entry)
        with open(self.log) as f:
            self.assertNotIn('hidden', f.read())

    def test_stdin_is_never_logged(self):
        self.pa_broker('test.cat', '--', stdin=b'a password nobody may log')
        self.assertEqual(self.logged()[0]['exit'], 0)
        with open(self.log) as f:
            self.assertNotIn('password', f.read())

    def test_a_refused_request_is_audited_with_why(self):
        self.pa_broker('files.runAnything', '--', 'sh')
        (entry,) = self.logged()
        self.assertEqual((entry['via'], entry['exit'], entry['action']), ('pa-broker', 77, 'files.runAnything'))
        self.assertIn("no action 'files.runAnything'", entry['refused'])


class CallerTest(Running):
    direct_uids = {54321}

    def test_a_caller_that_is_not_root_is_refused_and_audited(self):
        target = self.directory('homes', 'u')
        result = self.pa_broker('files.removeTree', '--', target)
        self.assertEqual(result.returncode, 77)
        self.assertIn(f'pa-broker: refused: uid {os.getuid()} may not run pa-broker'.encode(), result.stderr)
        self.assertTrue(os.path.isdir(target))
        self.assertIn('may not run pa-broker', self.logged()[0]['refused'])


class NoSocketTest(Cli, support.Tree):
    def test_it_runs_while_no_broker_listens(self):
        self.directory('homes', 'u')
        env = {**os.environ, 'PA_ROOT_SOCKET': os.path.join(self.base, 'root.sock')}
        pa_root = subprocess.run([sys.executable, '-I', support.CLIENT, 'files.listAllNames', '--', self.roots['homes']],
                                 capture_output=True, env=env, timeout=support.SECONDS)
        self.assertEqual(pa_root.returncode, 69)
        result = self.pa_broker('files.listAllNames', '--', self.roots['homes'])
        self.assertEqual((result.returncode, result.stdout), (0, b'u\n'))


def broken_broker():
    raise RuntimeError('broken')


class LogTest(unittest.TestCase):
    def test_the_log_is_the_stdout_and_stderr_of_the_container_s_first_process(self):
        out_r, out_w = os.pipe()
        err_r, err_w = os.pipe()
        first = support.Sleeper(stdout=out_w, stderr=err_w)
        os.close(out_w)
        os.close(err_w)
        try:
            log = direct.container_log(lambda: first.pid)
            os.write(log[0], b'to stdout\n')
            os.write(log[1], b'to stderr\n')
            for fd in log:
                os.close(fd)
            self.assertEqual((os.read(out_r, 64), os.read(err_r, 64)), (b'to stdout\n', b'to stderr\n'))
        finally:
            first.close()

    def test_without_the_container_s_first_process_nothing_runs(self):
        with self.assertRaises(OSError):
            direct.container_log(lambda: None)

    def test_pa_broker_says_so_and_runs_nothing_where_docker_names_no_container(self):
        if peer.own_pid() is not None:
            self.skipTest('runs in a container Docker names')
        with tempfile.TemporaryDirectory() as base:
            marker = os.path.join(base, 'touched')
            result = subprocess.run([sys.executable, '-I', PA_BROKER, 'files.touch', '--', marker], capture_output=True)
            self.assertEqual(result.returncode, 69)
            self.assertIn(b"pa-broker: the broker's log cannot be written, so nothing runs", result.stderr)
            self.assertFalse(os.path.exists(marker))

    def test_pa_broker_called_wrongly_says_how(self):
        result = subprocess.run([sys.executable, '-I', PA_BROKER], capture_output=True)
        self.assertEqual((result.returncode, result.stderr), (64, (direct.USAGE + '\n').encode()))


if __name__ == '__main__':
    unittest.main()
