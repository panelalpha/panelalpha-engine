"""The dying action publishes its pid by rename and is watched through a pidfd, so a reused pid cannot pass."""

import json
import os
import select
import time
import unittest

import support
from rootbroker import protocol, values
from rootbroker.actions import Action, Param, registry

STREAMING = {
    **registry(),
    'test.cat': Action((), lambda: ['cat']),
    'test.exit': Action((Param(values.integer),), lambda code: ['sh', '-c', f'echo out; echo err >&2; exit {code}']),
    'test.killed': Action((), lambda: ['sh', '-c', 'kill -TERM $$']),
    'test.sleep': Action(
        (Param(values.path),), lambda pidfile: ['sh', '-c', 'echo $$ > "$0.new" && mv "$0.new" "$0" && exec sleep 600', pidfile],
    ),
}


class StreamingTest(support.Serving):
    actions = STREAMING

    def test_stdin_reaches_the_action_and_its_stdout_comes_back_whole(self):
        data = os.urandom(3 << 20)
        result = self.pa_root('test.cat', stdin=data)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, data)

    def test_stdout_stderr_and_the_exit_code_are_the_actions(self):
        result = self.pa_root('test.exit', '3')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'out\n', b'err\n'))
        self.assertEqual(self.pa_root('test.exit', '0').returncode, 0)

    def test_an_action_killed_by_a_signal_exits_as_a_shell_reports_it(self):
        self.assertEqual(self.pa_root('test.killed').returncode, 128 + 15)

    def test_the_action_dies_with_the_client(self):
        pidfile = self.path('exchange', 'pid')
        conn = self.connect()
        conn.sendall(protocol.request('test.sleep', [pidfile], False))
        pid = self.wait_for_pid(pidfile)
        watch = os.pidfd_open(pid)
        try:
            conn.close()
            self.assertTrue(select.select([watch], [], [], support.SECONDS)[0], 'the action outlived its client')
        finally:
            os.close(watch)
        self.assertTrue(self.broker_alive())

    def test_the_action_dies_when_the_client_sends_a_broken_frame(self):
        pidfile = self.path('exchange', 'pid')
        conn = self.connect()
        conn.sendall(protocol.request('test.sleep', [pidfile], False))
        pid = self.wait_for_pid(pidfile)
        watch = os.pidfd_open(pid)
        try:
            conn.sendall(protocol.HEADER.pack(protocol.STDIN, protocol.MAX_PAYLOAD + 1))
            self.assertTrue(select.select([watch], [], [], support.SECONDS)[0], 'the action outlived a broken frame')
        finally:
            os.close(watch)
            conn.close()
        self.assertTrue(self.broker_alive())

    def test_the_audit_line_names_the_peer_and_the_action_but_never_stdin(self):
        self.pa_root('test.cat', stdin=b'a password nobody may log')
        entry = json.loads(self.audit_lines()[-1].removeprefix('pa-root-audit '))
        self.assertEqual(entry['action'], 'test.cat')
        self.assertEqual(entry['peer_uid'], os.getuid())
        self.assertEqual(entry['exit'], 0)
        self.assertIn('seconds', entry)
        self.assertNotIn('password', self.audit_lines()[-1])

    def wait_for_pid(self, pidfile):
        deadline = time.monotonic() + support.SECONDS
        while time.monotonic() < deadline:
            try:
                with open(pidfile) as f:
                    return int(f.read())
            except FileNotFoundError:
                time.sleep(0.02)
        self.fail('the action did not start')


class RequestTest(support.Serving):
    def test_an_unknown_action_is_refused(self):
        result = self.pa_root('files.runAnything', 'sh')
        self.assertEqual(result.returncode, 77)
        self.assertIn(b"no action 'files.runAnything'", result.stderr)

    def test_the_wrong_number_of_values_is_refused(self):
        self.assertEqual(self.pa_root('files.read', '/a', '/b').returncode, 77)
        self.assertEqual(self.pa_root('files.rename', self.path('homes', 'a')).returncode, 77)

    def test_a_value_the_tool_could_misread_is_refused_by_the_broker_itself(self):
        target = self.directory('homes', 'u')
        for request in [('files.changeMode', '--reference=/etc/shadow', target), ('files.changeOwner', 'root root', target),
                        ('files.removeEntriesNamed', target, '-o'), ('files.mirrorExcluding', target, target, '-e sh')]:
            with self.subTest(action=request[0]):
                self.assertEqual(self.pa_root(*request).returncode, 77)

    def test_garbage_and_a_client_that_leaves_mid_request_do_not_stop_the_broker(self):
        conn = self.connect()
        conn.sendall(b'GET / HTTP/1.1\r\n\r\n' + b'\0' * 64)
        conn.close()
        conn = self.connect()
        conn.sendall(protocol.request('files.read', ['/a'], False)[:5])
        conn.close()
        conn = self.connect()
        conn.sendall(protocol.frame(protocol.STDIN, b'x'))
        frames = support.read_reply(conn)
        conn.close()
        self.assertEqual(protocol.parse_exit(frames[-1][1]), protocol.EXIT_PROTOCOL)
        self.directory('homes', 'u')
        self.assertEqual(self.pa_root('files.listAllNames', self.roots['homes']).stdout, b'u\n')
        self.assertTrue(self.broker_alive())

    def test_a_request_for_the_host_may_only_name_homes_and_backup(self):
        self.make('engine', 'users/u/x')
        conn = self.connect()
        conn.sendall(protocol.request('files.removeIfExists', [self.path('engine', 'users', 'u', 'x')], True))
        frames = support.read_reply(conn)
        conn.close()
        if os.geteuid() == 0:
            self.assertEqual(protocol.parse_exit(frames[-1][1]), protocol.EXIT_REFUSED)
        else:
            self.assertNotEqual(protocol.parse_exit(frames[-1][1]), 0)
        self.assertTrue(os.path.exists(self.path('engine', 'users', 'u', 'x')))


class PeerTest(support.Serving):
    allowed = {54321}

    def test_a_peer_that_is_neither_root_nor_www_data_is_refused(self):
        self.directory('homes', 'u')
        result = self.pa_root('files.removeTree', self.path('homes', 'u'))
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'may not use the root broker', result.stderr)
        self.assertTrue(os.path.isdir(self.path('homes', 'u')))
        self.assertIn('"refused"', self.audit_lines()[-1])


class OutsideTest(support.Serving):
    outside = True

    def test_no_peer_is_taken_for_core_while_there_is_no_core_whatever_its_uid(self):
        self.directory('homes', 'u')
        result = self.pa_root('files.removeTree', self.path('homes', 'u'))
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'the core container is not running', result.stderr)
        self.assertTrue(os.path.isdir(self.path('homes', 'u')))


class UnreachableTest(unittest.TestCase):
    def test_the_client_says_when_the_broker_is_not_there(self):
        import subprocess
        import sys
        env = {**os.environ, 'PA_ROOT_SOCKET': '/nonexistent/root.sock'}
        result = subprocess.run([sys.executable, '-I', support.CLIENT, 'files.read', '--', '/a'],
                                capture_output=True, env=env, timeout=support.SECONDS)
        self.assertEqual(result.returncode, protocol.EXIT_UNAVAILABLE)
        self.assertIn(b'not reachable', result.stderr)

    def test_the_client_refuses_a_request_without_the_separator(self):
        import subprocess
        import sys
        result = subprocess.run([sys.executable, '-I', support.CLIENT, 'files.read', '/a'], capture_output=True)
        self.assertEqual(result.returncode, 64)


if __name__ == '__main__':
    unittest.main()
