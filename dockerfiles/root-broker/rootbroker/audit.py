"""One `pa-root-audit {...}` line per request on stdout. Never what the caller sent on stdin."""

import json
import os
import time

PREFIX = 'pa-root-audit '


class Audit:
    def __init__(self, fd=1):
        self.fd = fd

    def write(self, entry):
        line = PREFIX + json.dumps(entry, ensure_ascii=True, sort_keys=True) + '\n'
        os.write(self.fd, line.encode('ascii'))


class Audited:
    """One request's entry, written once it ends, and also once a detached command is answered while its job runs."""

    def __init__(self, audit, pid, uid, started, via=None):
        self.audit = audit
        self.started = started
        self.fields = {'peer_pid': pid, 'peer_uid': uid, **({'via': via} if via else {}), 'exit': None}

    def answered(self, code):
        self.audit.write({**self.fields, 'exit': code, 'seconds': self.seconds(), 'detached': 'running'})
        self.fields['detached'] = 'ended'

    def finish(self):
        self.fields['seconds'] = self.seconds()
        self.audit.write(self.fields)

    def seconds(self):
        return round(time.monotonic() - self.started, 3)
