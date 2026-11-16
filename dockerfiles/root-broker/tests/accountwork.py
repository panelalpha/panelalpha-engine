"""Plans each account-cases.json `broker` request on a scratch tree and compares it word for word with its `sudo` argv.

Run as a script, it prints the comparison as a Markdown table.
"""

import json
import os
import re
import shlex
import shutil
import sys
import tempfile

import casework
import support
from rootbroker.accounts import Accounts
from rootbroker.actions import ROOTS, Policy, registry, scripts

CASES = os.path.join(support.HERE, 'account-cases.json')
HOST_PATH = re.compile(r'(^|=|^[FE]:)(/(?:home|opt|var|tmp)(?=/|$))')
SETPRIV = 7

SHAPES = {
    'removeIfExists': 'ff', 'changeMode': 'd', 'changeModeRecursively': 'd', 'moveOverwriting': 'f-',
    'replaceWithPreservedCopy': 'ff', 'read': 'f', 'listAllNames': 'd', 'isSymlink': 'f', 'isRealDirectory': 'd',
    'diskUsageInMegabytes': 'd', 'listEntryTypes': 'd', 'nitroServerEntries': 'd', 'listZipNames': 'f',
    'listZipEntries': 'f', 'readZipMember': 'f', 'copyReplacingAsAccount': 'ff', 'extractZip': 'fd',
    'extractTar': 'fd', 'normaliseModes': 'd', 'readRegularFile': 'f', 'extractTarFromStdin': 'd', 'mergeTree': 'dd',
    'lastLinesOfLog': 'f', 'ensureLogFile': 'f', 'selfSignedCertificate': 'ff', 'fileStream': 'd',
}

NATIVE = ('renameInHome', 'readInHome')


def load():
    with open(CASES) as f:
        return json.load(f)


def unprivileged(case):
    sudo = case['sudo']
    if sudo[1] == 'setpriv':
        rest = sudo[SETPRIV:]
        return rest[1:] if case['class'] == 'AccountFiles' else rest
    if sudo[1] == 'nsenter':
        return sudo[sudo.index('--') + 1:]
    return sudo[1:]


class Scratch(casework.Scratch):
    """casework's scratch tree, with a passwd naming `u` (1004:1005) and `v`, homes in the scratch /home."""

    def __init__(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-account-'))
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        passwd = os.path.join(self.base, 'passwd')
        with open(passwd, 'w') as f:
            f.write('\n'.join(support.passwd_lines(self.roots['homes'])) + '\n')
        self.policy = Policy(self.roots, accounts_db=Accounts(passwd, self.roots['homes']), become=support.stay_as_we_are)

    def word_inside(self, word):
        return HOST_PATH.sub(lambda m: m.group(1) + self.base + m.group(2), word)

    def word_outside(self, word):
        return word.replace(self.base, '')


def plan_case(scratch, case):
    host, name, values = casework.split_broker(case['broker'])
    action = registry()[name]
    shapes = iter(SHAPES.get(case['method'], ''))
    inside = []
    for param, taken in zip(action.params, casework.action_values(action, values)):
        for value in taken:
            if param.is_path() and param.mode not in ('as-given', 'root-itself', 'resolve', 'tagged'):
                scratch.lay_out(value, next(shapes, '-'))
            inside.append(scratch.word_inside(value))
    return action.plan(action.check(inside), scratch.policy, host)


def same_word(scratch, plan, want, got):
    if want == got or scratch.word_outside(got) == want:
        return True
    head, equals, rest = want.partition('=')
    if equals and got.startswith(head + '='):
        return same_word(scratch, plan, rest, got[len(head) + 1:])
    return casework.same_path(want, casework.resolved(scratch, plan, got))


SCRIPT_NAMES = {value: name for name, value in vars(scripts).items() if name.isupper()}


def shown(word):
    return '$' + SCRIPT_NAMES[word] if word in SCRIPT_NAMES else shlex.quote(word)


def describe(scratch, plan, word):
    if word in SCRIPT_NAMES:
        return shown(word)
    if scratch.base in word:
        return shlex.quote(scratch.word_outside(word))
    target = casework.resolved(scratch, plan, word)
    return shlex.quote(word) if target == word else f'{shlex.quote(word)} [= {target}]'


def table():
    rows = ['| class | method | sudo argv today | argv the broker runs | broker working directory |', '|---|---|---|---|---|']
    scripts_in_cells = '`$NAME` is the script of that name (the gateway constant, and its copy in rootbroker.actions.scripts).'
    for case in load():
        if case['method'] in NATIVE:
            rows.append(f"| {case['class']} | {case['method']} | {casework.markdown_cell(map(shown, case['sudo']))} | "
                        f"native: the broker's own walk, no command | - |")
            continue
        scratch = Scratch()
        try:
            plan = plan_case(scratch, case)
            cwd = scratch.outside(os.readlink(f'/proc/self/fd/{plan.cwd}')) if plan.cwd is not None else '-'
            broker = [describe(scratch, plan, word) for word in plan.argv]
            rows.append(f"| {case['class']} | {case['method']} | {casework.markdown_cell(map(shown, case['sudo']))} | "
                        f"{casework.markdown_cell(broker)} | `{cwd}` |")
            casework.close(plan)
        finally:
            shutil.rmtree(scratch.base, ignore_errors=True)
    return scripts_in_cells + '\n\n' + '\n'.join(rows) + '\n'


if __name__ == '__main__':
    sys.stdout.write(table())
