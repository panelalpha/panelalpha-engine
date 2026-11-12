"""The `build-cache` area: one action per App\\System\\Privileged\\RootBuildCache method, under the same name.

The containers run a repository's Dockerfile, so every bind-mount source must be one build's work directory below
the build-cache root, walked right before the run. BuildKit gets only BuildCacheBuilder's own options.
"""

import re

from .. import dockerapi, paths, values
from ..refused import Refused
from . import hostapi, hostruns
from .docker import build_network, mount_pairs
from .rootscripts import BUILD_CACHE_RUN, BUILD_CACHE_WORK, HOST_CACHE_REGISTRY, PUBLISH_IMAGE
from .sequence import reads, rest

BUILDKIT_IMAGE = 'moby/buildkit:v0.34.1'
RAILPACK_PLAN = 'railpack-plan-tmp.json'
TAG = re.compile(r'[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}')
STORE = re.compile(r'base[0-9]+')
MOUNTS = {
    '/src': re.compile(r'src'),
    '/plan': re.compile(r'plan'),
    '/out': re.compile(r'out-[0-9a-f]{8}'),
    '/oci': re.compile(r'layout-[0-9a-f]{8}'),
    'layouts': re.compile(r'layout-[0-9a-f]{8}'),
}
BUILDCTL_HEAD = ['build', '--progress=plain', '--frontend', 'dockerfile.v0']
BUILDCTL_TAIL = ['--export-cache', 'type=local,dest=/out,mode=max,oci-mediatypes=true,image-manifest=true']
BUILD_ARG = re.compile(r'build-arg:[^=\x00]+=[^\x00]*')
LAYOUT_CONTEXT = re.compile(r'context:([^=\x00]+)=oci-layout://(base[0-9]+):([^@\x00]+)@([^\x00]+)')


memory = values.matching(values.MEMORY, 'memory limit')
tag = values.matching(TAG, 'tag')
read_sandbox = reads(build_network, memory, values.positive)
registry = values.one_of((HOST_CACHE_REGISTRY,), 'registry')


class Work:
    """The one build work directory every mount of a run must come from."""

    def __init__(self, context):
        self.context = context
        self.directory = None

    def source(self, target, source):
        rest = paths.below(self.context.policy.roots['engine'], source)
        shape = MOUNTS['layouts'] if target.startswith('/layouts/') else MOUNTS.get(target)
        if target.startswith('/layouts/') and not STORE.fullmatch(target.removeprefix('/layouts/')):
            raise Refused(f"'{target}' is not a layout a build reads")
        fits = (rest is not None and len(rest) == 4 and rest[:2] == list(BUILD_CACHE_WORK)
                and BUILD_CACHE_RUN.fullmatch(rest[2]) and shape is not None and shape.fullmatch(rest[3]))
        if not fits or self.directory not in (None, rest[2]):
            raise Refused(f"'{source}' is not this build's directory for {target}")
        self.directory = rest[2]
        return self.context.mount_source(source, ('engine',))

    def mounts(self, pairs, suffix):
        return [self.source(target, source) + ':' + target + suffix for target, source in pairs]


def directory_operand(value, allowed):
    path = values.path(value)
    if path not in allowed and not path.startswith('/src/'):
        values.refuse('build directory', value)
    return path


def buildctl(arguments, stores):
    """Accepts only the build BuildCacheBuilder::buildctl() writes."""
    head, tail = arguments[:len(BUILDCTL_HEAD)], arguments[len(arguments) - len(BUILDCTL_TAIL):]
    middle = arguments[len(BUILDCTL_HEAD):len(arguments) - len(BUILDCTL_TAIL)]
    if head != BUILDCTL_HEAD or tail != BUILDCTL_TAIL or len(middle) < 4 or len(middle) % 2:
        raise Refused('not a build the engine runs')
    if middle[0] != '--local' or not middle[1].startswith('context=') or middle[2] != '--local' \
            or not middle[3].startswith('dockerfile='):
        raise Refused('not a build the engine runs')
    directory_operand(middle[1].removeprefix('context='), ('/src',))
    directory_operand(middle[3].removeprefix('dockerfile='), ('/src', '/plan'))
    for flag, value in zip(middle[4::2], middle[5::2]):
        option(flag, value, stores)
    return arguments


def option(flag, value, stores):
    if flag == '--oci-layout':
        store, _, path = value.partition('=')
        if store not in stores or path != '/layouts/' + store:
            values.refuse('OCI layout', value)
    elif flag != '--opt' or not frontend_option(value, stores):
        values.refuse('build option', f'{flag} {value}')


def frontend_option(value, stores):
    if value.startswith(('filename=', 'target=')):
        return '\0' not in value
    if BUILD_ARG.fullmatch(value):
        return True
    layout = LAYOUT_CONTEXT.fullmatch(value)
    if layout is None or layout.group(2) not in stores:
        return False
    values.image(layout.group(1))
    tag(layout.group(3))
    return re.fullmatch(values.IMAGE_DIGEST, layout.group(4)) is not None


def build_kit(context, name, limit, pids, read_only, writable, arguments):
    work = Work(context)
    if any(target not in ('/src', '/plan') and not target.startswith('/layouts/') for target, _ in read_only):
        raise Refused('a build reads only its checkout, its plan and its layouts')
    if [target for target, _ in writable] != ['/out']:
        raise Refused('a build writes only its export')
    stores = {target.removeprefix('/layouts/') for target, _ in read_only if target.startswith('/layouts/')}
    return (hostruns.Spec(
        image=BUILDKIT_IMAGE, cmd=tuple(buildctl(arguments, stores)), entrypoint=('buildctl-daemonless.sh',),
        binds=(*work.mounts(read_only, ':ro'), *work.mounts(writable, '')), network=name, runtime=dockerapi.SYSBOX_RUNTIME,
        memory=hostruns.memory_bytes(limit), pids_limit=int(pids),
    ),)


def railpack_prepare(context, name, limit, pids, image, source, plan):
    work = Work(context)
    return (hostruns.Spec(
        image=image, cmd=('prepare', '.', '--plan-out', '/plan/' + RAILPACK_PLAN), entrypoint=('railpack',),
        workdir='/src', binds=(*work.mounts([('/src', source)], ':ro'), *work.mounts([('/plan', plan)], '')),
        network=name, runtime=dockerapi.SYSBOX_RUNTIME, memory=hostruns.memory_bytes(limit), pids_limit=int(pids),
    ),)


def copy_image_to_layout(context, address, ref, directory, name):
    if not ref.startswith(address + '/'):
        values.refuse('image in the cache registry', ref)
    return (hostruns.Spec(
        image=PUBLISH_IMAGE, user='0:0', network='host',
        cmd=('--host', f'reg={address},tls=disabled', 'image', 'copy', ref, f'ocidir:///oci:{name}'),
        binds=Work(context).mounts([('/oci', directory)], ''),
    ),)


ACTIONS = {
    'buildKit': hostapi.asking(
        reads(read_sandbox, mount_pairs, mount_pairs, rest(values.word)), hostruns.run_container, build_kit,
    ),
    'railpackPrepare': hostapi.asking(
        reads(read_sandbox, values.image, values.mount_path, values.mount_path),
        hostruns.run_container, railpack_prepare,
    ),
    'copyImageToLayout': hostapi.asking(
        reads(registry, values.image, values.mount_path, tag), hostruns.run_container, copy_image_to_layout,
    ),
}
