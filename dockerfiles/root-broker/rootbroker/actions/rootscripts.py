"""The `scripts` area: one action per App\\System\\Privileged\\RootScripts method that has a root step of its own.

The image pipelines are not actions. Each of their root commands is a `lines` request.
"""

import re

from .. import paths, values
from ..paths import ENTRY, ROOT_ITSELF
from ..refused import Refused
from . import Action, Param, hostapi, hostruns, scripts
from .builders import script
from .sequence import Command, reads

# RootScripts

REMOVE_STARTING_WITH = r'''rm -f -- "$1"*'''

MAKE_HOME_ENTRY = r'''set -eu
if [ -L "$1" ]; then
    echo "Refusing to use '$1': it is a symlink." >&2
    exit 3
fi
[ -d "$1" ] || mkdir -m "$2" -- "$1"
chown -h "$3" -- "$1"'''

COMPOSE_IMAGE_LINES = (
    r'''for f in %s; do '''
    r'''case "$f" in "$1/$2"/*) continue ;; esac; '''
    r'''[ -f "$f" ] || continue; '''
    r'''grep -hE '^[[:space:]]*image:[[:space:]]*[^[:space:]]' -- "$f"; '''
    r'''done 2>/dev/null || true'''
)

COMPOSE_FILE_GLOB = r'''"$1"/*/project/'''


# ImagePipelines

FILE_NAME = re.compile(r'[A-Za-z0-9_][A-Za-z0-9_.-]*')
PUSH_LOCK = '/run/panelalpha/cache-registry.lock'
DOCKER_STORAGE = 'docker'
PUBLISH_IMAGE = 'ghcr.io/regclient/regctl:v0.11.7'
HOST_CACHE_REGISTRY = '127.0.0.1:5000'
BUILD_CACHE_WORK = ('data', 'build-cache')
BUILD_CACHE_RUN = re.compile(r'[0-9a-f]{12}')
BUILD_CACHE_OUT = re.compile(r'out-[0-9a-f]{8}')


file_name = values.matching(FILE_NAME, 'file name')


def in_cache_registry(kind):
    """A check of an image reference in the host's cache registry, refusing any other as a `kind`."""
    def check(value):
        if not values.image(value).startswith(HOST_CACHE_REGISTRY + '/'):
            values.refuse(kind, value)
        return value
    return check


cache_registry_ref = in_cache_registry('cache registry reference')


def compose_image_lines(homes, excluded, names):
    return script(COMPOSE_IMAGE_LINES % ' '.join(COMPOSE_FILE_GLOB + name for name in names))(homes, excluded)


def make_home_entry(context, entry, mode, owner):
    """An entry directly in the account's own home, made or re-owned for the account. Never `docker`, which holds the
    account's Docker storage and stays root's."""
    account = context.policy.accounts.by_ids(*owner.split(':'))
    parts = paths.components(entry)
    if len(parts) < 2 or parts[:-1] != paths.components(account.home) or parts[-1] in ('.', '..'):
        raise Refused(f"'{entry}' is not an entry directly in the home of {account.name}")
    if parts[-1] == DOCKER_STORAGE:
        raise Refused(f"'{entry}' holds the account's Docker storage")
    placed = paths.place(context.placement, entry, ENTRY, [context.policy.roots['homes']])
    return script(MAKE_HOME_ENTRY)(placed, mode, owner)


def build_cache_layout(context, layout):
    """`<engine>/data/build-cache/<run>/out-<ref hash>`, as BuildCacheProducer makes it."""
    rest = paths.below(context.policy.roots['engine'], values.mount_path(layout))
    if rest is None or len(rest) != 4 or tuple(rest[:2]) != BUILD_CACHE_WORK \
            or not BUILD_CACHE_RUN.fullmatch(rest[2]) or not BUILD_CACHE_OUT.fullmatch(rest[3]):
        raise Refused(f"'{layout}' is not a build cache layout the engine made")
    return context.mount_source(layout, ('engine',))


def publish_build_cache(context, layout, ref):
    """The copy of a build's layout into the writer on the host's loopback, under the shared push lock."""
    return (hostruns.Spec(
        image=PUBLISH_IMAGE, user='0:0', network='host', lock=PUSH_LOCK,
        cmd=('--host', 'reg=' + ref.split('/', 1)[0] + ',tls=disabled', 'image', 'copy', 'ocidir:///oci', ref),
        binds=(build_cache_layout(context, layout) + ':/oci:ro',),
    ),)


ACTIONS = {
    'removeStartingWith': Action((Param(values.path, ENTRY, ('engine',), writes=True),), script(REMOVE_STARTING_WITH)),
    'isRealDirectory': Action((Param(values.path, ENTRY, ('homes',)),), script(scripts.REAL_DIRECTORY, 'bash')),
    'makeHomeEntry': Command(reads(values.path, values.octal_mode, values.owner_ids), make_home_entry),
    'composeImageLines': Action(
        (Param(values.path, ROOT_ITSELF, ('homes',)), Param(values.username), Param(file_name, many=True)),
        compose_image_lines,
    ),
    'publishBuildCache': hostapi.asking(
        reads(values.mount_path, cache_registry_ref), hostruns.run_container, publish_build_cache,
    ),
}
