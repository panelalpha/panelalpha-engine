"""Runs only as root: the account's docker client in the confined sandbox, against a fake daemon.

The interpreter stands in for the docker client, bound where the sandbox binds docker, and runs the probe the workdir
holds as `compose`; what the probe sees comes back as JSON on its stdout.
"""

import json
import os
import signal
import socket
import subprocess
import sys
import threading
import time
import unittest

import support
from rootbroker import accountdaemon, sandbox
from test_account_compose import Compose

INTERPRETER = os.path.realpath(sys.executable)
FILE_CAPABILITIES = sum(1 << cap for cap in sandbox.FILE_CAPABILITIES)

PROBE = '''
import ctypes, errno, json, os, shutil, socket, subprocess, sys, time

LIBC = ctypes.CDLL(None, use_errno=True)
MODE, ABSTRACT, LEFT, LOOPBACK, INTERPRETER, *LISTED = sys.argv[2:]
FLAGS = {'rdonly': os.ST_RDONLY, 'nosuid': os.ST_NOSUID, 'nodev': os.ST_NODEV, 'noexec': os.ST_NOEXEC}


class Header(ctypes.Structure):
    _fields_ = [('version', ctypes.c_uint32), ('pid', ctypes.c_int)]


class Data(ctypes.Structure):
    _fields_ = [('effective', ctypes.c_uint32), ('permitted', ctypes.c_uint32), ('inheritable', ctypes.c_uint32)]


def capabilities():
    data = (Data * 2)()
    LIBC.capget(ctypes.byref(Header(0x20080522, 0)), data)
    held = {kind: getattr(data[0], kind) | getattr(data[1], kind) << 32 for kind, _ in Data._fields_}
    held['ambient'] = sum(1 << cap for cap in range(64) if LIBC.prctl(47, 1, cap, 0, 0) == 1)
    held['bounding'] = sum(1 << cap for cap in range(64) if LIBC.prctl(23, cap, 0, 0, 0) == 1)
    return held


def failure(attempt):
    try:
        attempt()
    except OSError as e:
        return errno.errorcode.get(e.errno, type(e).__name__)
    return None


def flags(path):
    return sorted(name for name, flag in FLAGS.items() if os.statvfs(path).f_flag & flag)


def connected(address, family=socket.AF_UNIX):
    with socket.socket(family, socket.SOCK_STREAM) as conn:
        conn.settimeout(5)
        conn.connect(address)


def pinged():
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.connect(os.environ['DOCKER_HOST'][len('unix://'):])
        conn.sendall(b'GET /_ping HTTP/1.1\\r\\nHost: docker\\r\\nConnection: close\\r\\n\\r\\n')
        return conn.makefile('rb').readline().decode().strip()


def resolver():
    try:
        return open('/etc/resolv.conf').read()
    except FileNotFoundError:
        return None


def copied():
    shutil.copy(INTERPRETER, 'copy')
    os.chmod('copy', 0o755)
    subprocess.run(['./copy', '-c', ''])


def leave():
    if os.fork() == 0:
        os.setsid()
        if os.fork() == 0:
            LIBC.prctl(15, LEFT.encode(), 0, 0, 0)
            open('left', 'w').close()
            time.sleep(600)
        os._exit(0)
    os.wait()
    while not os.path.exists('left'):
        time.sleep(0.05)


leave()
if MODE == 'hold':
    time.sleep(600)
print(json.dumps({
    'listed': {path: sorted(os.listdir(path)) for path in LISTED},
    'links': {path: os.readlink(path) for path in ('/lib', '/lib64')},
    'passwd': open('/etc/passwd').read(),
    'ids': [os.getuid(), os.geteuid(), os.getgid(), os.getegid(), os.getgroups()],
    'pids': [os.getpid(), os.getppid()],
    'no_new_privs': LIBC.prctl(39, 0, 0, 0, 0),
    'capabilities': capabilities(),
    'flags': {path: flags(path) for path in ('/', '/tmp', os.environ['HOME'], INTERPRETER)},
    'writes': {path: failure(lambda: open(path, 'a').close()) for path in ('/probe', '/etc/passwd', '/tmp/probe')},
    'home_exec': failure(copied),
    'bound_exec': subprocess.run([INTERPRETER, '-c', '']).returncode,
    'interfaces': socket.if_nameindex(),
    'network': failure(lambda: connected(('192.0.2.1', 80), socket.AF_INET)),
    'abstract': failure(lambda: connected('\\0' + ABSTRACT)),
    'loopback': failure(lambda: connected(('127.0.0.1', int(LOOPBACK)), socket.AF_INET)),
    'resolver': resolver(),
    'daemon': pinged(),
}))
'''


def named(name):
    """The pids of the processes whose command is `name`."""
    found = []
    for pid in filter(str.isdigit, os.listdir('/proc')):
        try:
            with open(f'/proc/{pid}/comm') as comm:
                if comm.read().strip() == name:
                    found.append(int(pid))
        except OSError:
            continue
    return found


def children(pid):
    """The pids of the processes whose parent is `pid`."""
    found = []
    for child in filter(str.isdigit, os.listdir('/proc')):
        try:
            with open(f'/proc/{child}/stat') as stat:
                if int(stat.read().rsplit(')', 1)[1].split()[1]) == pid:
                    found.append(int(child))
        except OSError:
            continue
    return found


def waited(condition, seconds):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline and not condition():
        time.sleep(0.1)
    return condition()


class ConfinedCompose(Compose):
    """The probe as the client in the confined sandbox, and a TCP listener on the loopback of the test's own network."""

    settings = {'CONFINED': True, 'DOCKER': INTERPRETER}

    def setUp(self):
        super().setUp()
        self.project = f'{self.home}/project'
        with open(f'{self.project}/compose', 'w') as f:
            f.write(PROBE)
        for path in (self.home, self.project, f'{self.project}/compose'):
            os.chown(path, support.UID, support.GID)
        self.left = f'left-{os.getpid()}'
        self.abstract = f'broker-test-{os.getpid()}'
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        listener.bind('\0' + self.abstract)
        listener.listen(1)
        self.addCleanup(listener.close)
        loopback = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        loopback.bind(('127.0.0.1', 0))
        loopback.listen(1)
        self.addCleanup(loopback.close)
        self.loopback = str(loopback.getsockname()[1])

    def listed(self):
        """What the probe lists in the sandbox, and what each must hold."""
        libraries = [os.path.basename(path) for path in sandbox.LIBRARIES if os.path.isdir(path)]
        return {
            '/': sorted({'dev', 'etc', 'lib', 'lib64', 'run', 'tmp', 'usr', self.home.split('/')[1]}),
            '/dev': sorted(sandbox.DEVICES),
            '/etc': ['group', 'passwd'],
            '/run': [os.path.basename(sandbox.SOCKET)],
            '/usr': sorted({'bin', 'libexec', *libraries}),
            os.path.dirname(INTERPRETER): [os.path.basename(INTERPRETER)],
            sandbox.PLUGINS: sorted(os.listdir(sandbox.PLUGINS)),
        }

    def probe(self, mode='see', as_whom='', timeout=30):
        return self.compose(self.project, [], 'compose', 'ps', mode, self.abstract, self.left, self.loopback,
                            INTERPRETER, *self.listed(), as_whom=as_whom, timeout=timeout)

    def seen(self, as_whom):
        result = self.probe(as_whom=as_whom)
        self.assertEqual((result.returncode, result.stderr), (0, b''))
        return json.loads(result.stdout)


@support.needs_root
class ConfinedComposeTest(ConfinedCompose):
    def test_the_client_sees_only_its_sandbox_as_the_account(self):
        for as_whom in ('', 'root'):
            with self.subTest(as_whom=as_whom):
                seen = self.seen(as_whom)
                self.assertEqual(seen['listed'], self.listed())
                self.assertEqual(seen['links'], {'/lib': 'usr/lib', '/lib64': 'usr/lib64'})
                self.assertEqual(seen['passwd'], f'u:x:{support.UID}:{support.GID}::{self.home}:/bin/sh\n')
                self.assertEqual(seen['ids'], [support.UID, support.UID, support.GID, support.GID, []])
                self.assertEqual(seen['no_new_privs'], 1)
                self.assertEqual(seen['daemon'], 'HTTP/1.1 200 OK')
                self.assertIn(('GET', '/_ping'), self.asked)

    def test_the_root_and_the_binaries_are_read_only_and_nothing_in_the_home_or_tmp_executes(self):
        for as_whom, passwd in (('', 'EACCES'), ('root', 'EROFS')):
            with self.subTest(as_whom=as_whom):
                seen = self.seen(as_whom)
                self.assertEqual(seen['flags'], {
                    '/': ['nodev', 'nosuid', 'rdonly'],
                    '/tmp': ['nodev', 'noexec', 'nosuid'],
                    self.home: ['nodev', 'noexec', 'nosuid'],
                    INTERPRETER: ['nodev', 'nosuid', 'rdonly'],
                })
                self.assertEqual(seen['writes'], {'/probe': 'EROFS', '/etc/passwd': passwd, '/tmp/probe': None})
                self.assertEqual((seen['home_exec'], seen['bound_exec']), ('EACCES', 0))

    def test_the_client_keeps_only_the_file_capabilities_and_those_only_standing_in_for_root(self):
        for as_whom, held in (('', 0), ('root', FILE_CAPABILITIES)):
            with self.subTest(as_whom=as_whom):
                self.assertEqual(self.seen(as_whom)['capabilities'], {
                    'effective': held, 'permitted': held, 'inheritable': held, 'ambient': held, 'bounding': held,
                })

    def test_the_client_reaches_no_network_and_no_abstract_socket(self):
        seen = self.seen('')
        self.assertEqual(seen['interfaces'], [[1, 'lo']])
        self.assertEqual(seen['network'], 'ENETUNREACH')
        self.assertEqual(seen['abstract'], 'ECONNREFUSED')
        self.assertEqual((seen['loopback'], seen['resolver']), ('ENETUNREACH', None))

    def test_the_client_is_the_child_of_the_namespace_s_init_and_nothing_it_started_outlives_the_request(self):
        seen = self.seen('')
        self.assertEqual(seen['pids'], [2, 1])
        self.assertTrue(os.path.exists(f'{self.project}/left'))
        self.assertEqual(named(self.left), [])

    def test_a_caller_that_hangs_up_ends_what_the_client_started(self):
        with self.assertRaises(subprocess.TimeoutExpired):
            self.probe(mode='hold', timeout=5)
        self.assertTrue(os.path.exists(f'{self.project}/left'))
        deadline = time.monotonic() + sandbox.STOP_SECONDS + 5
        while time.monotonic() < deadline and named(self.left):
            time.sleep(0.2)
        self.assertEqual(named(self.left), [])

    def test_a_request_process_that_is_killed_takes_what_the_client_started_with_it(self):
        held = threading.Thread(target=self.probe, kwargs={'mode': 'hold'}, daemon=True)
        held.start()
        self.assertTrue(waited(lambda: os.path.exists(f'{self.project}/left') and named(self.left), 20))
        requests = children(self.server_pid)
        self.assertEqual(len(requests), 1)
        os.kill(requests[0], signal.SIGKILL)
        self.assertTrue(waited(lambda: not named(self.left), 5))
        held.join(10)


@support.needs_root
class AccountNetworkComposeTest(ConfinedCompose):
    """A daemon that holds its account's network namespace, the test's own here, puts the client in it."""

    def open_daemon(self, account, accounts_directory):
        return accountdaemon.Daemon(account.name, os.open(self.daemon_socket, os.O_PATH | os.O_CLOEXEC),
                                    network_fd=os.open('/proc/self/ns/net', os.O_RDONLY | os.O_CLOEXEC))

    def listed(self):
        bundled = os.path.isfile(sandbox.CERTIFICATES)
        listed = {**super().listed(), '/etc': ['group', 'passwd', 'resolv.conf', *(['ssl'] if bundled else [])]}
        if bundled:
            listed[os.path.dirname(sandbox.CERTIFICATES)] = [os.path.basename(sandbox.CERTIFICATES)]
        return listed

    def test_the_client_shares_the_account_s_network_and_looks_names_up_through_its_dns(self):
        seen = self.seen('')
        self.assertEqual(seen['listed'], self.listed())
        self.assertIsNone(seen['loopback'])
        self.assertEqual(seen['resolver'], sandbox.RESOLVER)
        self.assertEqual(seen['ids'], [support.UID, support.UID, support.GID, support.GID, []])
        self.assertEqual(seen['daemon'], 'HTTP/1.1 200 OK')


if __name__ == '__main__':
    unittest.main()
