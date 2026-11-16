"""Plans each host-cases.json `broker` request on a scratch tree and compares it word for word with its `sudo` argv.

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
from rootbroker.actions import ROOTS, Policy, registry

CASES = os.path.join(support.HERE, 'host-cases.json')
PREFIXES = (['sudo', 'nsenter', '--target', '1', '--all'], ['sudo', 'nsenter', '--target', '1', '--net'], ['sudo'])
PLACED = ('homes', 'engine', 'project-caches', 'legacy-js-caches', 'legacy-composer-caches')


def load():
    with open(CASES) as f:
        return json.load(f)


def unprivileged(sudo):
    for prefix in PREFIXES:
        if sudo[:len(prefix)] == prefix:
            return sudo[len(prefix):], prefix[-1]
    raise AssertionError(f'not a sudo argv: {sudo}')


class Scratch:
    """The host's roots below one scratch directory owned by the test's user, the engine directory among them."""

    def __init__(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-host-'))
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        self.policy = Policy(self.roots, owners={os.getuid()}, script_owners={os.getuid()}, host_root='')
        os.makedirs(self.roots['engine'], mode=0o755)
        for directory in (self.base, *paths_up_to(self.base, self.roots['engine'])):
            os.chmod(directory, 0o755)

    def close(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def inside(self, value):
        if any(value == ROOTS[name] or value.startswith(ROOTS[name] + '/') for name in PLACED):
            return self.base + value
        return value

    def outside(self, word):
        return word.replace(self.base, '')


def paths_up_to(base, path):
    found = []
    while path != base:
        found.append(path)
        path = os.path.dirname(path)
    return found


def plan_case(scratch, case):
    host, passing, name, values = dockerwork.split_broker(case['broker'])
    action = registry()[name]
    plan = action.plan(action.check([scratch.inside(value) for value in values]), scratch.policy, host)
    return plan, action, passing


def sudo_argv(case):
    """What sudo ran for the case, or None for an action the broker answers itself now."""
    return None if 'sudo' not in case else unprivileged(case['sudo'])[0]


def entered(case):
    """The namespaces the case's sudo command entered, or None where the broker runs no command of its own."""
    return None if 'sudo' not in case else unprivileged(case['sudo'])[1]


def resolved_argv(scratch, plan):
    return [scratch.outside(word) for word in plan.argv]


def namespaces(action):
    return 'net' if action.namespaces == ('net',) else 'all' if action.host_only else '-'


def markdown_cell(words):
    return '`' + ' '.join(words).replace('|', '\\|').replace('\n', '\\n').replace('\t', '\\t').replace('\r', '\\r') + '`'


def table():
    rows = ['| case | host namespaces | sudo argv today | pa-root request | argv the broker runs | same |', '|---|---|---|---|---|---|']
    for case in load():
        scratch = Scratch()
        try:
            plan, action, _ = plan_case(scratch, case)
            expected = sudo_argv(case)
            broker = [] if plan.argv is None else resolved_argv(scratch, plan)
            rows.append(f"| {case['name']} | {namespaces(action)} | {markdown_cell(map(shlex.quote, case.get('sudo', [])))} | "
                        f"{markdown_cell(map(shlex.quote, case['broker']))} | {markdown_cell(map(shlex.quote, broker))} | "
                        f"{'-' if expected is None else 'yes' if broker == expected else 'NO'} |")
            dockerwork.close(plan)
        finally:
            scratch.close()
    return '\n'.join(rows) + '\n'


if __name__ == '__main__':
    sys.stdout.write(table())
