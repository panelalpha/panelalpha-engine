"""Plans each scripts-cases.json `broker` command on a scratch tree and compares it word for word with its `sudo` one.

A line case compares the sudo line's words after `sudo` with the broker's argv, its glob expanded from the same place.
Run as a script, it prints the comparison as a Markdown table.
"""

import json
import os
import shlex
import shutil
import sys
import tempfile

import dockerwork
import support
from rootbroker.accounts import Accounts
from rootbroker.actions import ROOTS, Policy, lines, registry

CASES = os.path.join(support.HERE, 'scripts-cases.json')
HOST = ['sudo', 'nsenter', '--target', '1', '--all']
PLACED = ('engine', 'homes')
GLOB_SCRIPTS = (lines.REMOVE_SCRIPTS, lines.REMOVE_SCRIPTS_IF_ANY, lines.REMOVE_PROXY_RULE_CONFIGS_IF_ANY,
                lines.EMPTY_DIRECTORY)
ALICE = (1004, 1004)
FILES = ('docker-compose.yml', 'users/alice/docker-compose.yml')
DIRECTORIES = (
    'users/alice/entrypoint-init.d', 'users/alice/entrypoint.d', 'users/alice/apache-sites',
    'webserver-config/apache/vhosts', 'webserver-config/litespeed/vhosts', 'webserver-config/nginx-proxy/vhosts',
    'templates/user/dind/project', 'data/build-cache/0a1b2c3d4e5f/out-1a2b3c4d',
)


def load():
    with open(CASES) as f:
        return json.load(f)


def is_line(case):
    return isinstance(case['broker'], str)


def request(case):
    return dockerwork.split_broker(shlex.split(case['broker']) if is_line(case) else case['broker'])


def expected(case):
    """The `sudo` command without its `sudo` or `sudo nsenter ...` prefix."""
    words = shlex.split(case['sudo']) if isinstance(case['sudo'], str) else case['sudo']
    for prefix in (dockerwork.HOST_SUDO, HOST, dockerwork.SUDO):
        if words[:len(prefix)] == prefix:
            return words[len(prefix):]
    raise AssertionError(f'not a sudo command: {case["sudo"]}')


class Scratch:
    """The engine directory and the homes below one scratch directory, an account `alice`, and the way back out."""

    def __init__(self, alice=ALICE):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-scripts-'))
        os.chmod(self.base, 0o755)
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        engine = self.roots['engine']
        for name in DIRECTORIES:
            os.makedirs(os.path.join(engine, name), mode=0o755, exist_ok=True)
        for name in FILES:
            with open(os.path.join(engine, name), 'w') as f:
                f.write('services: {}\n')
        for directory in (engine, *[os.path.join(engine, *name.split('/')[:depth]) for name in FILES
                                    for depth in range(1, name.count('/') + 1)]):
            os.chmod(directory, 0o755)
        self.home = os.path.join(self.roots['homes'], 'alice')
        os.makedirs(self.home)
        passwd = os.path.join(self.base, 'passwd')
        with open(passwd, 'w') as f:
            f.write(f'alice:x:{alice[0]}:{alice[1]}::{self.home}:/bin/sh\n')
        self.policy = Policy(self.roots, owners={os.getuid()}, host_root='', engine_owners={os.getuid()},
                             accounts_db=Accounts(passwd, self.roots['homes']), render=support.no_services)

    def close(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def inside(self, value):
        if any(value == ROOTS[name] or value.startswith(ROOTS[name] + '/') for name in PLACED):
            return self.base + value
        return value

    def outside(self, word):
        return word.replace(self.base, '')


def plan_case(scratch, case):
    host, _, name, given = request(case)
    action = registry()[name]
    return action.plan(action.check([scratch.inside(value) for value in given]), scratch.policy, host)


def resolved(scratch, plan, word):
    """A placed operand as the path it names, and every scratch path in a word as the host's."""
    if word.startswith('./') and plan.cwd is not None:
        return scratch.outside(os.readlink(f'/proc/self/fd/{plan.cwd}') + word[1:])
    for fd in plan.fds:
        prefix = f'/proc/self/fd/{fd}'
        if word == prefix or word.startswith(prefix + '/'):
            return scratch.outside(os.readlink(prefix) + word[len(prefix):])
    return scratch.outside(word)


def effective(scratch, plan):
    """The words the broker's command amounts to, with a file line's glob directory put back in."""
    argv = plan.argv
    if argv[:2] == ['sh', '-c'] and argv[2] in GLOB_SCRIPTS:
        return shlex.split(argv[2].replace('"$1"', shlex.quote(resolved(scratch, plan, argv[4]))))
    return [resolved(scratch, plan, word) for word in support.as_sudo_named(plan)]


def close(plan):
    dockerwork.close(plan)


def markdown_cell(text):
    return '`' + text.replace('|', '\\|').replace('\n', '\\n') + '`'


def shown(words):
    return ' '.join(shlex.quote(word) for word in words)


def described(scratch, plan, word):
    """A placed operand as the broker hands it over, with the path it names."""
    if word.startswith(('./', '/proc/self/fd/')):
        return f'{shlex.quote(word)} [= {resolved(scratch, plan, word)}]'
    return shlex.quote(scratch.outside(word))


def table():
    rows = ['| case | host | today (sudo) | asked of the broker | what the broker runs | working directory |',
            '|---|---|---|---|---|---|']
    for case in load():
        scratch = Scratch()
        try:
            plan = plan_case(scratch, case)
            cwd = scratch.outside(os.readlink(f'/proc/self/fd/{plan.cwd}')) if plan.cwd is not None else '-'
            given = case.get('sudo')
            sudo = '-' if given is None else given if isinstance(given, str) else shown(given)
            broker = case['broker'] if is_line(case) else shown(case['broker'])
            runs = ' '.join(described(scratch, plan, word) for word in (plan.argv or []))
            rows.append(f"| {case['name']} | {'yes' if case['host'] else ''} | {markdown_cell(sudo)} | "
                        f"{markdown_cell(broker)} | {markdown_cell(runs)} | `{cwd}` |")
            close(plan)
        finally:
            scratch.close()
    return '\n'.join(rows) + '\n'


if __name__ == '__main__':
    sys.stdout.write(table())
