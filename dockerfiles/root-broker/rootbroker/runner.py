"""Runs a planned command for the client in its own process group with a fixed environment: through pipes, in its own
pseudo-terminal for `pa-root --pty`, or as native work.

A detached action is answered when its command exits; what the job it left running writes is then discarded.
"""

import errno
import functools
import os
import selectors
import signal
import subprocess

from . import protocol, terminal
from .channel import ClientGone
from .output import Sent
from .protocol import CHUNK, exit_code

ENVIRONMENT = {'PATH': '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/root'}
MAX_READY_READS = 64


def environment(plan):
    return {**(plan.environment or ENVIRONMENT), **plan.passed}


def spawn(plan, **streams):
    """The plan's command in a session of its own, in its directory, with its environment."""
    if plan.cwd is not None:
        os.fchdir(plan.cwd)
    return subprocess.Popen(plan.argv, pass_fds=plan.fds, start_new_session=True, env=environment(plan), **streams)


def kill_group(process):
    """SIGKILLs the process's group and reaps the process."""
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait()


class Stdin:
    """The client's stdin on its way to the command, whose input ends once the client's has."""

    def __init__(self, process, selector):
        self.process = process
        self.selector = selector
        self.pending = bytearray()
        self.ended = False
        self.watched = False
        os.set_blocking(process.stdin.fileno(), False)

    def take(self, frames):
        """Queues the frames' input and writes what the command takes; ClientGone for a frame that is not stdin."""
        for kind, payload in frames:
            if kind == protocol.STDIN:
                self.pending += payload if self.process.stdin else b''
            elif kind == protocol.STDIN_END:
                self.ended = True
            else:
                raise ClientGone()
        self.write()

    def write(self):
        stdin = self.process.stdin
        if stdin is None:
            return
        try:
            while self.pending:
                del self.pending[:os.write(stdin.fileno(), self.pending)]
        except BlockingIOError:
            self.watch(True)
            return
        except BrokenPipeError:
            self.pending.clear()
            self.ended = True
        self.watch(False)
        if self.ended:
            self.close()

    def watch(self, watch):
        if watch and not self.watched:
            self.selector.register(self.process.stdin, selectors.EVENT_WRITE, self.write)
        elif not watch and self.watched:
            self.selector.unregister(self.process.stdin)
        self.watched = watch

    def close(self):
        self.watch(False)
        if self.process.stdin is not None:
            self.process.stdin.close()
            self.process.stdin = None


class Pump:
    """The command's stdout and stderr to the client as they come, the client's stdin to the command."""

    def __init__(self, channel, process, rewrite, detach=False, answered=None):
        self.channel = channel
        self.process = process
        self.detach = detach
        self.answered = answered
        self.selector = selectors.PollSelector()
        self.stdin = Stdin(process, self.selector)
        self.readers = ((process.stdout, functools.partial(self.read, process.stdout, protocol.STDOUT, rewrite)),
                        (process.stderr, functools.partial(self.read, process.stderr, protocol.STDERR, None)))
        self.discarding = False
        self.exited = False

    def run(self):
        """The command's exit code once it has ended and said all it had to; None once the client has hung up."""
        self.selector.register(self.channel, selectors.EVENT_READ, self.from_client)
        for stream, read in self.readers:
            self.selector.register(stream, selectors.EVENT_READ, read)
        exit_watch = self.watch_exit()
        try:
            self.stdin.take(self.channel.take_early())
            while (self.reading() or not self.exited) and not (self.detach and self.exited):
                self.select(None if self.reading() or exit_watch else 0.05)
                self.exited = self.exited or (not exit_watch and self.process.poll() is not None)
        except ClientGone:
            kill_group(self.process)
            return None
        except protocol.ProtocolError:
            kill_group(self.process)
            raise
        code = exit_code(self.process.wait())
        if self.detach and self.reading():
            return self.outlive(code)
        self.channel.exit(code)
        return code

    def outlive(self, code):
        """Answers the client, then reads and drops what the command's job writes until it closes its output."""
        try:
            self.send_ready()
            self.channel.exit(code)
        except ClientGone:
            pass
        if self.answered is not None:
            self.answered(code)
        self.discarding = True
        self.selector.unregister(self.channel)
        self.stdin.close()
        while self.reading():
            self.select(None)
        return code

    def send_ready(self):
        """Sends what the command wrote without waiting for its job's later output, from each stream still open."""
        for stream, read in self.open_readers():
            os.set_blocking(stream.fileno(), False)
            try:
                for _ in range(MAX_READY_READS):
                    if stream.closed:
                        break
                    read()
            except BlockingIOError:
                pass
            if not stream.closed:
                os.set_blocking(stream.fileno(), True)

    def select(self, timeout):
        for key, _ in self.selector.select(timeout):
            key.data()

    def open_readers(self):
        return [(stream, read) for stream, read in self.readers if not stream.closed]

    def reading(self):
        return bool(self.open_readers())

    def watch_exit(self):
        try:
            pidfd = os.pidfd_open(self.process.pid)
        except (AttributeError, OSError):
            return False
        self.selector.register(pidfd, selectors.EVENT_READ, functools.partial(self.on_exit, pidfd))
        return True

    def on_exit(self, pidfd):
        self.selector.unregister(pidfd)
        os.close(pidfd)
        self.exited = True

    def from_client(self):
        self.stdin.take(self.channel.receive())

    def read(self, stream, kind, rewrite):
        data = os.read(stream.fileno(), CHUNK)
        if not data:
            self.selector.unregister(stream)
            stream.close()
        if rewrite is not None:
            data = rewrite.feed(data) if data else rewrite.flush()
        if not self.discarding:
            self.channel.send(kind, data)


class Console:
    """The command's terminal shown to the client, and what the client types and its resizes given to it."""

    def __init__(self, channel, process, controller):
        self.channel = channel
        self.process = process
        self.controller = controller
        self.selector = selectors.PollSelector()
        self.pending = bytearray()
        self.shown = False
        os.set_blocking(controller, False)

    def run(self):
        self.selector.register(self.channel, selectors.EVENT_READ, self.from_client)
        self.selector.register(self.controller, selectors.EVENT_READ, self.from_terminal)
        try:
            self.frames(self.channel.take_early())
            while not self.shown:
                for key, _ in self.selector.select():
                    key.data()
            code = exit_code(self.process.wait())
            self.channel.exit(code)
        except ClientGone:
            kill_group(self.process)
            return None
        finally:
            os.close(self.controller)
        return code

    def from_client(self):
        self.frames(self.channel.receive())

    def frames(self, frames):
        for kind, payload in frames:
            if kind == protocol.STDIN:
                self.pending += payload
            elif kind == protocol.RESIZE:
                terminal.set_size(self.controller, *protocol.parse_resize(payload))
            elif kind != protocol.STDIN_END:
                raise ClientGone()
        self.type()

    def type(self):
        try:
            while self.pending:
                del self.pending[:os.write(self.controller, self.pending)]
        except BlockingIOError:
            self.watch_typing(True)
            return
        except OSError:
            self.pending.clear()
        self.watch_typing(False)

    def watch_typing(self, watch):
        events = selectors.EVENT_READ | (selectors.EVENT_WRITE if watch else 0)
        self.selector.modify(self.controller, events, self.from_terminal)

    def from_terminal(self):
        if self.pending:
            self.type()
        try:
            data = os.read(self.controller, CHUNK)
        except BlockingIOError:
            return
        except OSError as e:
            if e.errno != errno.EIO:
                raise
            data = b''
        if not data:
            self.selector.unregister(self.controller)
            self.shown = True
            return
        self.channel.stdout(data)


def in_terminal(plan):
    """Starts the command in a new session on a new pty's other end and returns it with the pty."""
    controller, own = os.openpty()
    try:
        terminal.set_size(controller, *plan.terminal)
        process = spawn(plan, stdin=own, stdout=own, stderr=own, preexec_fn=terminal.take_terminal)
    except BaseException:
        os.close(controller)
        raise
    finally:
        os.close(own)
    return process, controller


def started(channel, plan, answered):
    """What relays the plan's command, started in a pty when the client asked for one, else through pipes."""
    if plan.terminal is not None:
        return Console(channel, *in_terminal(plan))
    process = spawn(plan, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return Pump(channel, process, plan.rewrite, plan.detach, answered)


def run_native(channel, plan):
    try:
        code = plan.native(Sent(channel, plan.passed))
        channel.exit(code)
    except ClientGone:
        return None
    return code


def run(channel, plan, answered=None):
    """The plan's exit code once the client is answered; None once the client has hung up."""
    if plan.native is not None:
        return run_native(channel, plan)
    try:
        relayed = started(channel, plan, answered)
    except FileNotFoundError:
        channel.answer(f'pa-root: {plan.argv[0]}: command not found\n', 127)
        return 127
    return relayed.run()
