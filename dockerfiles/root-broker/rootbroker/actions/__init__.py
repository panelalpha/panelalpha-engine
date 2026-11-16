"""The fixed set of actions the broker runs.

An action is a list of checked parameters and an argv builder. An action run as an account becomes it before the
path walk, so the walk sees no more than the account could.
"""

import copy
import importlib
import os
import posixpath
import re
import stat
from dataclasses import dataclass, field

from .. import accounts, composefiles, composepolicy, paths
from ..output import Rewriter
from ..refused import Refused

ACCOUNT = 'account'
USER = 'user'
IDENTITY_VALUES = {ACCOUNT: 2, USER: 1}

ROOTS = {
    'homes': '/home',
    'engine': '/opt/panelalpha/shared-hosting',
    'engine-log': '/opt/panelalpha/log',
    'letsencrypt': '/etc/letsencrypt',
    'core-storage': '/var/www/html/storage',
    'core-sources': '/var/www/html/resources/sources',
    'coverage': '/var/www/tests/coverage',
    'archive-stage': '/var/lib/panelalpha/archive-stage',
    'unzip-stage': '/home/.panelalpha-unzip-stage',
    'backup': '/var/tmp/panelalpha-backup',
    'exchange': '/var/lib/panelalpha/exchange',
    'project-caches': '/var/cache/panelalpha/projects',
    'legacy-js-caches': '/var/cache/panelalpha/js',
    'legacy-composer-caches': '/var/cache/pa-composer',
    'panelalpha': '/opt/panelalpha',
}

TRUSTED = ('engine-log', 'letsencrypt')

HOST = ('homes', 'backup')

ENGINE_WRITES = (
    'users', 'webserver-config', 'webserver-logs', 'data/webserver/acme-challenges', 'data/lscache', 'data/build-cache',
    'config/exim', 'config/modsecurity', 'config/sftp', 'crt',
)
ENGINE_WRITTEN_FILES = re.compile(r'config/tenant-api-access(?:\.tmp-[0-9a-f]+)?')

OWNERS = frozenset({0, 33})
SCRIPT_OWNERS = frozenset({0})
ENGINE_ROOTS = ('engine', 'account-engine')
ENGINE_OWNERS = frozenset({0})

HOST_ROOT = '/proc/1/root'

AREAS = {
    'files': ('files',),
    'account': ('account',),
    'account-docker': ('accountdocker', 'accountrun', 'accountstorage'),
    'account-container': ('accountcontainer',),
    'account-compose': ('accountcompose',),
    'docker': ('docker',),
    'compose': ('compose',),
    'host': ('host',),
    'build-cache': ('buildcache',),
    'scripts': ('rootscripts',),
    'lines': ('lines',),
    'users': ('users',),
    'network': ('network',),
    'firewall': ('firewall',),
    'probes': ('probes',),
    'backup-store': ('backupstore',),
    'webserver': ('webserver',),
}


@dataclass(frozen=True)
class Param:
    check: object
    mode: str | None = None
    roots: tuple = ()
    many: bool = False
    trim: bool = False
    writes: bool = False
    creates: bool = False

    def is_path(self):
        return self.mode is not None


@dataclass(frozen=True, kw_only=True)
class Declared:
    """What the server, the audit and the docs read of an action of either kind, with their defaults."""

    passes: tuple = ()
    secret: tuple = ()
    terminal: bool = False
    host_only: bool = False
    namespaces: tuple | None = None
    native: bool = False

    def audited(self, given):
        return ['<secret>' if index in self.secret else value for index, value in enumerate(given)]


@dataclass(frozen=True)
class Action(Declared):
    params: tuple
    build: object
    rewrite: tuple | None = None
    use_cwd: bool = True
    identity: str | None = None
    verify: object = None
    with_values: bool = False
    linkless: tuple | None = None

    def check(self, values):
        fixed = [param for param in self.params if not param.many]
        variadic = self.params[-1].many if self.params else False
        if len(values) < len(fixed) or (not variadic and len(values) != len(fixed)):
            raise Refused(f'{len(values)} values given, {len(fixed)}{" or more" if variadic else ""} expected')
        checked = []
        for index, param in enumerate(self.params):
            taken = values[index:] if param.many else [values[index]]
            checked.append([param.check(value) for value in taken])
        return checked

    def plan(self, checked, policy, host=False):
        if self.host_only and not host:
            raise Refused('this action runs only in the host\'s namespaces')
        count = IDENTITY_VALUES.get(self.identity, 0)
        params, rest = self.params[count:], checked[count:]
        env = None
        if self.identity is not None:
            policy, env = self.become(policy, [group[0] for group in checked[:count]], params, rest)
        if self.linkless is not None:
            self.refuse_links_into_engine(params, rest, policy, host)
        placement = paths.Placement(self.use_cwd)
        placed = []
        for param, taken in zip(params, rest):
            placed.append([self.place(placement, param, value, policy, host) for value in taken])
        arguments = unwrapped(params, placed)
        if self.native:
            return Plan(None, None, native=self.build(policy, *arguments))
        argv = self.build(unwrapped(params, rest), *arguments) if self.with_values else self.build(*arguments)
        return Plan(argv, placement.cwd, placement.fds, self.rewriter(params, rest, placed), environment=env)

    def refuse_links_into_engine(self, params, rest, policy, host):
        """A link moved below the engine's directory after a request checked a bind source there would be followed."""
        source, target = self.linkless
        roots = policy.roots_for(params[source].roots, host)
        if policy.nearest_root(rest[target][0]) in ENGINE_ROOTS and policy.nearest_root(rest[source][0], roots):
            policy.check_no_links(rest[source][0], roots)

    def become(self, policy, ids, params, rest):
        account = policy.account(self.identity, ids)
        if self.verify is not None:
            self.verify(account, *unwrapped(params, rest))
        policy = policy.for_account(account)
        policy.become(account, self.identity == USER)
        return policy, account.sudo_environment() if self.identity == USER else None

    def place(self, placement, param, value, policy, host):
        if not param.is_path():
            return value
        path = value.rstrip('/') if param.trim else value
        if param.writes or param.creates:
            policy.check_writable(path, param.creates)
        return paths.place(placement, path, param.mode, policy.roots_for(param.roots, host), policy.trusted())

    def rewriter(self, params, checked, placed):
        if self.rewrite is None:
            return None
        separator, index, after_tab = self.rewrite
        original = checked[index][0].rstrip('/') if params[index].trim else checked[index][0]
        operand = placed[index][0]
        if not operand.startswith('./'):
            return None
        prefix = original[:len(original) - len(operand) + 2]
        return Rewriter(separator, os.fsencode(prefix), after_tab)


def unwrapped(params, groups):
    return [group if param.many else group[0] for param, group in zip(params, groups)]


@dataclass(frozen=True)
class Plan:
    """A command with its directory, descriptors and environment, or the broker's own work; `passed` and `terminal`
    come with the request."""

    argv: list | None
    cwd: int | None
    fds: list = field(default_factory=list)
    rewrite: Rewriter | None = None
    environment: dict | None = None
    passed: dict = field(default_factory=dict)
    native: object = None
    detach: bool = False
    terminal: tuple | None = None


class Policy:
    """Roots, account lookup, compose-file owners and renderer, and the host's filesystem root, replaceable in tests."""

    def __init__(self, roots=None, trusted=TRUSTED, host=HOST, accounts_db=None, become=accounts.become, owners=OWNERS,
                 host_root=HOST_ROOT, script_owners=SCRIPT_OWNERS, engine_owners=ENGINE_OWNERS,
                 render=composepolicy.render):
        self.roots = dict(ROOTS if roots is None else roots)
        self.trusted_names = trusted
        self.host_names = host
        self.accounts = accounts_db or accounts.Accounts(homes=self.roots.get('homes', ROOTS['homes']))
        self.become = become
        self.owners = frozenset(owners)
        self.script_owners = frozenset(script_owners)
        self.engine_owners = frozenset(engine_owners)
        self.render = render
        self.host_root = host_root

    def roots_for(self, names, host):
        return [self.roots[name] for name in names if name in self.roots and (not host or name in self.host_names)]

    def trusted(self):
        return [self.roots[name] for name in self.trusted_names if name in self.roots]

    def check_writable(self, path, creating=False):
        """Below the engine's directory only the parts the engine writes, never its scripts, compose files or sources.
        A directory made on the way to such a part is allowed too."""
        if 'engine' not in self.roots or self.nearest_root(path) != 'engine':
            return
        rest = paths.below(self.roots['engine'], path)
        for area in (area.split('/') for area in ENGINE_WRITES):
            if rest[:len(area)] == area or (creating and rest and area[:len(rest)] == rest):
                return
        if not ENGINE_WRITTEN_FILES.fullmatch('/'.join(rest)):
            raise Refused(f"'{path}' is not in a part of the engine's directory the engine writes")

    def check_no_links(self, path, roots):
        """`path` is no symlink and, as a directory, holds none."""
        location = paths.locate(path.rstrip('/'), roots, trusted_roots=self.trusted())
        if location.parent is None:
            return
        try:
            try:
                mode = os.lstat(location.name, dir_fd=location.parent).st_mode
            except FileNotFoundError:
                return
            if stat.S_ISLNK(mode):
                raise Refused(f"'{path}' is a symlink")
            if stat.S_ISDIR(mode):
                for _, directories, names, fd in os.fwalk(location.name, dir_fd=location.parent):
                    if any(stat.S_ISLNK(os.lstat(name, dir_fd=fd).st_mode) for name in [*directories, *names]):
                        raise Refused(f"'{path}' holds a symlink")
        finally:
            os.close(location.parent)

    def nearest_root(self, path, among=None):
        holding = [name for name, root in self.roots.items()
                   if paths.below(root, path) is not None and (among is None or root in among)]
        return max(holding, key=lambda name: len(paths.components(self.roots[name])), default=None)

    def account(self, identity, ids):
        return self.accounts.by_ids(*ids) if identity == ACCOUNT else self.accounts.by_name(ids[0])

    @property
    def accounts_directory(self):
        """The engine's directory of the accounts' compose projects."""
        return posixpath.join(self.roots['engine'], composefiles.ACCOUNTS)

    def for_account(self, account):
        own = {'home': account.home}
        if 'engine' in self.roots:
            own['account-engine'] = posixpath.join(self.accounts_directory, account.name)
        policy = copy.copy(self)
        policy.roots = {**self.roots, **own}
        return policy


def registry():
    """Every area's actions under `area.action`."""
    return {
        f'{area}.{name}': action
        for area, modules in AREAS.items()
        for module in modules
        for name, action in importlib.import_module(f'{__name__}.{module}').ACTIONS.items()
    }
