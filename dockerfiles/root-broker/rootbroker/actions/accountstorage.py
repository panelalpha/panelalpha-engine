"""`account-docker` actions on the account daemon's storage: volumes, the prunes, and the build cache's size.

The prunes are the Engine API requests `docker system prune -af [--volumes]`, `image prune -af` and
`builder prune -af` make, in the CLI's order.
"""

import json
import urllib.parse

from .. import cli, replies, values
from .accountdocker import asking, printed_json
from .sequence import flag, nothing, reads, rest

ANONYMOUS = 'com.docker.volume.anonymous'
PRUNE_SECONDS = 600


def filters(**given):
    return urllib.parse.urlencode({'filters': json.dumps({name: list(wanted) for name, wanted in given.items()})})


def create_volume(daemon, output, name):
    """Creates `name`, or answers with the volume when it is there already, as `docker volume create` does."""
    created = replies.volume(daemon.json('POST', '/volumes/create', missing=(), body={'Name': name}))
    return cli.printed(output, [values.name(created.get('Name', name))])


def volumes(daemon, output, names):
    """Each volume's checked inspect, null for one the daemon does not have."""
    return printed_json(output, daemon.volumes.each(names, replies.volume))


def list_volumes(daemon, output):
    """`docker volume ls`: every volume of the account's daemon."""
    return printed_json(output, replies.volumes(daemon.json('GET', '/volumes', missing=())).get('Volumes') or [])


def pruned(daemon, path):
    """Bytes one prune request freed."""
    reply = replies.pruned(daemon.json('POST', path, missing=(), seconds=PRUNE_SECONDS) or {})
    return reply.get('SpaceReclaimed') or 0


def reclaimed(output, freed):
    return cli.printed(output, [f'Total reclaimed space: {freed}B'])


def prune_anonymous_volumes(daemon, output):
    """Removes the volumes no container uses and no name was given, and says how much went."""
    return reclaimed(output, pruned(daemon, '/volumes/prune?' + filters(label=[ANONYMOUS])))


def prune_images(daemon, output):
    """`docker image prune -af`: every image no container uses."""
    return reclaimed(output, pruned(daemon, '/images/prune?' + filters(dangling=['false'])))


def prune_build_cache(daemon, output):
    """`docker builder prune -af`, which `docker buildx prune -af` repeats on the same daemon."""
    return reclaimed(output, pruned(daemon, '/build/prune?all=1'))


def prune_system(daemon, output, with_volumes):
    """`docker system prune -af [--volumes]`: stopped containers, unused networks, the anonymous volumes when asked,
    every unused image and the whole build cache."""
    paths = ['/containers/prune', '/networks/prune']
    if with_volumes:
        paths.append('/volumes/prune')
    paths += ['/images/prune?' + filters(dangling=['false']), '/build/prune?all=1']
    return reclaimed(output, sum(pruned(daemon, path) for path in paths))


def build_cache_bytes(daemon, output):
    """What `docker buildx du` totals: the size of every build cache record."""
    reply = replies.build_cache(daemon.json('GET', '/system/df?type=build-cache', missing=()) or {})
    return cli.printed(output, [sum(record.get('Size', 0) for record in reply.get('BuildCache') or [])])


ACTIONS = {
    'createVolume': asking(reads(values.name), create_volume),
    'volumes': asking(reads(rest(values.name)), volumes),
    'listVolumes': asking(nothing, list_volumes),
    'pruneAnonymousVolumes': asking(nothing, prune_anonymous_volumes),
    'pruneImages': asking(nothing, prune_images),
    'pruneBuildCache': asking(nothing, prune_build_cache),
    'pruneSystem': asking(reads(flag('volumes')), prune_system),
    'buildCacheBytes': asking(nothing, build_cache_bytes),
}
