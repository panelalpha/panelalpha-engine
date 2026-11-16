"""Plans each rootfiles-cases.json `broker` request on a scratch tree and compares it with its `sudo` argv.

Run as a script, it prints the comparison as a Markdown table.
"""

import json
import os
import shlex
import shutil
import subprocess
import sys
import tempfile

import support
from rootbroker.actions import ROOTS, Policy, registry

CASES = os.path.join(support.HERE, 'rootfiles-cases.json')
HOST_PREFIX = ['sudo', 'nsenter', '--target', '1', '--all']

SHAPES = {
    'remove': 'f', 'removeIfExists': 'ff', 'removeTree': 'dd', 'removeEmptyDirectory': 'd', 'makeDirectory': '-',
    'makeDirectoryWithMode': '-', 'makeDirectories': '-', 'makeDirectoriesWithMode': '-', 'move': 'dd',
    'moveOverwriting': 'f-', 'rename': 'd-', 'copy': 'ff', 'replaceWithPreservedCopy': 'ff', 'copyTree': 'dd',
    'install': 'f-', 'changeMode': 'ff', 'touch': '-', 'read': 'f', 'lastLines': 'f', 'isFile': 'f', 'isSymlink': 'f',
    'modificationTime': 'f', 'permissionBits': 'f', 'existingRealPaths': 'df', 'createArchive': 'd-',
    'extractArchive': 'fd', 'move@host': 'd-',
}


def load():
    with open(CASES) as f:
        return json.load(f)


def split_broker(argv):
    host = argv[1] == '--host'
    rest = argv[2:] if host else argv[1:]
    rest = rest[1:] if rest[0] == '--pty' else rest
    return host, rest[0], rest[2:]


def unprivileged(sudo):
    return sudo[len(HOST_PREFIX):] if sudo[:len(HOST_PREFIX)] == HOST_PREFIX else sudo[1:]


class Scratch:
    """The host's roots laid out below one scratch directory, and the way back from a scratch path to a host path."""

    def __init__(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-cases-'))
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        self.policy = Policy(self.roots)

    def close(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def inside(self, path):
        return self.base + path

    def outside(self, path):
        if path == self.base:
            return '/'
        return path[len(self.base):] if path.startswith(self.base + '/') else path

    def lay_out(self, path, shape):
        full = os.path.normpath(self.inside(path))
        os.makedirs(full if shape == 'd' else os.path.dirname(full), exist_ok=True)
        if shape == 'f' and not os.path.exists(full):
            open(full, 'w').close()


def plan_case(scratch, case):
    host, name, values = split_broker(case['broker'])
    action = registry()[name]
    shapes = iter(SHAPES.get(case['method'] + ('@host' if host else ''), SHAPES.get(case['method'], '')))
    inside = []
    for param, taken in zip(action.params, action_values(action, values)):
        for value in taken:
            if param.is_path():
                scratch.lay_out(value, next(shapes, 'd'))
                inside.append(scratch.inside(value))
            else:
                inside.append(value)
    plan = action.plan(action.check(inside), scratch.policy, host)
    return plan


def action_values(action, values):
    groups = []
    for index, param in enumerate(action.params):
        groups.append(values[index:] if param.many else [values[index]])
    return groups


def resolved(scratch, plan, word):
    cwd = os.readlink(f'/proc/self/fd/{plan.cwd}') if plan.cwd is not None else None
    if word.startswith('./') and cwd is not None:
        return scratch.outside(cwd + word[1:])
    for fd in plan.fds:
        prefix = f'/proc/self/fd/{fd}'
        if word == prefix or word.startswith(prefix + '/'):
            return scratch.outside(os.readlink(prefix) + word[len(prefix):])
    return scratch.outside(word)


def same_path(left, right):
    return os.path.normpath(left) == os.path.normpath(right) and left.endswith('/') == right.endswith('/')


def describe(scratch, plan, word):
    if word.startswith(scratch.base + '/'):
        return shlex.quote(scratch.outside(word))
    target = resolved(scratch, plan, word)
    return shlex.quote(word) if target == word else f'{shlex.quote(word)} [= {target}]'


class Answer:
    def __init__(self):
        self.out, self.err = b'', b''

    def stdout(self, data):
        self.out += data

    def stderr(self, data):
        self.err += data


def native_answer(plan):
    answer = Answer()
    code = plan.native(answer)
    return code, answer.out


def run_inside(scratch, argv):
    result = subprocess.run([scratch.inside(word) if word.startswith('/') else word for word in argv], capture_output=True)
    return result.returncode, result.stdout


def close(plan):
    for fd in [plan.cwd, *plan.fds]:
        if fd is not None:
            try:
                os.close(fd)
            except OSError:
                pass


def markdown_cell(words):
    return '`' + ' '.join(words).replace('|', '\\|') + '`'


def table():
    rows = ['| method | host | sudo argv today | argv the broker runs | broker working directory |', '|---|---|---|---|---|']
    for case in load():
        scratch = Scratch()
        try:
            plan = plan_case(scratch, case)
            cwd = scratch.outside(os.readlink(f'/proc/self/fd/{plan.cwd}')) if plan.cwd is not None else '-'
            broker = [describe(scratch, plan, word) for word in plan.argv]
            rows.append(f"| {case['method']} | {'yes' if case['host'] else ''} | {markdown_cell(map(shlex.quote, case['sudo']))} | "
                        f"{markdown_cell(broker)} | `{cwd}` |")
            close(plan)
        finally:
            scratch.close()
    return '\n'.join(rows) + '\n'


if __name__ == '__main__':
    sys.stdout.write(table())
