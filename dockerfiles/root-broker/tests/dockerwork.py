"""Plans each docker-cases.json `broker` request on a scratch tree and compares it word for word with its `sudo` argv.

Run as a script, it prints the comparison as a Markdown table.
"""

import json
import os
import shlex
import shutil
import sys
import tempfile

import support
from rootbroker.actions import ROOTS, Policy, registry

CASES = os.path.join(support.HERE, 'docker-cases.json')
SUDO = ['sudo']
HOST_SUDO = ['sudo', 'nsenter', '--target', '1', '--all', 'sudo']
PLACED = ('engine', 'project-caches')
COMPOSE_FILES = ('docker-compose.yml', 'users/alice/docker-compose.yml')


def load():
    with open(CASES) as f:
        return json.load(f)


def split_broker(argv):
    rest = argv[1:]
    host = rest[0] == '--host'
    rest = rest[1:] if host else rest
    rest = rest[1:] if rest[0] == '--pty' else rest
    passing = []
    while rest[0] == '--env':
        passing.append(rest[1])
        rest = rest[2:]
    return host, passing, rest[0], rest[2:]


def unprivileged(sudo, host):
    prefix = HOST_SUDO if host else SUDO
    if sudo[:len(prefix)] != prefix:
        raise AssertionError(f'not a sudo argv: {sudo}')
    rest = sudo[len(prefix):]
    passed = [word.removeprefix('--preserve-env=') for word in rest if word.startswith('--preserve-env=')]
    return [word for word in rest if not word.startswith('--preserve-env=')], passed


class Scratch:
    """The engine directory, the homes and the project caches below one scratch directory, owned by the test's user."""

    def __init__(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-docker-'))
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        self.policy = Policy(self.roots, owners={os.getuid()}, host_root='', engine_owners={os.getuid()},
                             render=support.no_services)
        for name in COMPOSE_FILES:
            path = os.path.join(self.roots['engine'], name)
            os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
            with open(path, 'w') as f:
                f.write('services: {}\n')
        for directory in (self.roots['engine'], os.path.join(self.roots['engine'], 'users'),
                          os.path.join(self.roots['engine'], 'users', 'alice')):
            os.chmod(directory, 0o755)
        os.makedirs(os.path.join(self.roots['homes'], 'alice', 'project'))

    def close(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def inside(self, value):
        """A path the broker opens (below the engine directory, a project cache, a checkout) moved into the scratch tree."""
        parts = value.split('/')
        checkout = len(parts) == 4 and parts[:2] == ['', 'home'] and parts[3] == 'project'
        if checkout or any(value == ROOTS[name] or value.startswith(ROOTS[name] + '/') for name in PLACED):
            return self.base + value
        return value

    def outside(self, word):
        return word.replace(self.base, '')


def plan_case(scratch, case):
    host, passing, name, values = split_broker(case['broker'])
    action = registry()[name]
    plan = action.plan(action.check([scratch.inside(value) for value in values]), scratch.policy, host)
    return plan, passing


def resolved(scratch, plan, argv, index):
    """The word at `index` of the planned argv, with the compose file or project directory named as sudo named it."""
    word = argv[index]
    before = argv[index - 1] if index else None
    cwd = os.readlink(f'/proc/self/fd/{plan.cwd}') if plan.cwd is not None else None
    if cwd is not None and before == '--project-directory' and word == '.':
        return scratch.outside(cwd)
    if cwd is not None and before == '-f' and word == './docker-compose.yml':
        return scratch.outside(cwd + word[1:])
    return scratch.outside(word)


def resolved_argv(scratch, plan):
    argv = support.as_sudo_named(plan)
    return [resolved(scratch, plan, argv, index) for index in range(len(argv))]


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
            plan, _ = plan_case(scratch, case)
            cwd = scratch.outside(os.readlink(f'/proc/self/fd/{plan.cwd}')) if plan.cwd is not None else '-'
            broker = [shlex.quote(scratch.outside(word)) for word in (plan.argv or [])]
            sudo = '-' if 'sudo' not in case else markdown_cell(map(shlex.quote, case['sudo']))
            rows.append(f"| {case['name']} | {'yes' if case['host'] else ''} | {sudo} | "
                        f"{markdown_cell(broker)} | `{cwd}` |")
            close(plan)
        finally:
            scratch.close()
    return '\n'.join(rows) + '\n'


if __name__ == '__main__':
    sys.stdout.write(table())
