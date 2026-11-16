import os
import shutil
import socketserver
import tempfile
import threading
import time
import unittest

from rootbroker import accountdaemon, dockerapi, hostdaemon


class Replying(socketserver.StreamRequestHandler):
    """Reads a request's head, then sends the server's `reply`, or holds the connection without one."""

    def handle(self):
        while self.rfile.readline() not in (b'\r\n', b''):
            pass
        if self.server.reply is None:
            time.sleep(2)
        else:
            self.wfile.write(self.server.reply)


class WordingTest(unittest.TestCase):
    def setUp(self):
        self.base = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.base)
        self.missing = os.path.join(self.base, 'missing.sock')

    def refusal(self, daemon):
        with self.assertRaises(dockerapi.Unavailable) as raised:
            daemon.json('GET', '/containers/json')
        return str(raised.exception)

    def test_a_daemon_words_its_failures_without_a_subclass(self):
        self.assertEqual(self.refusal(dockerapi.Daemon('x', self.missing)), 'the Docker daemon of x is not running')

    def test_the_host_and_an_account_word_a_daemon_that_is_not_there_as_before(self):
        self.assertEqual(self.refusal(hostdaemon.open_host(socket_path=self.missing)), hostdaemon.CANNOT_CONNECT)
        self.assertEqual(self.refusal(accountdaemon.Daemon('u', address=self.missing)),
                         'the Docker daemon of container u is not running')

    def serving(self, reply):
        server = socketserver.UnixStreamServer(self.missing, Replying)
        server.reply = reply
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)

    def test_the_host_s_daemon_that_does_not_answer_is_told_in_its_words(self):
        self.serving(None)
        with self.assertRaises(dockerapi.Unavailable) as raised:
            hostdaemon.open_host(seconds=1, socket_path=self.missing).json('GET', '/containers/json')
        self.assertEqual(str(raised.exception), "the host's Docker did not answer within 1s")

    def test_a_reply_of_the_host_s_daemon_over_the_limit_is_told_in_its_words(self):
        self.serving(b'HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 64\r\n\r\n' + b'[' + b' ' * 62 + b']')
        with self.assertRaises(dockerapi.Failed) as raised:
            hostdaemon.open_host(socket_path=self.missing).json('GET', '/containers/json?all=1', limit=16)
        self.assertEqual(str(raised.exception), "the host's Docker answered /containers/json with the reply is larger than 16 bytes")

    def test_a_bounded_account_daemon_leaves_the_socket_to_the_one_it_came_from(self):
        fd = os.open(self.base, os.O_PATH)
        daemon = accountdaemon.Daemon('u', socket_fd=fd)
        bounded = daemon.bounded(5)
        bounded.close()
        os.fstat(fd)
        self.assertEqual((bounded.seconds, bounded.address, daemon.socket_fd), (5, daemon.address, fd))
        daemon.close()
        self.assertRaises(OSError, os.fstat, fd)


if __name__ == '__main__':
    unittest.main()
