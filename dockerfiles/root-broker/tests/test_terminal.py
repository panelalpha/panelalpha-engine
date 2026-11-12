import os
import pty
import select
import subprocess
import sys
import time
import unittest

import support
from rootbroker import protocol, terminal
from rootbroker.actions import registry
from rootbroker.actions.sequence import Command

SHOW = 'tty >/dev/null && echo "tty yes"; stty size; IFS= read -r line; echo "read $line"; stty size; exit 7'

TERMINAL = {
    **registry(),
    'test.terminal': Command(lambda given: (), lambda context: ['sh', '-c', SHOW], terminal=True),
    'test.plain': Command(lambda given: (), lambda context: ['sh', '-c', 'echo plain']),
    'test.sleep': Command(lambda given: (given.one(str),), lambda context, pidfile: [
        'sh', '-c', 'echo $$ > "$0.new" && mv "$0.new" "$0" && exec sleep 600', pidfile,
    ], terminal=True),
}


class TerminalTest(support.Serving):
    actions = TERMINAL

    def test_the_action_runs_in_a_terminal_of_the_requested_size_and_follows_a_resize(self):
        conn = self.connect()
        conn.sendall(protocol.request('test.terminal', [], False, {}, (30, 100)))
        output, code = self.until_exit(conn, after=b'30 100', then=protocol.resize(50, 200) + protocol.frame(protocol.STDIN, b'hello\n'))
        conn.close()
        self.assertEqual(code, 7)
        self.assertIn(b'tty yes', output)
        self.assertIn(b'read hello', output)
        self.assertLess(output.index(b'30 100'), output.index(b'50 200'))

    def test_the_audit_line_says_the_request_had_a_terminal(self):
        conn = self.connect()
        conn.sendall(protocol.request('test.terminal', [], False, {}, (24, 80)))
        self.until_exit(conn, after=b'24 80', then=protocol.frame(protocol.STDIN, b'x\n'))
        conn.close()
        entry = self.audited()
        self.assertEqual((entry['action'], entry['pty'], entry['exit']), ('test.terminal', True, 7))

    def test_an_action_that_takes_no_terminal_is_refused_one(self):
        conn = self.connect()
        conn.sendall(protocol.request('test.plain', [], False, {}, (24, 80)))
        frames = support.read_reply(conn)
        conn.close()
        self.assertEqual(protocol.parse_exit(frames[-1][1]), protocol.EXIT_REFUSED)
        self.assertIn(b'no terminal', b''.join(payload for kind, payload in frames if kind == protocol.STDERR))

    def test_no_terminal_is_relayed_from_the_hosts_namespaces(self):
        conn = self.connect()
        conn.sendall(protocol.request('test.terminal', [], True, {}, (24, 80)))
        frames = support.read_reply(conn)
        conn.close()
        self.assertEqual(protocol.parse_exit(frames[-1][1]), protocol.EXIT_REFUSED)
        self.assertIn(b'host', b''.join(payload for kind, payload in frames if kind == protocol.STDERR))

    def test_an_action_that_takes_a_terminal_runs_without_one_when_none_is_asked_for(self):
        conn = self.connect()
        conn.sendall(protocol.request('test.terminal', [], False) + protocol.frame(protocol.STDIN, b'x\n')
                     + protocol.frame(protocol.STDIN_END))
        frames = support.read_reply(conn)
        conn.close()
        self.assertEqual(protocol.parse_exit(frames[-1][1]), 7)
        self.assertNotIn(b'tty yes', b''.join(payload for kind, payload in frames if kind == protocol.STDOUT))

    def test_the_session_dies_with_the_client(self):
        pidfile = self.path('exchange', 'pid')
        conn = self.connect()
        conn.sendall(protocol.request('test.sleep', [pidfile], False, {}, (24, 80)))
        pid = self.wait_for_pid(pidfile)
        watch = os.pidfd_open(pid)
        try:
            conn.close()
            self.assertTrue(select.select([watch], [], [], support.SECONDS)[0], 'the action outlived its client')
        finally:
            os.close(watch)
        self.assertTrue(self.broker_alive())

    def test_pa_root_hands_its_own_terminal_over_and_gives_it_back_as_it_was(self):
        controller, own = pty.openpty()
        terminal.set_size(own, 33, 99)
        before = subprocess.run(['stty', '-g'], stdin=own, capture_output=True, check=True).stdout
        env = {**os.environ, 'PA_ROOT_SOCKET': self.socket}
        process = subprocess.Popen([sys.executable, '-I', support.CLIENT, '--pty', 'test.terminal', '--'],
                                   stdin=own, stdout=own, stderr=own, env=env)
        output = self.read_until(controller, b'33 99')
        os.write(controller, b'typed\r')
        output += self.read_until(controller, b'read typed')
        self.assertEqual(process.wait(support.SECONDS), 7)
        self.assertIn(b'tty yes', output)
        after = subprocess.run(['stty', '-g'], stdin=own, capture_output=True, check=True).stdout
        self.assertEqual(after, before)
        os.close(own)
        os.close(controller)

    def until_exit(self, conn, after, then):
        reader, output, sent = protocol.Reader(), b'', False
        while True:
            ready = select.select([conn], [], [], support.SECONDS)[0]
            self.assertTrue(ready, f'no answer; so far {output!r}')
            data = conn.recv(65536)
            self.assertTrue(data, f'the broker closed; so far {output!r}')
            for kind, payload in reader.feed(data):
                if kind == protocol.STDOUT:
                    output += payload
                elif kind == protocol.EXIT:
                    return output, protocol.parse_exit(payload)
            if not sent and after in output:
                conn.sendall(then)
                sent = True

    def read_until(self, fd, wanted):
        output = b''
        while wanted not in output:
            self.assertTrue(select.select([fd], [], [], support.SECONDS)[0], f'waited for {wanted!r}; got {output!r}')
            output += os.read(fd, 65536)
        return output

    def wait_for_pid(self, pidfile):
        deadline = time.monotonic() + support.SECONDS
        while time.monotonic() < deadline:
            try:
                with open(pidfile) as f:
                    return int(f.read())
            except FileNotFoundError:
                time.sleep(0.02)
        self.fail('the action did not start')


if __name__ == '__main__':
    unittest.main()
