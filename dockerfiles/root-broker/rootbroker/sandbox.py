"""The docker client run as an account, outside its container, against that account's own daemon.

It runs as the account's uid with no new privileges in namespaces of its own, chrooted into a read-only tmpfs with
the docker binaries, the home, `/tmp` and a relay to the account's dockerd; nothing in the home or `/tmp` executes.
Its network is the account container's when the daemon holds that, so it reaches what the account does and looks
names up as the account does, with the CA bundle, since BuildKit has the client fetch a registry's token. Else it
has none.
"""

import ctypes
import os
import resource
import select
import selectors
import shutil
import signal
import socket
import tempfile
import threading
import time

from . import hostns
from .dockerapi import Unavailable
from .libc import LIBC
from .protocol import CHUNK, exit_code

RUN = '/run/panelalpha-compose'
CONFINED = True
DOCKER = '/usr/bin/docker'
PLUGINS = '/usr/libexec/docker/cli-plugins'
LIBRARIES = ('/usr/lib', '/usr/lib64')
DEVICES = ('null', 'zero', 'random', 'urandom')
SOCKET = '/run/docker.sock'
RESOLVER = 'nameserver 127.0.0.11\noptions ndots:0\n'
CERTIFICATES = '/etc/ssl/certs/ca-certificates.crt'
STOP_SECONDS = 10
DEADLINE_SECONDS = 7200
CONNECTIONS = 128
TMP_SIZE = '512m'
LIMITS = ((resource.RLIMIT_CORE, 0), (resource.RLIMIT_NOFILE, 4096), (resource.RLIMIT_DATA, 2 << 30))
OOM_SCORE_ADJ = 1000
DEADLINE = 'docker {client} for {name} did not finish within {seconds}s; the broker stopped it'
CROWDED = ('docker {client} for {name} opened more than {limit} connections to its daemon at once; '
           'the broker closed {refused} of them')

MS_RDONLY = 0x1
MS_NOSUID = 0x2
MS_NODEV = 0x4
MS_NOEXEC = 0x8
MS_REMOUNT = 0x20
MS_BIND = 0x1000
MS_REC = 0x4000
MS_PRIVATE = 0x40000
PR_SET_PDEATHSIG = 1
PR_SET_NO_NEW_PRIVS = 38
PR_SET_KEEPCAPS = 8
PR_CAPBSET_DROP = 24
PR_CAP_AMBIENT = 47
PR_CAP_AMBIENT_RAISE = 2
CAP_DAC_OVERRIDE = 1
CAP_DAC_READ_SEARCH = 2
CAP_LAST = 63
FILE_CAPABILITIES = (CAP_DAC_OVERRIDE, CAP_DAC_READ_SEARCH)
CAPABILITY_VERSION_3 = 0x20080522
NAMESPACES = os.CLONE_NEWNS | os.CLONE_NEWNET | os.CLONE_NEWIPC | os.CLONE_NEWUTS | os.CLONE_NEWPID


class CapHeader(ctypes.Structure):
    _fields_ = [('version', ctypes.c_uint32), ('pid', ctypes.c_int)]


class CapData(ctypes.Structure):
    _fields_ = [('effective', ctypes.c_uint32), ('permitted', ctypes.c_uint32), ('inheritable', ctypes.c_uint32)]


def mount(source, target, fstype=None, flags=0, data=None):
    encoded = [None if v is None else v.encode() for v in (source, target, fstype, data)]
    if LIBC.mount(encoded[0], encoded[1], encoded[2], flags, encoded[3]) != 0:
        error = ctypes.get_errno()
        raise OSError(error, f'mount {target}: {os.strerror(error)}')


def bind(source, target, flags=0):
    mount(source, target, None, MS_BIND | MS_REC)
    mount(None, target, None, MS_BIND | MS_REMOUNT | MS_NOSUID | MS_NODEV | flags)


def placeholder(path, directory):
    os.makedirs(path if directory else os.path.dirname(path), mode=0o755, exist_ok=True)
    if not directory:
        open(path, 'a').close()


class Relay:
    """`docker.sock` for the client: every connection it opens is piped, byte for byte, to a new one to the daemon, at
    most CONNECTIONS at once. One past them is closed as it comes, and counted in `refused`."""

    def __init__(self, connect, account):
        self.connect = connect
        self.directory = tempfile.mkdtemp(prefix=f'{account.name}-', dir=self.run_directory())
        self.root = os.path.join(self.directory, 'root')
        os.mkdir(self.root, 0o755)
        self.path = os.path.join(self.directory, 'docker.sock')
        self.listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.listener.bind(self.path)
        if CONFINED:
            os.chown(self.path, account.uid, account.gid)
        os.chmod(self.path, 0o600)
        self.listener.listen(64)
        self.pairs = set()
        self.refused = 0
        self.lock = threading.Lock()

    @staticmethod
    def run_directory():
        os.makedirs(RUN, mode=0o711, exist_ok=True)
        return RUN

    def start(self):
        threading.Thread(target=self.serve, daemon=True).start()

    def serve(self):
        while True:
            try:
                client, _ = self.listener.accept()
            except OSError:
                return
            if not self.admits():
                client.close()
                continue
            try:
                upstream = self.connect()
            except OSError:
                client.close()
                continue
            pair = Pair(client, upstream, self.ended)
            with self.lock:
                self.pairs.add(pair)
            pair.start()

    def admits(self):
        """Whether one more connection fits; one that does not is counted."""
        with self.lock:
            if len(self.pairs) < CONNECTIONS:
                return True
            self.refused += 1
            return False

    def ended(self, pair):
        with self.lock:
            self.pairs.discard(pair)

    def close(self):
        self.listener.close()
        with self.lock:
            pairs = list(self.pairs)
        for pair in pairs:
            pair.close()
        shutil.rmtree(self.directory, ignore_errors=True)


class Pair:
    """Both directions of one relayed connection; each half-closes the other side, and the last one closes both and
    hands the pair to `ended`."""

    def __init__(self, a, b, ended):
        self.sockets = (a, b)
        self.ended = ended
        self.left = 2
        self.lock = threading.Lock()

    def start(self):
        a, b = self.sockets
        threading.Thread(target=self.pump, args=(a, b), daemon=True).start()
        threading.Thread(target=self.pump, args=(b, a), daemon=True).start()

    def pump(self, source, target):
        try:
            while data := source.recv(CHUNK):
                target.sendall(data)
        except OSError:
            pass
        try:
            target.shutdown(socket.SHUT_WR)
        except OSError:
            pass
        with self.lock:
            self.left -= 1
            last = self.left == 0
        if last:
            self.close()
            self.ended(self)

    def close(self):
        for conn in self.sockets:
            try:
                conn.close()
            except OSError:
                pass


def environment(account, given, relay_path):
    env = {'PATH': '/usr/bin', 'HOME': account.home, 'USER': account.name, 'LOGNAME': account.name}
    env.update(given)
    env['DOCKER_HOST'] = f'unix://{SOCKET if CONFINED else relay_path}'
    return env


def prctl(option, value=0):
    if LIBC.prctl(option, value, 0, 0, 0) != 0:
        raise OSError(ctypes.get_errno(), f'prctl {option}')


def keep_file_capabilities():
    """After setuid: only the file capabilities, effective and ambient, so docker and its plugins keep them."""
    mask = sum(1 << cap for cap in FILE_CAPABILITIES)
    data = (CapData * 2)(CapData(mask, mask, mask), CapData(0, 0, 0))
    if LIBC.capset(ctypes.byref(CapHeader(CAPABILITY_VERSION_3, 0)), data) != 0:
        raise OSError(ctypes.get_errno(), 'capset')
    for cap in FILE_CAPABILITIES:
        if LIBC.prctl(PR_CAP_AMBIENT, PR_CAP_AMBIENT_RAISE, cap, 0, 0) != 0:
            raise OSError(ctypes.get_errno(), 'ambient capability')


def enter(account, relay_path, root, files=False, joined=False):
    """In the child: the namespaces, the tmpfs root and its few mounts, then the account's uid, with $files only the
    file capabilities; $joined keeps the account's network the child already entered."""
    os.unshare(NAMESPACES & ~os.CLONE_NEWNET if joined else NAMESPACES)
    build_root(root, account, relay_path, joined)
    os.chroot(root)
    drop_bounding_set(files)
    become(account, files)


def inside(root, path):
    return os.path.join(root, path.lstrip('/'))


def build_root(root, account, relay_path, joined=False):
    """The tmpfs root in a mount tree private to the namespace, and all it holds, read-only once it holds it."""
    mount(None, '/', None, MS_REC | MS_PRIVATE)
    mount('tmpfs', root, 'tmpfs', MS_NOSUID | MS_NODEV, 'mode=0755,size=16m')
    bind_binaries(root)
    bind_devices(root)
    mount_tmp(root)
    bind_socket(root, relay_path)
    bind_home(root, account)
    write_identity(root, account)
    if joined:
        bind_certificates(root)
        write_resolver(root)
    mount(None, root, None, MS_BIND | MS_REMOUNT | MS_RDONLY | MS_NOSUID | MS_NODEV)


def bind_binaries(root):
    """docker, its plugins and the libraries, read-only, and the `lib` links into them."""
    libraries = [(library, True) for library in LIBRARIES if os.path.isdir(library)]
    for path, directory in ((DOCKER, False), (PLUGINS, True), *libraries):
        placeholder(inside(root, path), directory)
        bind(path, inside(root, path), MS_RDONLY)
    for link in ('lib', 'lib64'):
        os.symlink(f'usr/{link}', inside(root, f'/{link}'))


def bind_devices(root):
    for device in DEVICES:
        placeholder(inside(root, f'/dev/{device}'), False)
        mount(f'/dev/{device}', inside(root, f'/dev/{device}'), None, MS_BIND)


def mount_tmp(root):
    placeholder(inside(root, '/tmp'), True)
    mount('tmpfs', inside(root, '/tmp'), 'tmpfs', MS_NOSUID | MS_NODEV | MS_NOEXEC, f'mode=1777,size={TMP_SIZE}')


def bind_socket(root, relay_path):
    placeholder(inside(root, SOCKET), False)
    mount(relay_path, inside(root, SOCKET), None, MS_BIND)


def bind_home(root, account):
    """The account's home at its own path, nothing in it executable."""
    placeholder(inside(root, account.home), True)
    home = os.open(account.home, os.O_PATH | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        bind(f'/proc/self/fd/{home}', inside(root, account.home), MS_NOEXEC)
    finally:
        os.close(home)


def write_identity(root, account):
    """The account's lines in `/etc/passwd` and `/etc/group`."""
    os.makedirs(inside(root, '/etc'), exist_ok=True)
    with open(inside(root, '/etc/passwd'), 'w') as passwd:
        passwd.write(f'{account.name}:x:{account.uid}:{account.gid}::{account.home}:/bin/sh\n')
    with open(inside(root, '/etc/group'), 'w') as group:
        group.write(f'{account.name}:x:{account.gid}:\n')


def bind_certificates(root):
    """The CA bundle, read-only, for the client's TLS to a registry; nothing when the image has none."""
    if os.path.isfile(CERTIFICATES):
        placeholder(inside(root, CERTIFICATES), False)
        bind(CERTIFICATES, inside(root, CERTIFICATES), MS_RDONLY)


def write_resolver(root):
    """`/etc/resolv.conf` naming Docker's DNS on the account's network, as the account's own does."""
    with open(inside(root, '/etc/resolv.conf'), 'w') as resolver:
        resolver.write(RESOLVER)


def drop_bounding_set(files):
    """Every capability out of the bounding set, with $files all but the file capabilities."""
    for cap in range(CAP_LAST + 1):
        if cap not in FILE_CAPABILITIES or not files:
            LIBC.prctl(PR_CAPBSET_DROP, cap, 0, 0, 0)


def become(account, files):
    """The account's groups, gid and uid, with $files keeping the file capabilities, and no new privileges."""
    prctl(PR_SET_KEEPCAPS, 1 if files else 0)
    os.setgroups([])
    os.setgid(account.gid)
    os.setuid(account.uid)
    if files:
        keep_file_capabilities()
    prctl(PR_SET_NO_NEW_PRIVS, 1)


def die_with(parent):
    """SIGKILL once `parent` dies, set after the last change of credentials, which clears it; a parent already gone ends
    this process at once."""
    prctl(PR_SET_PDEATHSIG, signal.SIGKILL)
    if os.getppid() != parent:
        os._exit(1)


def limit():
    """The client's LIMITS, and its OOM score: of the processes in the broker's container, the kernel's OOM killer
    ends the client first."""
    for kind, value in LIMITS:
        resource.setrlimit(kind, (value, value))
    with open('/proc/self/oom_score_adj', 'w') as score:
        score.write(str(OOM_SCORE_ADJ))


def spawn(account, relay, workdir, given, argv, files=False, network=None):
    """Forks the process that starts the client under its init, in the network namespace `network` holds when it holds
    one; returns its pid, which leads the client's process group, and the read ends of the client's stdout and stderr."""
    out_r, out_w = os.pipe()
    err_r, err_w = os.pipe()
    parent = os.getpid()
    pid = os.fork()
    if pid == 0:
        try:
            os.close(out_r)
            os.close(err_r)
            joined = CONFINED and network is not None
            if joined:
                hostns.setns(network)
            null = os.open('/dev/null', os.O_RDONLY)
            os.dup2(null, 0)
            os.dup2(out_w, 1)
            os.dup2(err_w, 2)
            os.closerange(3, os.sysconf('SC_OPEN_MAX'))
            os.setsid()
            limit()
            if CONFINED:
                enter(account, relay.path, relay.root, files, joined)
            die_with(parent)
            os.chdir(workdir)
            os._exit(under_init(['docker', *argv], environment(account, given, relay.path)))
        except BaseException as e:
            try:
                os.write(2, f'the docker client could not start for {account.name}: {e}\n'.encode())
            finally:
                os._exit(126)
    os.close(out_w)
    os.close(err_w)
    return pid, out_r, err_r


def under_init(argv, env):
    """Forks the init of the PID namespace `enter` made, which forks the client, and returns its exit code. Both ignore
    SIGTERM, which the client takes; `lifeline` stops an init whose parent died before its PDEATHSIG was set."""
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    lifeline, held = os.pipe()
    pid = os.fork()
    if pid == 0:
        os.close(held)
        prctl(PR_SET_PDEATHSIG, signal.SIGKILL)
        if select.select([lifeline], [], [], 0)[0]:
            os._exit(1)
        os.close(lifeline)
        os._exit(init(client(argv, env)))
    os.close(lifeline)
    return reaped(pid)


def client(argv, env):
    """Forks DOCKER with `argv` and `env`, SIGTERM back at its default; returns its pid."""
    pid = os.fork()
    if pid == 0:
        signal.signal(signal.SIGTERM, signal.SIG_DFL)
        os.execve(DOCKER, argv, env)
    return pid


def init(pid):
    """Reaps every process the namespace leaves to it, and returns the client's exit code once `pid`, the client, has
    ended. Its own end then ends the namespace: the kernel kills whatever is left in it."""
    while True:
        ended, status = os.waitpid(-1, 0)
        if ended == pid:
            return exit_code(os.waitstatus_to_exitcode(status))


def stop(pid):
    try:
        os.killpg(pid, signal.SIGTERM)
    except ProcessLookupError:
        return
    deadline = time.monotonic() + STOP_SECONDS
    while time.monotonic() < deadline:
        if os.waitpid(pid, os.WNOHANG)[0] == pid:
            return
        time.sleep(0.1)
    try:
        os.killpg(pid, signal.SIGKILL)
    except ProcessLookupError:
        pass


def run(connect, account, workdir, given, argv, output, files=False, network=None):
    """`docker <argv>` as the account against its daemon, with $files root's reach over files, in the network
    namespace `network` holds, streamed to `output`; returns its exit code. A client hanging up stops it, and
    DEADLINE_SECONDS raises Unavailable."""
    relay = Relay(connect, account)
    words = {'client': argv[0], 'name': account.name}
    try:
        pid, out_r, err_r = spawn(account, relay, workdir, given, argv, files, network)
        relay.start()
        code = follow(pid, out_r, err_r, output, time.monotonic() + DEADLINE_SECONDS)
    except TimeoutError as e:
        raise Unavailable(DEADLINE.format(seconds=DEADLINE_SECONDS, **words)) from e
    finally:
        relay.close()
    if relay.refused:
        output.stderr(f'{CROWDED.format(limit=CONNECTIONS, refused=relay.refused, **words)}\n'.encode())
    return code


def follow(pid, out_r, err_r, output, deadline):
    """The client's exit code once its output has ended; it streams to `output` as it comes. A caller that hangs up
    stops the client; so does `deadline` passing, which then raises TimeoutError."""
    pipes = {out_r: output.stdout, err_r: output.stderr}
    with selectors.DefaultSelector() as selector:
        for fd in pipes:
            selector.register(fd, selectors.EVENT_READ, 'pipe')
        if output.channel is not None:
            selector.register(output.channel, selectors.EVENT_READ, 'client')
        while pipes:
            ready = selector.select(deadline - time.monotonic())
            if time.monotonic() >= deadline:
                stopped(pid, pipes)
                raise TimeoutError()
            for key, _ in ready:
                if key.data == 'client':
                    if output.channel.hung_up():
                        return stopped(pid, pipes)
                    continue
                data = os.read(key.fd, CHUNK)
                if data:
                    pipes[key.fd](data)
                    continue
                selector.unregister(key.fd)
                os.close(key.fd)
                del pipes[key.fd]
    return reaped(pid)


def stopped(pid, pipes):
    """Stops the client, closes its pipes and reaps what started it."""
    stop(pid)
    for fd in pipes:
        os.close(fd)
    return reaped(pid)


def reaped(pid):
    try:
        _, status = os.waitpid(pid, 0)
    except ChildProcessError:
        return 1
    return exit_code(os.waitstatus_to_exitcode(status))
