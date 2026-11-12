"""The `backup-store` area: one action per App\\System\\Privileged\\RootBackupStore method, under the same name.

Paths are walked below /home, /opt/panelalpha or core's storage. Nothing is removed at a root.
"""

import posixpath
from dataclasses import dataclass

from .. import values
from ..paths import ENTRY, EXISTING, PARENTS
from ..refused import Refused
from . import Action, Param

STORE = ('homes', 'panelalpha', 'engine', 'core-storage')
ROOTS = ('/home', '/opt/panelalpha', '/var/www/html/storage')


@dataclass(frozen=True)
class InCore(Action):
    """A store in core's own directory, never the host's."""

    def plan(self, checked, policy, host=False):
        if host:
            raise Refused('this action does not run in the host\'s namespaces')
        return super().plan(checked, policy, host)


def is_account_home(path, policy):
    if posixpath.dirname(path.rstrip('/')) != posixpath.normpath(policy.accounts.homes):
        return False
    try:
        policy.accounts.by_name(posixpath.basename(path.rstrip('/')))
        return True
    except Refused:
        return False


@dataclass(frozen=True)
class ChownStore(InCore):
    """A store's chown or chmod: never a root the engine keeps (below_root), nor an account's own home directory."""

    def plan(self, checked, policy, host=False):
        if is_account_home(checked[0][0], policy):
            raise Refused('a backup store is an account home, which the account depends on')
        return super().plan(checked, policy, host)


def store_path(value):
    if values.path(value).rstrip('/') in ('', '/home'):
        values.refuse('backup store path', value)
    return value


def below_root(value):
    if store_path(value).rstrip('/') in ROOTS:
        values.refuse('backup store path', value)
    return value


def path(mode, check=store_path, many=False):
    return Param(check, mode, STORE, many=many, writes=True)


ACTIONS = {
    'removeTree': InCore((path(ENTRY, below_root),), lambda d: ['rm', '-rf', '--', d]),
    'removeEmptyDirectories': InCore(
        (path(ENTRY, below_root),), lambda d: ['find', d, '-depth', '-type', 'd', '-empty', '-delete'],
    ),
    'removeEmptyParents': InCore(
        (path(ENTRY, below_root, many=True),), lambda ds: ['rmdir', '--ignore-fail-on-non-empty', '--', *ds],
    ),
    'makeDirectories': InCore((path(PARENTS),), lambda d: ['mkdir', '-p', '--', d]),
    'giveToWebUser': ChownStore((path(EXISTING, below_root),), lambda d: ['chown', 'www-data:www-data', '--', d]),
    'openToWebUser': ChownStore((path(EXISTING, below_root),), lambda d: ['chmod', '0775', '--', d]),
}
