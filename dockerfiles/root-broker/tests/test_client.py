"""The client's end: input the broker no longer takes is not a failure, and a lost connection names the action."""

import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import unittest

import support
from rootbroker import client, protocol


class LateInputTest(unittest.TestCase):
    """An action that finished before the client sent its last frame, as a busy host's fast one does."""

    def setUp(self):
        self.ours, self.theirs = socket.socketpair()
        self.stdin, stdin_write = os.pipe()
        os.close(stdin_write)                       # the client's stdin is already at its end
        self.theirs.sendall(protocol.exit_frame(0))  # the answer, queued before the broker closed
        self.ours.sendall(protocol.frame(protocol.STDIN, b'a frame the broker never read'))
        self.theirs.close()                          # so the client's next write is EPIPE, as in the field

    def tearDown(self):
        os.close(self.stdin)
        self.ours.close()

    def session(self):
        session = client.Session(self.ours, stdin=self.stdin)
        session.watch_stdin()
        return session

    def test_the_turn_of_input_the_broker_closed_on_is_not_an_error(self):
        self.assertIsNone(self.session().from_stdin())

    def test_the_answer_the_broker_left_is_still_read(self):
        session = self.session()
        session.from_stdin()
        self.assertEqual(session.from_broker(), 0)


class SilentBroker:
    """A socket where the broker's is: it takes one connection, reads it and leaves without an answer."""

    def __init__(self):
        self.directory = tempfile.mkdtemp(prefix='pa-client-test-')
        self.path = os.path.join(self.directory, 'root.sock')
        self.listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.listener.bind(self.path)
        self.listener.listen(1)
        self.thread = threading.Thread(target=self.serve, daemon=True)
        self.thread.start()

    def serve(self):
        try:
            conn, _ = self.listener.accept()
        except OSError:
            return
        conn.recv(protocol.CHUNK)
        conn.close()

    def close(self):
        self.listener.close()
        shutil.rmtree(self.directory, ignore_errors=True)


class LostConnectionTest(unittest.TestCase):
    def test_a_broker_that_leaves_without_answering_names_the_action(self):
        broker = SilentBroker()
        try:
            result = subprocess.run([sys.executable, '-I', support.CLIENT, 'files.read', '--', '/a'],
                                    capture_output=True, timeout=support.SECONDS,
                                    env={**os.environ, 'PA_ROOT_SOCKET': broker.path})
        finally:
            broker.close()
        self.assertEqual(result.returncode, protocol.EXIT_PROTOCOL)
        self.assertTrue(result.stderr.startswith(b'pa-root: files.read: '), result.stderr)


if __name__ == '__main__':
    unittest.main()
