"""The `account-docker` area, one action per App\\System\\Privileged\\RootAccountDocker method.

The first value names the account, never a path. What goes back is JSON built from checked fields or a container's
log output, never the daemon's reply as it came.
"""

import functools
import http.client
import json
import re
import urllib.parse

from .. import accountdaemon, cli, protocol, registrylogin, relay, replies, values
from ..dockerapi import NO_SUCH_IMAGE, Failed, Unavailable
from .daemoncall import DaemonCall
from .failures import Failures
from .sequence import Command, flag, nothing, reads, rest, step

CONTAINER_STATES = ('created', 'restarting', 'running', 'removing', 'paused', 'exited', 'dead')
PUBLISHED = re.compile(r'[1-9][0-9]{0,4}(?:/(?:tcp|udp|sctp))?')
FILTERS = ('label', 'status', 'publish')
LOG_SECONDS = 60
LOG_LIMIT = 4 << 20
LOG_LINES = 10000
LOG_CUT = b'\n[log cut at 4 MiB]\n'
NO_SUCH_CONTAINER = 'Error response from daemon: No such container: {}'


def printed_json(output, value):
    """`value` on stdout as ASCII JSON, on one line; 0, the exit code."""
    output.stdout(cli.json_line(value, ascii=True))
    return 0


OPENING = Failures(
    (Unavailable, '{e}', protocol.EXIT_UNAVAILABLE),
    (Failed, '{e}', 1),
)
FAILURES = Failures(
    *OPENING.rows,
    (replies.Unexpected, 'the Docker daemon of {daemon.name} answered with an unexpected {e.where}', 1),
    ((http.client.HTTPException, ConnectionError), 'the Docker daemon of {daemon.name} stopped answering: {e!r}',
     protocol.EXIT_UNAVAILABLE),
    (TimeoutError, 'the Docker daemon of {daemon.name} did not answer in time', protocol.EXIT_UNAVAILABLE),
)


def account_daemon(opener, context, name, *given):
    """The daemon of the account the first value names, opened by `opener`, else the account's own dockerd's."""
    account = context.policy.accounts.by_name(name)
    opened = functools.partial(opener or accountdaemon.open_daemon, account, context.policy.accounts_directory)
    return opened, (account,), given


def asking(read, work, prepare=None, passes=(), opener=None):
    """A native action on the account the first value names: `DaemonCall(work, account_daemon(opener), FAILURES,
    OPENING, prepare)`, where `prepare(context, account, *values)` checks the values against that account."""
    call = DaemonCall(work, functools.partial(account_daemon, opener), FAILURES, OPENING, prepare)
    return Command(reads(values.username, read), call, native=True, passes=passes)


def ping(daemon, output):
    """Whether the account's daemon answers its API."""
    daemon.expect('GET', '/_ping', (200,), 64)
    return 0


def containers(daemon, output, refs):
    """Each container's checked inspect, null for one the daemon does not have."""
    return printed_json(output, daemon.containers.each(refs, replies.container))


container_filter = values.one_of(FILTERS, 'container filter')


def container_filters(given):
    """The counted filter and value pairs, each value checked for its filter, grouped by filter."""
    grouped = {}
    for name, value in given.group(container_filter, values.operand):
        grouped.setdefault(name, []).append(filter_value(name, value))
    return grouped


def filter_value(name, value):
    if name == 'status' and value not in CONTAINER_STATES:
        values.refuse('container state', value)
    if name == 'publish' and not PUBLISHED.fullmatch(value):
        values.refuse('published port', value)
    if name == 'label':
        values.name(value.split('=', 1)[0])
    return value


def list_containers(daemon, output, every, grouped):
    """`docker ps`: the containers the filters match, every one with `all`."""
    query = {'all': '1' if every else '0', 'filters': json.dumps(grouped)}
    return printed_json(output, daemon.containers.listed(query, replies.containers, missing=()))


def images(daemon, output, refs):
    """Each image's checked inspect, null for one the daemon does not have."""
    return printed_json(output, daemon.images.each(refs, replies.image))


def has_image(daemon, output, ref):
    """Asks for the history, which needs the image's config: a tag held without one counts as missing."""
    daemon.images.inspected(ref, replies.history, NO_SUCH_IMAGE, part='/history')
    return 0


def list_images(daemon, output):
    """`docker images`: every image the daemon holds."""
    return printed_json(output, replies.images(daemon.json('GET', '/images/json', missing=())))


def taggable(value):
    if '@' in values.image(value):
        values.refuse('tag', value)
    return value


def tag(daemon, output, source, target):
    """`docker tag` in the account's daemon."""
    daemon.images.tag(source, target)
    return 0


def remove_images(daemon, output, force, refs):
    """Every image is tried, as `docker rmi` does: any failure fails the whole, and with force a missing one is none."""
    return daemon.images.remove_each(output, refs, f'force={int(force)}', forgiving=force)


def with_login(context, account, ref, login):
    """The login directory, when given, must be the account's own."""
    return ref, account, registrylogin.directory(account, login) if login else ''


def pull(daemon, output, ref, account=None, login=''):
    """`docker pull` into the account's daemon, with the project's registry login when it has one."""
    auth = registrylogin.header(account, login, ref) if login else None
    daemon.pull(ref, {'X-Registry-Auth': auth} if auth else None)
    return 0


def log_lines(value):
    if int(values.positive(value)) > LOG_LINES:
        values.refuse('number of log lines', value)
    return value


def log_tail(daemon, output, ref, lines, timestamps):
    """Both streams on stdout in the order the daemon sent them, as `docker logs … 2>&1` prints them; with
    `timestamps`, each line led by the time the daemon logged it, as `docker logs -t` prints it."""
    tty = daemon.containers.inspected(ref, replies.container, NO_SUCH_CONTAINER).get('Config', {}).get('Tty') is True
    query = {'stdout': '1', 'stderr': '1', 'tail': lines}
    if timestamps:
        query['timestamps'] = '1'
    silence = f'{daemon.who} sent no log of {ref} within {LOG_SECONDS}s'
    path = f'{daemon.containers.path(ref)}/logs?{urllib.parse.urlencode(query)}'
    with daemon.streaming('GET', path, LOG_SECONDS, silence=silence) as response:
        output.stdout(read_log(response, tty))
    return 0


def read_log(response, tty):
    """The raw stream for a TTY, else each 8-byte-headed frame's payload; read to a byte past LOG_LIMIT, cut there."""
    if tty:
        return cut(response.read(LOG_LIMIT + 1))
    out = bytearray()
    for _, payload in relay.Frames().pulled(lambda size: response.read(min(size, LOG_LIMIT + 1 - len(out)))):
        out += payload
        if len(out) > LOG_LIMIT:
            break
    return cut(bytes(out))


def cut(log):
    return log if len(log) <= LOG_LIMIT else log[:LOG_LIMIT] + LOG_CUT


ACTIONS = {
    'ping': asking(nothing, ping),
    'containers': asking(reads(rest(values.name)), containers),
    'listContainers': asking(reads(flag('all'), step(container_filters)), list_containers),
    'images': asking(reads(rest(values.image)), images),
    'hasImage': asking(reads(values.image), has_image),
    'listImages': asking(nothing, list_images),
    'tag': asking(reads(values.image, taggable), tag),
    'removeImages': asking(reads(flag('force'), rest(values.image)), remove_images),
    'pull': asking(reads(values.image, values.optional(values.path)), pull, with_login),
    'logTail': asking(reads(values.name, log_lines, flag('timestamps')), log_tail),
}
