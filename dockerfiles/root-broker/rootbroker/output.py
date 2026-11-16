"""Where an action's output goes: a native action's Output, and the Rewriter that puts the caller's path back where a
command prints the `./name` it was given relative to the opened directory."""

import os

STDOUT, STDERR = 1, 2


class Output:
    """What a native action writes, dropped; it has no client channel to read from or close."""

    channel = None

    def stdout(self, data):
        pass

    def stderr(self, data):
        pass


DROPPED = Output()


class Printed(Output):
    """What a native work writes, on this process's own descriptors: its stdout on `out`, its stderr on `err`."""

    def __init__(self, out=STDOUT, err=STDERR):
        self.out = out
        self.err = err

    def stdout(self, data):
        written(self.out, data)

    def stderr(self, data):
        written(self.err, data)


def written(fd, data):
    while data:
        data = data[os.write(fd, data):]


class Sent(Output):
    """What a native action writes, sent as the frames a command's output would be; its channel, for an action that
    reads the client's input or closes this process's end of it; and the caller's variables the action takes."""

    def __init__(self, channel, passed):
        self.channel = channel
        self.passed = dict(passed)

    def stdout(self, data):
        self.channel.stdout(data)

    def stderr(self, data):
        self.channel.stderr(data)

    def take_early(self):
        return self.channel.take_early()

    def close(self):
        self.channel.close()


class Rewriter:
    def __init__(self, separator, prefix, after_tab=False):
        self.separator = separator
        self.prefix = prefix
        self.after_tab = after_tab
        self.buffer = b''

    def feed(self, data):
        self.buffer += data
        records = self.buffer.split(self.separator)
        self.buffer = records.pop()
        return b''.join(self.record(record) + self.separator for record in records)

    def flush(self):
        rest, self.buffer = self.buffer, b''
        return self.record(rest) if rest else b''

    def record(self, record):
        if not self.after_tab:
            return self.restored(record)
        head, tab, tail = record.partition(b'\t')
        return head + tab + self.restored(tail) if tab else record

    def restored(self, text):
        return self.prefix + text[2:] if text.startswith(b'./') else text
