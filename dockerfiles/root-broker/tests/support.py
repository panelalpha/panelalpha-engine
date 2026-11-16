"""A scratch tree for the broker's roots, and a broker serving it from a forked process.

SECONDS only bounds a wait, so a busy host is slower, never red.
"""

import functools
import json
import os
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))

from rootbroker import composepolicy, protocol  # noqa: E402
from rootbroker.accounts import Accounts  # noqa: E402
from rootbroker.actions import Policy  # noqa: E402
from rootbroker.audit import PREFIX, Audit  # noqa: E402
from rootbroker import peer  # noqa: E402
from rootbroker.server import Broker, Server  # noqa: E402

CLIENT = os.path.join(os.path.dirname(HERE), 'pa-root')
ROOT_NAMES = ('homes', 'engine', 'engine-log', 'letsencrypt', 'core-storage', 'core-sources', 'coverage',
              'archive-stage', 'unzip-stage', 'backup', 'exchange', 'project-caches')
UID, GID = 1004, 1005
SECONDS = 120

# The scratch trees are made as a correctly installed host has them, whatever the umask of the shell running the tests:
# a group-writable file is one the broker refuses.
os.umask(0o022)
OTHER_UID = 1006


def passwd_lines(homes):
    return [
        'root:x:0:0:root:/root:/bin/bash',
        'daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin',
        'www-data:x:33:33:www-data:/var/www:/usr/sbin/nologin',
        'low:x:999:999::' + homes + '/low:/usr/sbin/nologin',
        f'u:x:{UID}:{GID}::' + homes + '/u:/usr/sbin/nologin',
        f'v:x:{OTHER_UID}:{OTHER_UID}::' + homes + '/v:/usr/sbin/nologin',
        'outside:x:1010:1010::/srv/outside:/usr/sbin/nologin',
        'deep:x:1011:1011::' + homes + '/deep/er:/usr/sbin/nologin',
        'nobody:x:65534:65534:nobody:/nonexistent:/usr/sbin/nologin',
    ]


def stay_as_we_are(_account, _own_groups=False):
    pass

needs_root = unittest.skipUnless(os.geteuid() == 0, 'needs root: runs on the test host')

PLUGINS = (composepolicy.PLUGIN, os.path.expanduser('~/.docker/cli-plugins/docker-compose'))
AS_ROOT_IN_A_NAMESPACE = ('unshare', '--map-root-user', '--mount')


def no_services(_content, options):
    """A render for a compose file whose shape is not under test: the project the options name, holding nothing."""
    return {'name': options[options.index('-p') + 1], 'services': {}}


def sandbox_render():
    """The broker's own render, run as root of a user namespace; None where compose or such a namespace is missing."""
    plugin = next((path for path in PLUGINS if os.access(path, os.X_OK)), None)
    if plugin is None or shutil.which('unshare') is None:
        return None
    if subprocess.run([*AS_ROOT_IN_A_NAMESPACE, 'true'], capture_output=True).returncode != 0:
        return None
    return functools.partial(composepolicy.render, unshare=AS_ROOT_IN_A_NAMESPACE, plugin=plugin)


def as_sudo_named(plan):
    """The planned argv with an account's compose options turned back into `-f ./docker-compose.yml`.

    Only after checking that they pin the directory's own project and hand over the file's bytes."""
    argv = list(plan.argv)
    if '--env-file' not in argv:
        return argv
    at = argv.index('--env-file')
    options, fd = argv[at:at + 8], argv[at + 7]
    directory = os.readlink(f'/proc/self/fd/{plan.cwd}')
    expected = ['--env-file', '/dev/null', '-p', os.path.basename(directory), '--project-directory', '.', '-f', fd]
    if options != expected or not fd.startswith('/dev/fd/') or int(fd.removeprefix('/dev/fd/')) not in plan.fds:
        raise AssertionError(f'not the options of an account compose file: {options}')
    with open(os.path.join(directory, 'docker-compose.yml'), 'rb') as f:
        if os.pread(int(fd.removeprefix('/dev/fd/')), 1 << 20, 0) != f.read():
            raise AssertionError('the compose file the broker hands over is not the file it read')
    return argv[:at] + ['-f', './docker-compose.yml'] + argv[at + 8:]


class Tree(unittest.TestCase):
    """Every named root as a directory of its own below one scratch directory."""

    become = staticmethod(stay_as_we_are)

    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-test-'))
        self.roots = {name: os.path.join(self.base, name) for name in ROOT_NAMES}
        for directory in self.roots.values():
            os.makedirs(directory)
        self.passwd = os.path.join(self.base, 'passwd')
        with open(self.passwd, 'w') as f:
            f.write('\n'.join(self.passwd_entries()) + '\n')
        self.accounts = Accounts(self.passwd, self.roots['homes'])
        self.policy = Policy(self.roots, accounts_db=self.accounts, become=self.become)

    def passwd_entries(self):
        return passwd_lines(self.roots['homes'])

    def tearDown(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def path(self, root, *parts):
        return os.path.join(self.roots[root], *parts)

    def make(self, root, relative, content=b''):
        full = self.path(root, relative)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, 'wb') as f:
            f.write(content)
        return full

    def directory(self, root, relative):
        full = self.path(root, relative)
        os.makedirs(full, exist_ok=True)
        return full


class Serving(Tree):
    """A broker on a socket in the scratch tree, in a forked process, with its audit lines in a file."""

    allowed = None
    actions = None
    outside = False

    def setUp(self):
        super().setUp()
        self.socket = os.path.join(self.base, 'root.sock')
        self.audit_path = os.path.join(self.base, 'audit.log')
        allowed = {os.getuid()} if self.allowed is None else self.allowed
        audit = os.open(self.audit_path, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        broker = Broker(allowed, self.broker_policy(), self.actions, Audit(audit), core=self.core())
        server = Server(self.socket, broker)
        self.server_pid = os.fork()
        if self.server_pid == 0:
            self.serve(server)
        server.server_close()
        os.close(audit)

    def serve(self, server):
        try:
            server.serve_forever()
        finally:
            os._exit(1)

    def broker_policy(self):
        return self.policy

    def core(self):
        """The test process stands for the core container; with `outside`, there is no core."""
        ours = os.getpid()
        return peer.Core(locate=(lambda: None) if self.outside else (lambda: ours))

    def tearDown(self):
        os.kill(self.server_pid, signal.SIGKILL)
        os.waitpid(self.server_pid, 0)
        super().tearDown()

    def pa_root(self, action, *values, host=False, stdin=b'', timeout=SECONDS):
        return self.pa_root_at(self.socket, action, *values, host=host, stdin=stdin, timeout=timeout)

    def pa_root_at(self, path, action, *values, host=False, stdin=b'', timeout=SECONDS):
        argv = [sys.executable, '-I', CLIENT, *(['--host'] if host else []), action, '--', *values]
        env = {**os.environ, 'PA_ROOT_SOCKET': path}
        return subprocess.run(argv, input=stdin, capture_output=True, env=env, timeout=timeout)

    def connect(self):
        conn = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        conn.connect(self.socket)
        return conn

    def audit_lines(self):
        with open(self.audit_path) as f:
            return f.read().splitlines()

    def audited(self, request=1):
        """The audit entry of the test's `request`th request, once its request process has written it."""
        deadline = time.monotonic() + SECONDS
        while len(self.audit_lines()) < request and time.monotonic() < deadline:
            time.sleep(0.02)
        return json.loads(self.audit_lines()[request - 1].removeprefix(PREFIX))

    def assert_answered(self, result, code, stderr, request=1):
        """The client got `stderr` and exit `code`, and the request's audit entry has that code."""
        self.assertEqual((result.returncode, result.stderr), (code, stderr))
        self.assertEqual(self.audited(request)['exit'], code)

    def broker_alive(self):
        return os.waitpid(self.server_pid, os.WNOHANG) == (0, 0)


class Sleeper:
    """A child process that waits until it is closed, on the stdout and stderr given, once it has them."""

    def __init__(self, stdout=None, stderr=None):
        self.done_r, self.done_w = os.pipe()
        ready_r, ready_w = os.pipe()
        self.pid = os.fork()
        if self.pid == 0:
            os.close(self.done_w)
            for fd, standard in ((stdout, 1), (stderr, 2)):
                if fd is not None:
                    os.dup2(fd, standard)
            os.close(ready_w)
            os.read(self.done_r, 1)
            os._exit(0)
        os.close(self.done_r)
        os.close(ready_w)
        os.read(ready_r, 1)
        os.close(ready_r)

    def close(self):
        if self.pid is None:
            return
        os.write(self.done_w, b'x')
        os.close(self.done_w)
        os.waitpid(self.pid, 0)
        self.pid = None


class Container:
    """A child in a mount namespace of its own, as in another container, that sends one request when asked."""

    def __init__(self, uid=None, user_namespace=True):
        self.asked = False
        ready_r, ready_w = os.pipe()
        self.ask_r, self.ask_w = os.pipe()
        self.answer_r, self.answer_w = os.pipe()
        self.pid = os.fork()
        if self.pid == 0:
            self.serve(ready_w, uid, user_namespace)
        os.close(ready_w)
        self.isolated = os.read(ready_r, 1) == b'y'
        os.close(ready_r)

    def serve(self, ready_w, uid, user_namespace):
        try:
            try:
                os.unshare(os.CLONE_NEWNS | (os.CLONE_NEWUSER if user_namespace else 0))
                if uid is not None:
                    os.setresgid(uid, uid, uid)
                    os.setresuid(uid, uid, uid)
                os.write(ready_w, b'y')
            except OSError:
                os.write(ready_w, b'n')
            asked = json.loads(os.read(self.ask_r, 1 << 16))
            if asked is not None:
                path, action, values = asked
                os.write(self.answer_w, json.dumps(ask(path, action, values)).encode())
        finally:
            os._exit(0)

    def pa_root(self, path, action, *values):
        self.asked = True
        os.write(self.ask_w, json.dumps([path, action, list(values)]).encode())
        code, err = json.loads(os.read(self.answer_r, 1 << 20))
        return code, err

    def close(self):
        if self.pid is None:
            return
        if not self.asked:
            os.write(self.ask_w, b'null')
        for fd in (self.ask_w, self.ask_r, self.answer_r, self.answer_w):
            os.close(fd)
        os.waitpid(self.pid, 0)
        self.pid = None


def ask(path, action, values):
    try:
        conn = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        conn.connect(path)
    except OSError as e:
        return [protocol.EXIT_UNAVAILABLE, str(e)]
    with conn:
        conn.sendall(protocol.request(action, values, False, {}) + protocol.frame(protocol.STDIN_END))
        frames = read_reply(conn)
    err = b''.join(payload for kind, payload in frames if kind == protocol.STDERR).decode(errors='replace')
    code = next((protocol.parse_exit(payload) for kind, payload in frames if kind == protocol.EXIT), None)
    return [code, err]


def read_reply(conn):
    reader = protocol.Reader()
    frames = []
    while True:
        data = conn.recv(65536)
        if not data:
            return frames
        frames += reader.feed(data)
        if frames and frames[-1][0] == protocol.EXIT:
            return frames
