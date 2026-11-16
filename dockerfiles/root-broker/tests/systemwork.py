"""Plans each system-cases.json `broker` request on a scratch tree and compares it word for word with its `sudo` argv.

Run as a script, it prints the comparison as a Markdown table.
"""

import json
import os
import shlex
import shutil
import sys
import tempfile

import support
from rootbroker.accounts import Accounts
from rootbroker.actions import ROOTS, Policy, registry

CASES = os.path.join(support.HERE, 'system-cases.json')
HOST_PREFIX = ['sudo', 'nsenter', '--target', '1', '--all']
PLACED = ('homes', 'engine', 'panelalpha', 'core-storage')
PLACING = ('users.create', 'probes.diskUsageInKilobytes', 'webserver.queueReload', 'backup-store.')


def load():
    with open(CASES) as f:
        return json.load(f)


def split_broker(argv):
    host = argv[1] == '--host'
    rest = argv[2:] if host else argv[1:]
    rest = rest[1:] if rest[0] == '--pty' else rest
    return host, rest[0], rest[2:]


def unprivileged(sudo, host):
    prefix = HOST_PREFIX if host else ['sudo']
    if sudo[:len(prefix)] != prefix:
        raise AssertionError(f'not a sudo argv: {sudo}')
    return sudo[len(prefix):]


class Scratch:
    """The host's roots below one scratch directory, an account `alice` in its passwd, and the way back to host paths."""

    def __init__(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-system-'))
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        passwd = os.path.join(self.base, 'passwd')
        with open(passwd, 'w') as f:
            f.write(f"root:x:0:0:root:/root:/bin/bash\nalice:x:1004:1005::{self.roots['homes']}/alice:/usr/sbin/nologin\n")
        self.policy = Policy(self.roots, accounts_db=Accounts(passwd, self.roots['homes']), host_root='',
                             script_owners={os.getuid()})
        os.makedirs(os.path.join(self.roots['engine']), exist_ok=True)

    def close(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def inside(self, value):
        """A path the action places or compares with a root, made in the scratch tree. Fixed host files stay put."""
        if any(value == ROOTS[name] or value.startswith(ROOTS[name] + '/') for name in PLACED):
            os.makedirs(self.base + value, exist_ok=True)
            return self.base + value
        return value

    def outside(self, word):
        return word.replace(self.base, '')


def plan_case(scratch, case):
    host, name, given = split_broker(case['broker'])
    action = registry()[name]
    placing = any(name == placed or (placed.endswith('.') and name.startswith(placed)) for placed in PLACING)
    given = [scratch.inside(value) for value in given] if placing else given
    return action.plan(action.check(given), scratch.policy, host)


def resolved(scratch, plan, word):
    cwd = os.readlink(f'/proc/self/fd/{plan.cwd}') if plan.cwd is not None else None
    if word.startswith('./') and cwd is not None:
        return scratch.outside(cwd + word[1:])
    for fd in plan.fds:
        prefix = f'/proc/self/fd/{fd}'
        if word == prefix or word.startswith(prefix + '/'):
            return scratch.outside(os.readlink(prefix) + word[len(prefix):])
    return scratch.outside(word)


def resolved_argv(scratch, plan):
    return [resolved(scratch, plan, word) for word in (plan.argv or [])]


def close(plan):
    for fd in [plan.cwd, *plan.fds]:
        if fd is not None:
            try:
                os.close(fd)
            except OSError:
                pass


def markdown_cell(words):
    return '`' + ' '.join(words).replace('|', '\\|').replace('\n', '\\n') + '`'


def table():
    rows = ['| case | host | sudo argv today | argv the broker runs | working directory |', '|---|---|---|---|---|']
    for case in load():
        scratch = Scratch()
        try:
            plan = plan_case(scratch, case)
            cwd = scratch.outside(os.readlink(f'/proc/self/fd/{plan.cwd}')) if plan.cwd is not None else '-'
            broker = '-' if plan.argv is None else markdown_cell(map(shlex.quote, resolved_argv(scratch, plan)))
            rows.append(f"| {case['name']} | {'yes' if case['host'] else ''} | {markdown_cell(map(shlex.quote, case.get('sudo', [])))} | "
                        f"{broker} | `{cwd}` |")
            close(plan)
        finally:
            scratch.close()
    return '\n'.join(rows) + '\n'


if __name__ == '__main__':
    sys.stdout.write(table())
