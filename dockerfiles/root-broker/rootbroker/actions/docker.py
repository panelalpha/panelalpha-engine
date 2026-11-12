"""The `docker` area: one action per App\\System\\Privileged\\RootDocker method, under the same name.

A host build container gets RootDocker's hardening and nothing a request could add. The daemon resolves a mount
source by name, so each is walked without following links right before the run.
"""


from .. import paths, values
from ..refused import Refused
from . import hostapi, hostbuilds, hostcontainers, hostimages, hostruns
from .sequence import constant, flag, group, nothing, reads, rest

LITESPEED_IMAGE = 'litespeedtech/litespeed'
LITESPEED_SERIAL = '/usr/local/lsws/conf/serial.no'
LITESPEED_SERIAL_FILES = ('webserver-config/litespeed/serial.no.tmp', 'webserver-config/openlitespeed/serial.no.tmp')
CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt'
CA_BUNDLES = ('/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/ca-bundle.pem',
              '/etc/ssl/cert.pem')
PROJECT_CACHES = ('node_modules', 'npm', 'pnpm', 'yarn', 'bun', 'composer')


def build_user(value):
    """An account's `uid:gid`, never root, since the build runs a recipe's commands on the host's daemon."""
    parts = values.owner(value).split(':')
    if any(not part.isdigit() or int(part) == 0 for part in parts):
        values.refuse('build user', value)
    return value


def build_network(value):
    """The build network, or '' for Docker's default bridge. Never the host's."""
    if value != '' and (values.name(value) == 'host'):
        values.refuse('build network', value)
    return value


def memory(value):
    if not values.MEMORY.fullmatch(values.operand(value)):
        values.refuse('memory', value)
    return value


labels = group(values.name, values.operand)
mount_pairs = group(values.mount_path, values.mount_path)
read_build = reads(
    labels, build_user, memory, build_network, mount_pairs, mount_pairs, values.path,
    group(values.environment_name, values.text), values.image, values.text,
)


def stdin_build(pull, no_cache, build_args):
    """`docker build -` of the tag read first, then whether to pull, whether to rebuild every layer and the build
    arguments, each read by its reader."""
    return hostapi.asking(reads(values.image, pull, no_cache, build_args), hostbuilds.stdin_build)


def host_build(entrypoint, command):
    """A build container on the host's daemon, its mounts checked right before the run."""
    def prepare(context, run_labels, user, memory_limit, network, mounts, read_only, workdir, environment, image, script):
        return (hostruns.Spec.hardened(
            memory_limit, image=image, cmd=(*command, script), entrypoint=entrypoint, user=user,
            workdir=workdir, environment=tuple(environment), labels=tuple(run_labels), network=network,
            binds=(*binds(context, mounts, ''), *binds(context, read_only, ':ro')),
        ),)
    return hostapi.asking(read_build, hostruns.run_container, prepare)


def binds(context, mounts, suffix):
    return [(trust_store(target, source) if suffix else build_source(context, source)) + ':' + target + suffix
            for target, source in mounts]


def build_source(context, source):
    """The account's checkout, `/home/<user>/project`, or its cache below `/var/cache/panelalpha/projects/<user>`."""
    for root, shapes in (('homes', ((None, 'project'),)), ('project-caches', ((None,), (None, PROJECT_CACHES)))):
        rest = paths.below(context.policy.roots[root], source)
        if rest is not None and any(fits(rest, shape) for shape in shapes):
            return context.mount_source(source, (root,))
    raise Refused(f"'{source}' is not a checkout or a project cache a build may mount")


def fits(rest, shape):
    if len(rest) != len(shape):
        return False
    for part, want in zip(rest, shape):
        if want is None:
            values.name(part)
        elif part != want and part not in (want if isinstance(want, tuple) else ()):
            return False
    return True


def trust_store(target, source):
    """The host's CA bundle, mounted read-only where Debian images look for it."""
    if target != CA_BUNDLE or source not in CA_BUNDLES:
        raise Refused(f"'{source}' is not the host's CA bundle")
    return source


def read_file_from_image(context, run_labels, image, path):
    return (hostruns.Spec(
        image=image, cmd=('--', path), entrypoint=('cat',), labels=run_labels, network='none',
        pull=hostruns.PULL_NEVER,
    ),)


def check_litespeed_serial(context, run_labels, serial):
    return (hostruns.Spec(
        image=LITESPEED_IMAGE, cmd=('-r',), entrypoint=('/usr/local/lsws/bin/lshttpd',), labels=run_labels,
        binds=(litespeed_serial(context, serial) + ':' + LITESPEED_SERIAL,),
    ),)


def litespeed_serial(context, serial):
    rest = paths.below(context.policy.roots['engine'], serial)
    if rest is None or '/'.join(rest) not in LITESPEED_SERIAL_FILES:
        raise Refused(f"'{serial}' is not a LiteSpeed serial the engine writes")
    return context.mount_source(serial, ('engine',))


def pruning(read, work):
    return hostapi.asking(read, work, seconds=hostimages.PRUNE_SECONDS)


CONTAINERS = hostcontainers.ACTIONS

IMAGES = {
    'pull': hostapi.asking(reads(values.image), hostimages.pull, seconds=hostimages.SECONDS),
    'inspectImage': hostapi.asking(reads(values.image), hostimages.inspect_image),
    'imageId': hostapi.asking(reads(values.image), hostimages.image_id),
    'imageExposedPorts': hostapi.asking(reads(values.image), hostimages.image_exposed_ports),
    'imagesLastTagTime': hostapi.asking(reads(rest(values.image)), hostimages.images_last_tag_time),
    'imageNames': hostapi.asking(nothing, hostimages.image_names),
    'imageNamesWithSizes': hostapi.asking(nothing, hostimages.image_names_with_sizes),
    'removeImages': hostapi.asking(reads(rest(values.image)), hostimages.remove_images),
    'forceRemoveImages': hostapi.asking(reads(rest(values.image)), hostimages.force_remove_images),
    'pruneBuildCache': pruning(nothing, hostimages.prune_build_cache),
    'pruneBuildCacheUnusedFor': pruning(reads(values.count), hostimages.prune_build_cache_unused_for),
    'pruneBuildCacheAbove': pruning(reads(values.count), hostimages.prune_build_cache_above),
    'pruneBuildCacheUntilFree': pruning(reads(values.count), hostimages.prune_build_cache_until_free),
}

BUILDS = {
    'inspectManifest': hostbuilds.inspect_manifest(reads(values.image)),
    'buildFromStdin': stdin_build(constant(True), flag('no-cache'), group(values.environment_name, values.text)),
}

RUNS = {
    'nodeBuild': host_build((), ('sh', '-c')),
    'phpBuild': host_build(('sh',), ('-e', '-c')),
    'composerInstall': host_build(('sh',), ('-c',)),
    'readFileFromImage': hostapi.asking(
        reads(labels, values.image, values.path), hostruns.run_container, read_file_from_image,
    ),
    'checkLiteSpeedSerial': hostapi.asking(
        reads(labels, values.mount_path), hostruns.run_container, check_litespeed_serial,
    ),
}

ACTIONS = {**CONTAINERS, **IMAGES, **BUILDS, **RUNS}
