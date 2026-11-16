import io
import json
import os
import subprocess
import sys
import unittest

import support
from rootbroker import filestream
from rootbroker.actions import account, registry

HELPER = os.path.join(os.path.dirname(support.HERE), 'pa-file-stream')
U, G = str(support.UID), str(support.GID)
STAT_KEYS = [str(index) for index in range(13)] + list(filestream.STAT_FIELDS)


def write(data):
    return b'WRITE ' + format(len(data), 'x').encode() + b'\n' + data


class Helper(support.Tree):
    def setUp(self):
        super().setUp()
        self.home = self.directory('homes', 'u')
        self.file = self.make('homes', 'u/public_html/index.php', b'hello')
        self.secret = self.make('homes', 'v/secret.txt', b'theirs')

    def run_helper(self, mode, path, stdin, root=None, timeout=support.SECONDS):
        argv = [sys.executable, '-I', HELPER, mode, path, self.home if root is None else root]
        return subprocess.run(argv, input=stdin, capture_output=True, timeout=timeout)

    def assert_refused(self, result, message=b'does not resolve to a file under'):
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertEqual(result.stdout, b'')
        self.assertIn(message, result.stderr)


class WireTest(Helper):
    """The command lines FileStreamWrapper sends, and the answers it parses."""

    def test_stat_then_reads_until_the_end_then_close(self):
        result = self.run_helper('rb', self.file, b'STAT\nREAD 8192\nEOF\nREAD 8192\nCLOSE\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        stat_line, rest = result.stdout.split(b'\n', 1)
        self.assertEqual(rest, b'OK 5\nhelloOK 1\nOK 0\nOK\n')
        self.assertTrue(stat_line.startswith(b'OK {"0":'))
        info = json.loads(stat_line[3:])
        self.assertEqual(list(info), STAT_KEYS)
        self.assertEqual((info['7'], info['size'], info['2'], info['mode']), (5, 5, *[os.stat(self.file).st_mode] * 2))
        self.assertNotIn(b' ', stat_line[3:])

    def test_the_length_of_a_read_is_hex(self):
        self.make('homes', 'u/big', bytes(range(256)) * 100)
        result = self.run_helper('r', self.path('homes', 'u', 'big'), b'READ 8192\nTELL\nCLOSE\n')
        self.assertEqual(result.stdout[:7], b'OK 2000')
        self.assertEqual(result.stdout[8:8 + 8192], (bytes(range(256)) * 100)[:8192])
        self.assertEqual(result.stdout[8 + 8192:], b'OK 8192\nOK\n')

    def test_the_end_is_reported_only_once_a_read_came_up_short(self):
        result = self.run_helper('r', self.file, b'READ 5\nEOF\nREAD 5\nEOF\nSEEK 1 0\nEOF\nCLOSE\n')
        self.assertEqual(result.stdout, b'OK 5\nhelloOK 0\nOK 0\nOK 1\nOK\nOK 0\nOK\n')

    def test_seeks_from_the_start_the_position_and_the_end(self):
        stdin = b'SEEK 1 0\nREAD 2\nTELL\nSEEK -2 2\nREAD 10\nSEEK -3 1\nTELL\nSEEK -100 0\nTELL\nCLOSE\n'
        result = self.run_helper('r', self.file, stdin)
        self.assertEqual(result.stdout, b'OK\nOK 2\nelOK 3\nOK\nOK 2\nloOK\nOK 2\nERR seek_failed\nOK 2\nOK\n')

    def test_a_write_takes_exactly_its_hex_length_of_bytes(self):
        data = b'new\n\x00line WRITE 3\n'
        result = self.run_helper('w', self.file, write(data) + b'TELL\nSTAT\nCLOSE\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(result.stdout.startswith(b'OK\nOK 18\nOK {'), result.stdout)
        with open(self.file, 'rb') as f:
            self.assertEqual(f.read(), data)

    def test_read_write_modes_share_one_position(self):
        result = self.run_helper('r+', self.file, write(b'HE') + b'TELL\nSEEK 0 0\nREAD 9\nCLOSE\n')
        self.assertEqual(result.stdout, b'OK\nOK 2\nOK\nOK 5\nHEllo' + b'OK\n')

    def test_append_reports_the_position_php_would(self):
        result = self.run_helper('a', self.file, b'TELL\n' + write(b'zz') + b'TELL\nCLOSE\n')
        self.assertEqual(result.stdout, b'OK 0\nOK\nOK 2\nOK\n')
        with open(self.file, 'rb') as f:
            self.assertEqual(f.read(), b'hellozz')

    def test_a_write_to_a_file_opened_for_reading_fails_and_the_stream_goes_on(self):
        result = self.run_helper('r', self.file, write(b'abc') + b'READ 9\nCLOSE\n')
        self.assertEqual(result.stdout, b'ERR write_failed\nOK 5\nhelloOK\n')

    def test_unknown_and_empty_lines(self):
        result = self.run_helper('r', self.file, b'\n\r\nNOPE\nread 2\r\ntell\nCLOSE\nREAD 1\n')
        self.assertEqual(result.stdout, b'ERR unknown_command\nOK 2\nheOK 2\nOK\n')

    def test_a_directory_opens_and_stats_but_does_not_read(self):
        result = self.run_helper('r', self.path('homes', 'u', 'public_html'), b'STAT\nREAD 10\nEOF\nCLOSE\n')
        stat_line, rest = result.stdout.split(b'\n', 1)
        self.assertTrue(json.loads(stat_line[3:])['mode'] & 0o040000)
        self.assertEqual(rest, b'ERR read_failed\nOK 1\nOK\n')

    def test_a_mode_php_does_not_know_cannot_open(self):
        self.assert_refused(self.run_helper('z', self.file, b'CLOSE\n'), b'Cannot open')
        self.assert_refused(self.run_helper('x', self.file, b'CLOSE\n'), b'Cannot open')

    def test_usage(self):
        result = subprocess.run([sys.executable, '-I', HELPER, 'r', self.file], capture_output=True)
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertIn(b'Usage:', result.stderr)


class ConfinementTest(Helper):
    def test_a_link_that_stays_inside_the_root_is_followed(self):
        os.symlink('public_html/index.php', self.path('homes', 'u', 'relative'))
        os.symlink(self.file, self.path('homes', 'u', 'absolute'))
        os.symlink('public_html', self.path('homes', 'u', 'site'))
        for name in ('relative', 'absolute', 'site/index.php', 'public_html/../relative'):
            with self.subTest(name=name):
                result = self.run_helper('r', self.path('homes', 'u', name), b'READ 100\nCLOSE\n')
                self.assertEqual(result.stdout, b'OK 5\nhelloOK\n', result.stderr)

    def test_a_path_outside_the_root_is_refused(self):
        os.symlink(self.secret, self.path('homes', 'u', 'file-link'))
        os.symlink(os.path.dirname(self.secret), self.path('homes', 'u', 'dir-link'))
        os.symlink('../v/secret.txt', self.path('homes', 'u', 'relative-out'))
        os.symlink('../u/public_html/index.php', self.path('homes', 'u', 'out-and-back'))
        for path in (self.secret, self.path('homes', 'u', '..', 'v', 'secret.txt'), self.path('homes', 'u', 'file-link'),
                     self.path('homes', 'u', 'dir-link', 'secret.txt'), self.path('homes', 'u', 'relative-out'),
                     self.path('homes', 'u', 'out-and-back'), self.home, self.path('homes', 'u', 'missing')):
            with self.subTest(path=path):
                self.assert_refused(self.run_helper('r', path, b'READ 100\nCLOSE\n'))

    def test_a_file_cannot_be_walked_through_and_a_link_loop_ends(self):
        os.symlink('loop-b', self.path('homes', 'u', 'loop-a'))
        os.symlink('loop-a', self.path('homes', 'u', 'loop-b'))
        self.assert_refused(self.run_helper('r', self.file + '/x', b'CLOSE\n'))
        self.assert_refused(self.run_helper('r', self.path('homes', 'u', 'loop-a'), b'CLOSE\n'))

    def test_a_fifo_is_refused_without_waiting_for_a_writer(self):
        fifo = self.path('homes', 'u', 'fifo')
        os.mkfifo(fifo)
        for mode in ('r', 'r+', 'w'):
            with self.subTest(mode=mode):
                self.assert_refused(self.run_helper(mode, fifo, b'READ 10\nCLOSE\n', timeout=10), fifo.encode())

    def test_the_root_is_opened_where_the_broker_opened_it(self):
        moved = self.path('homes', 'elsewhere')
        fd = os.open(self.home, os.O_PATH | os.O_DIRECTORY)
        try:
            os.rename(self.home, moved)
            argv = [sys.executable, '-I', HELPER, 'r', self.file, self.home, f'/proc/self/fd/{fd}']
            result = subprocess.run(argv, input=b'READ 100\nCLOSE\n', capture_output=True, pass_fds=[fd])
        finally:
            os.close(fd)
        self.assertEqual(result.stdout, b'OK 5\nhelloOK\n', result.stderr)


class SwapTest(Helper):
    """A component replaced between the walk and the open."""

    def open_swapping(self, path, swap, mode='r'):
        checked = filestream.opened_as_seen

        def swapped_first(*args):
            swap()
            return checked(*args)

        filestream.opened_as_seen = swapped_first
        try:
            return filestream.open_below(mode, path, self.home, self.home)
        finally:
            filestream.opened_as_seen = checked

    def test_a_file_swapped_for_a_link_is_not_followed(self):
        def swap():
            os.unlink(self.file)
            os.symlink(self.secret, self.file)

        with self.assertRaisesRegex(filestream.Refusal, 'Cannot open'):
            self.open_swapping(self.file, swap)

    def test_a_file_swapped_for_another_is_refused(self):
        def swap():
            os.rename(self.file, self.file + '.old')
            self.make('homes', 'u/public_html/index.php', b'other')

        with self.assertRaisesRegex(filestream.Refusal, 'changed between the check and the open'):
            self.open_swapping(self.file, swap)

    def test_a_directory_swapped_for_a_link_still_opens_the_walked_directory(self):
        site = self.path('homes', 'u', 'public_html')
        self.make('homes', 'v/index.php', b'theirs')

        def swap():
            os.rename(site, site + '.old')
            os.symlink(os.path.dirname(self.secret), site)

        fd = self.open_swapping(self.file, swap)
        try:
            self.assertEqual(os.read(fd, 100), b'hello')
        finally:
            os.close(fd)


class StreamTest(unittest.TestCase):
    def test_php_integer_casts(self):
        for text, number in (('12', 12), (' 12abc', 12), ('-3', -3), ('+4', 4), ('abc', 0), ('', 0)):
            with self.subTest(text=text):
                self.assertEqual(filestream.php_integer(text), number)

    def test_php_hexdec_skips_what_is_not_hex(self):
        self.assertEqual(filestream.php_hex('1g0'), 16)
        self.assertEqual(filestream.php_hex(''), 0)

    def test_a_short_write_body_is_a_failed_write(self):
        read_end, write_end = os.pipe()
        os.close(read_end)
        stream = filestream.Stream(write_end, io.BytesIO(b'ab'), io.BytesIO())
        stream.write('3')
        self.assertEqual(stream.stdout.getvalue(), b'ERR write_failed\n')
        os.close(write_end)


class ThroughTheBrokerTest(support.Serving):
    """account.fileStream as the broker runs it: the root opened by its own walk, the helper as the account."""

    def setUp(self):
        self.helper = account.FILE_STREAM
        account.FILE_STREAM = HELPER
        super().setUp()
        self.home = self.directory('homes', 'u')
        self.file = self.make('homes', 'u/public_html/index.php', b'hello')
        self.secret = self.make('homes', 'v/secret.txt', b'theirs')

    def tearDown(self):
        super().tearDown()
        account.FILE_STREAM = self.helper

    def stream(self, mode, path, root, stdin):
        return self.pa_root('account.fileStream', U, G, mode, path, root, stdin=stdin)

    def test_the_wrapper_reads_through_the_broker(self):
        result = self.stream('rb', self.file, self.home, b'STAT\nREAD 8192\nEOF\nCLOSE\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(result.stdout.endswith(b'\nOK 5\nhelloOK 1\nOK\n'), result.stdout)

    def test_the_wrapper_writes_through_the_broker(self):
        result = self.stream('r+', self.file, self.home, write(b'J') + b'SEEK 0 0\nREAD 9\nCLOSE\n')
        self.assertEqual(result.stdout, b'OK\nOK\nOK 5\nJelloOK\n', result.stderr)

    def test_a_link_out_of_the_root_is_refused_by_the_helper(self):
        os.symlink(self.secret, self.path('homes', 'u', 'shadow'))
        result = self.stream('r', self.path('homes', 'u', 'shadow'), self.home, b'READ 100\nCLOSE\n')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertIn(b'does not resolve to a file under', result.stderr)

    def test_a_root_that_is_a_link_is_refused_by_the_broker(self):
        os.symlink(os.path.dirname(self.secret), self.path('homes', 'u', 'linked'))
        result = self.stream('r', self.path('homes', 'u', 'linked', 'secret.txt'), self.path('homes', 'u', 'linked'), b'')
        self.assertEqual(result.returncode, 77, result.stderr)
        self.assertIn(b'is a symlink', result.stderr)

    def test_the_stream_has_a_root_below_the_accounts_home_and_no_root_variant(self):
        self.assertEqual(self.stream('r', self.secret, self.path('homes', 'v'), b'').returncode, 77)
        self.assertEqual(self.pa_root('account.fileStream', U, G, 'r', self.file).returncode, 77)
        self.assertNotIn('account.fileStreamHelper', registry())
