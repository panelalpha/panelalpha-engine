"""The engine's images on the host's own daemon through the Engine API, printed as the docker CLI prints them.

A failure is the CLI's words and exit code; the two best_effort host steps say nothing and never fail.
"""

import base64
import http.client
import json
import urllib.parse

from .. import cli, dockerapi, replies
from ..dockerapi import DAEMON_ERROR, NO_SUCH_IMAGE, Failed, ImageRef, Progress, Unavailable
from ..output import DROPPED

SECONDS = 900
PRUNE_SECONDS = 600
TOTAL_DIGITS = 4
HOST = "the host's Docker"
CACHE_REGISTRY = 'panelalpha-cache-registry'
CACHE_REGISTRY_WRITER = 'panelalpha-cache-registry-writer'
NO_AUTH = base64.urlsafe_b64encode(b'{}').decode()
SWALLOWED = (OSError, ValueError, http.client.HTTPException, Failed, Unavailable)


def encoded(text):
    return cli.line(text, 'replace')


def printed(output, texts):
    return cli.printed(output, texts, 'replace')


def field(reply, *names):
    """A text field of the daemon's reply, '' where the reply does not hold one."""
    value = reply
    for name in names:
        value = value.get(name) if isinstance(value, dict) else None
    return value if isinstance(value, str) else ''


def inspected(daemon, ref):
    """The host's reply for one image, failing in the CLI's words when it does not hold it."""
    return daemon.images.inspected(ref, replies.unchecked, NO_SUCH_IMAGE)


def inspect_image(daemon, output, ref):
    """`docker image inspect`: the image's JSON indented, or `[]` beside the failure."""
    found = daemon.images.inspect(ref, replies.unchecked)
    if found is None:
        output.stdout(b'[]\n')
        output.stderr(encoded(NO_SUCH_IMAGE.format(ref)))
        return 1
    output.stdout(encoded(cli.indented([found])))
    return 0


def image_id(daemon, output, ref):
    """`{{.Id}}` of one image."""
    return printed(output, [field(inspected(daemon, ref), 'Id')])


def image_exposed_ports(daemon, output, ref):
    """`{{json .Config.ExposedPorts}}`: `null` when the image declares no port."""
    found = inspected(daemon, ref)
    config = found.get('Config') if isinstance(found, dict) else None
    ports = config.get('ExposedPorts') if isinstance(config, dict) else None
    return printed(output, [cli.go_json(ports)])


def image_label(daemon, output, ref, name):
    """`{{ index .Config.Labels "<name>" }}`: an empty line when the image carries no such label."""
    return printed(output, [field(inspected(daemon, ref), 'Config', 'Labels', name)])


def images_last_tag_time(daemon, output, refs):
    """`{{json .Metadata.LastTagTime}}` of each image, one line apiece."""
    return daemon.images.each_line(output, refs, replies.unchecked, NO_SUCH_IMAGE, last_tag_time)


def last_tag_time(found):
    metadata = found.get('Metadata') if isinstance(found, dict) else None
    stamp = metadata.get('LastTagTime') if isinstance(metadata, dict) else None
    return encoded(cli.go_json(stamp if isinstance(stamp, str) else None))


def image_names(daemon, output):
    """`docker images`: one line per repository tag; an image with none is left out, as the CLI leaves it out."""
    return list_images(daemon, output, sizes=False)


def image_names_with_sizes(daemon, output):
    """`docker images` with the CLI's size beside each tag."""
    return list_images(daemon, output, sizes=True)


def list_images(daemon, output, sizes):
    found = daemon.json('GET', '/images/json', missing=())
    images = found if isinstance(found, list) else ()
    return printed(output, (text for image in images for text in listed_image(image, sizes)))


def listed_image(image, sizes):
    """One line per repository tag of a listed image, with its size when asked for."""
    image = image if isinstance(image, dict) else {}
    size, tags = image.get('Size'), image.get('RepoTags')
    suffix = '\t' + cli.size(size if isinstance(size, int) and size > 0 else 0) if sizes else ''
    return [tag + suffix for tag in tags if isinstance(tag, str)] if isinstance(tags, list) else []


def remove_images(daemon, output, refs):
    """`docker rmi`: every image is tried, and a missing one fails the whole."""
    return delete_images(daemon, output, refs, force=False)


def force_remove_images(daemon, output, refs):
    """`docker rmi -f`: a missing image is no failure of the whole."""
    return delete_images(daemon, output, refs, force=True)


def remove_image(daemon, output, ref):
    """`docker image rm` of the one image a line names."""
    return delete_images(daemon, output, [ref], force=False)


def delete_images(daemon, output, refs, force):
    """Every image is tried, as `docker rmi` tries them; with force a missing one is told but no failure."""
    return daemon.images.remove_each(output, refs, f'force={int(force)}', forgiving=force, told=deleted,
                                     tells_missing=True)


def deleted(ref, body):
    """The `Untagged:` and `Deleted:` lines the daemon answers a removal with."""
    try:
        entries = json.loads(body)
    except (ValueError, RecursionError):
        return b''
    return b''.join(encoded(f'{name}: {entry[name]}') for entry in (entries if isinstance(entries, list) else ())
                    for name in ('Untagged', 'Deleted') if isinstance(entry, dict) and isinstance(entry.get(name), str))


def tag_image(daemon, output, source, target):
    """`docker tag`, which prints nothing."""
    daemon.images.tag(source, target)
    return 0


def pull(daemon, output, image):
    """`docker pull`: the daemon's progress, then the reference as the CLI names it once pulled."""
    return transferred(output, image, daemon.images.pulling(image, SECONDS), output)


def pull_quietly(daemon, output, image):
    """`docker pull -q`: only that reference."""
    return transferred(output, image, daemon.images.pulling(image, SECONDS), DROPPED)


def push_quietly(daemon, output, image):
    """`docker push -q` to the cache registry, which needs no login: `{}` as the auth header."""
    return transferred(output, image, daemon.images.pushing(image, SECONDS, {'X-Registry-Auth': NO_AUTH}), DROPPED)


def transferred(output, image, streaming, shown):
    """A pull or push read to its end, its progress on `shown`, then the reference as the CLI names it."""
    with streaming as response:
        failure = progress(response, shown)
    if failure is not None:
        raise Failed(f'{DAEMON_ERROR}{failure}')
    return printed(output, [ImageRef(image).cli_name()])


def progress(response, output):
    """The first error of a progress stream, read to its end; its lines go to `output`."""
    failure = None
    for event in Progress(response, HOST, 'a progress line'):
        message = dockerapi.progress_error(event)
        if message:
            failure = failure or dockerapi.one_line(message)
        elif failure is None:
            output.stdout(progress_line(event))
    return failure


def progress_line(event):
    """One line of a pull as the CLI's non-terminal printer writes it; a progress bar is dropped as there."""
    if not isinstance(event, dict) or event.get('progressDetail'):
        return b''
    if isinstance(event.get('stream'), str):
        return event['stream'].encode('utf-8', 'replace')
    status = event.get('status') if isinstance(event.get('status'), str) else ''
    identifier = event.get('id') if isinstance(event.get('id'), str) else ''
    return encoded(f'{identifier}: {status}' if identifier else status)


def prune_build_cache(daemon, output):
    """`docker buildx prune -af`."""
    return prune(daemon, output)


def prune_build_cache_unused_for(daemon, output, seconds):
    """`docker buildx prune -af --filter until=<seconds>s`."""
    return prune(daemon, output, filters=json.dumps({'until': [f'{seconds}s']}, separators=(',', ':')))


def prune_build_cache_above(daemon, output, space):
    """`docker buildx prune -af --max-used-space <bytes>`."""
    return prune(daemon, output, **{'max-used-space': space})


def prune_build_cache_until_free(daemon, output, space):
    """`docker buildx prune -af --min-free-space <bytes>`."""
    return prune(daemon, output, **{'min-free-space': space})


def prune(daemon, output, **query):
    report = daemon.json('POST', '/build/prune?' + urllib.parse.urlencode({'all': '1', **query}), missing=())
    reclaimed = report.get('SpaceReclaimed') if isinstance(report, dict) else None
    return printed(output, ['Total:\t' + cli.size(reclaimed if isinstance(reclaimed, int) else 0, TOTAL_DIGITS)])


def ensure_own_image(daemon, output, image, source):
    """The host's copy of one of our images, pulled from the cache registry and tagged under its own name."""
    if not held_image(daemon, image) and cache_registry_running(daemon):
        pull_quietly(daemon, DROPPED, source)
        daemon.images.tag(source, image)
    return 0


def refresh_public_image(daemon, output, image):
    """A public image pulled again through the host's registry mirror."""
    return pull_quietly(daemon, DROPPED, image)


def best_effort(work):
    """A step that reports nothing and answers 0 whatever happens."""
    def run(daemon, output, *values):
        return ignore(work, daemon, output, *values)
    return run


def ignore(work, *arguments):
    """`work(*arguments)`, or 0 when it raises one of SWALLOWED."""
    try:
        return work(*arguments)
    except SWALLOWED:
        return 0


def held_image(daemon, ref):
    """Whether the host holds the image; a failed inspect counts as no."""
    try:
        return daemon.images.inspect(ref, replies.unchecked) is not None
    except Failed:
        return False


def cache_registry_running(daemon):
    """Whether both containers of the cache registry are running."""
    return container_running(daemon, CACHE_REGISTRY) and container_running(daemon, CACHE_REGISTRY_WRITER)


def container_running(daemon, name):
    try:
        found = daemon.containers.inspect(name, replies.unchecked)
    except Failed:
        return False
    state = found.get('State') if isinstance(found, dict) else None
    return isinstance(state, dict) and state.get('Running') is True


def remove_account_docker_store(daemon, output, volume, images):
    """An account's node_modules volume and the images only it pulled, every failure ignored."""
    ignore(daemon.volumes.delete, volume, 'force=1')
    for image in images:
        ignore(daemon.images.delete, image)
    return 0
