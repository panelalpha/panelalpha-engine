"""Prints ACTIONS.md from the broker's action table; test_actions_doc.py fails while the file differs.

    python3 actionsdoc.py > ../ACTIONS.md

A command action shows the command one of its cases pins, a native one its work function's docstring.
"""

import inspect
import json
import os
import re
import shlex
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PRIVILEGED = os.path.join(HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged')
sys.path.insert(0, os.path.join(HERE, '..'))

from rootbroker.actions import Action, accountdocker, hostapi, registry  # noqa: E402

AREAS = {
    'files': ('RootFiles', "Files and directories on the engine's own paths and the host's, each walked without following "
              "a link, below a root the action names."),
    'account': ('AccountCommands, AccountFiles, AccountFileManager', "Work in an account's home, run as that account "
                "once its uid and gid are checked against the host's passwd."),
    'account-docker': ('RootAccountDocker, RootAccountDockerLines', "An account's own dockerd, through the Engine API "
                       "on its DinD socket, opened without links below the account container's root. Replies are the "
                       "tenant's, so each is read up to a size and cut down to checked fields."),
    'account-container': ('RootAccountContainer', "The account's own container on the host's daemon: an exec through "
                          "the host's Engine API, and its dockerd's config reload."),
    'account-compose': ('RootAccountCompose', "`docker compose` or `docker buildx build` run as the account against its "
                        "own daemon, in a sandbox that sees only the account's home and a relay to that daemon."),
    'docker': ('RootDocker', "The host's own daemon through its Engine API: containers, networks, images, builds, the "
               "registry containers and the host build containers."),
    'compose': ('RootCompose, RootEngineCompose, RootEngineComposeLines, RootEngineServices', "Compose projects on the "
                "host's daemon: `up`, `down`, `build` and `pull` run compose; every lookup and every command in an "
                "engine service goes through the host's Engine API."),
    'host': ('RootHost', "Work in the host's own namespaces: the engine's host scripts, caches, the stuck-DinD "
             "recovery, the tenant network's firewall, project statistics."),
    'build-cache': ('RootBuildCache', "The build cache's BuildKit, Railpack and layout runs, containers on the host's "
                    "daemon."),
    'scripts': ('RootScripts', "The root steps of core's image pipelines that are not lines."),
    'lines': ('RootDockerLines, RootComposeLines, RootFileLines', "One request a core pipeline pastes in as a line, so "
              "only that line runs as root."),
    'users': ('RootUsers', "The host's users and groups."),
    'network': ('RootNetwork', "The host's addresses and routes."),
    'firewall': ('RootFirewall', "The host's firewall."),
    'probes': ('RootProbes', "Read-only facts of the host."),
    'backup-store': ('RootBackupStore', "The backup store's files."),
    'webserver': ('RootWebserver', "The web server's reload, run once the request is answered."),
}
RENAMED = {
    'compose.engineUpWithoutDependencies': 'RootEngineCompose::upWithoutDependencies',
    'compose.engineRunningServices': 'RootEngineServices::runningServices',
    'compose.engineSetEnv': 'RootEngineCompose::setEnv',
    'compose.engineEnvValues': 'RootEngineCompose::envValues',
    'lines.composePull': 'RootComposeLines::pull',
    'lines.composeBuild': 'RootComposeLines::build',
}
CASES = ('account-cases.json', 'docker-cases.json', 'host-cases.json', 'rootfiles-cases.json', 'scripts-cases.json',
         'system-cases.json')
ENGINE_API = {
    hostapi.FAILURES: "Engine API, the host's daemon",
    accountdocker.FAILURES: "Engine API, the account's daemon",
}
OPTIONS = ('--host', '--pty')
COMMAND_LIMIT = 160


def action_name(broker):
    words = broker.split(' ') if isinstance(broker, str) else broker
    at = 1
    while at < len(words) and words[at] in OPTIONS + ('--env',):
        at += 2 if words[at] == '--env' else 1
    return words[at]


def commands():
    """The command one case of each action pins, in the case files' order."""
    found = {}
    for name in CASES:
        with open(os.path.join(HERE, name)) as f:
            for case in json.load(f):
                if 'sudo' in case:
                    found.setdefault(action_name(case['broker']), case['sudo'])
    return found


def unprivileged(sudo):
    """The command itself: without sudo, the host's nsenter, or the account's setpriv, which the Runs column names."""
    for prefix in (['sudo', 'nsenter', '--target', '1', '--all'], ['sudo', 'nsenter', '--target', '1', '--net'], ['sudo']):
        if sudo[:len(prefix)] == prefix:
            sudo = sudo[len(prefix):]
            break
    if sudo[:1] == ['setpriv'] and '--clear-groups' in sudo:
        sudo = sudo[sudo.index('--clear-groups') + 1:]
        sudo = sudo[1:] if sudo[:1] == ['--'] else sudo
    return sudo


def shortened(text):
    return text if len(text) <= COMMAND_LIMIT else text[:COMMAND_LIMIT].rstrip() + ' …'


def held(function, name):
    """What a builder holds under `name`: its attribute of that name, else what it closed over as one, else None."""
    if getattr(function, name, None) is not None:
        return getattr(function, name)
    names = getattr(getattr(function, '__code__', None), 'co_freevars', ())
    cells = dict(zip(names, getattr(function, '__closure__', None) or ()))
    return cells[name].cell_contents if name in cells else None


def native_work(action):
    """The function a native action calls when it runs: the work it holds, followed through wrappers."""
    function = action.build
    while callable(held(function, 'work')):
        function = held(function, 'work')
    return function


def enclosing(function):
    """The module-level function a nested one was made in, or None."""
    outer, _, rest = function.__qualname__.partition('.<locals>.')
    return getattr(sys.modules.get(function.__module__), outer, None) if rest else None


def summary(function):
    doc = inspect.getdoc(function) or inspect.getdoc(enclosing(function)) or ''
    text = ' '.join(doc.split('\n\n')[0].split())
    service = held(function, 'service')
    return f'{text} Runs in `{service}`.' if isinstance(service, str) and text else text


def how(name, action):
    if not action.native:
        return 'command'
    if name.startswith('account-container.'):
        return "Engine API, the host's daemon, the account's container"
    if name.startswith('account-compose.'):
        return 'compose in the sandbox'
    if name == 'docker.inspectManifest':
        return "the image's registry, its v2 API"
    return ENGINE_API.get(getattr(action.build, 'failures', None), 'broker')


def runs_as(action):
    if isinstance(action, Action):
        return {'account': 'the account', 'user': 'the account'}.get(action.identity, '') + \
            (', host' if action.host_only else '')
    if action.host_only:
        return 'host'
    return '`--host` too' if action.on_host else ''


def cell(text):
    return text.replace('|', '\\|').replace('\n', '\\n').replace('\r', '\\r').replace('\t', '\\t')


def described(name, action, pinned):
    if action.native:
        return cell(summary(native_work(action))) or '—'
    if name in pinned:
        return '`' + cell(shortened(shlex.join(unprivileged(pinned[name])))) + '`'
    return '—'


def declares(gateway, action):
    try:
        with open(os.path.join(PRIVILEGED, gateway + '.php')) as f:
            return re.search(rf'public function {re.escape(action)}\(', f.read()) is not None
    except OSError:
        return False


def method(name):
    """The core method that asks for the action: the one gateway of its area that declares it."""
    if name in RENAMED:
        return f'`{RENAMED[name]}`'
    area, _, action = name.partition('.')
    gateways = AREAS[area][0].split(', ')
    found = [gateway for gateway in gateways if declares(gateway, action)] or gateways[:1]
    return ', '.join(f'`{gateway}::{action}`' for gateway in found)


def document():
    table = registry()
    pinned = commands()
    lines = [
        '# Root broker actions',
        '',
        'Generated by `tests/actionsdoc.py` from the broker\'s own action table; `tests/test_actions_doc.py` keeps it',
        'current. See [README.md](README.md) for how a request is checked and run.',
        '',
        f'{len(table)} actions in {len(AREAS)} areas. **How** is `command` for an action that runs its own command (the',
        'example is the command one of its cases pins), or the API it answers through itself (the text is its work',
        "function's docstring). **Runs** says when it runs in the host's namespaces (`host`), may be asked to",
        '(`--host` too), or runs as the account.',
        '',
        'Core asks with `pa-root`. Root on the host can run any of them in the broker\'s container with',
        '`docker compose exec -T root-broker pa-broker [--host] AREA.ACTION -- VALUE...`, checked and audited the same',
        'way.',
    ]
    for area, (gateways, about) in AREAS.items():
        names = sorted(name for name in table if name.split('.', 1)[0] == area)
        lines += ['', f'## `{area}` ({len(names)})', '', f'{about} Core asks through {", ".join(f"`{g}`" for g in gateways.split(", "))}.',
                  '', '| Action | Core method | How | Runs | What it does |', '|---|---|---|---|---|']
        for name in names:
            action = table[name]
            lines.append(f'| `{name}` | {method(name)} | {how(name, action)} | {runs_as(action)} | '
                         f'{described(name, action, pinned)} |')
    missing = sorted(name for name in table if name.split('.', 1)[0] not in AREAS)
    if missing:
        raise SystemExit(f'areas without a description: {missing}')
    return '\n'.join(lines) + '\n'


if __name__ == '__main__':
    sys.stdout.write(document())
