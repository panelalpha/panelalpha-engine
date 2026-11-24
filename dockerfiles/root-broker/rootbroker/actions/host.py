"""The `host` area: one action per App\\System\\Privileged\\RootHost method, under the same name.

Every action runs in the host's namespaces, httpRequest in the network one only. An engine script runs by name once
every component down to it is no symlink and owned by root or the engine.
"""

import contextlib
import functools
import ipaddress
import json
import os
import posixpath
import re

from .. import composefiles, dindrecovery, egress, hostdaemon, paths, projectstats, scriptrun, tenantports, values
from ..refused import Refused
from . import hostapi, hostimages, hostscripts
from .builders import script
from .hosted import fixed, host
from .sequence import flag, group, nothing, reads, rest
from .rootscripts import HOST_CACHE_REGISTRY

BUILD_NETWORK_FIREWALL = 'scripts/build-network-firewall.sh'
TENANT_NETWORK_FIREWALL = 'scripts/tenant-network-firewall.sh'
TENANT_EGRESS_LIMITS = 'scripts/tenant-egress-limits.sh'
PRUNE_PROJECT_CACHES = 'scripts/prune-project-caches.sh'
REQUEST_ENGINE_CERTIFICATE = 'scripts/letsencrypt-request-cert.sh'
WEBSERVER = ('webserver.sh', 'scripts/webserver.sh')
UPDATER = ('updater.sh', 'scripts/updater.sh')
NODE_MODULES_VOLUME = 'pa-nm-'
REGISTRY_API = f'http://{HOST_CACHE_REGISTRY}/v2/'
HTTP_METHODS = ('GET', 'HEAD', 'DELETE')
HTTP_URL = re.compile(r'https?://[^\s\x00-\x1f\x7f]+')
HTTP_HEADERS = ('Accept',)
SPACE = ' \t\n\x0b\f\r'
EMAIL = re.compile(f'[^{SPACE}@-][^{SPACE}@]*@[^{SPACE}@]+')
ENGINE_CERTIFICATE_FLAGS = ('staging', 'force-renewal', 'skip-dns-check', 'dry-run')
SAFE_NAME = re.compile(r'[a-zA-Z0-9_.-]+')
LINE_WORD = re.compile(r'\S+')


def safe_name(value):
    """An account name as SafeName allows it, never all dots, since it reaches `rm -rf`."""
    if not SAFE_NAME.fullmatch(value) or value.strip('.') == '':
        values.refuse('account name', value)
    return value


def plain_hostname(value):
    if value.startswith('*'):
        values.refuse('hostname', value)
    return values.hostname(value)


def ip(value):
    """An IPv4 or IPv6 address without a zone, as PHP's FILTER_VALIDATE_IP takes it."""
    try:
        if '%' in value or value != value.strip():
            raise ValueError(value)
        ipaddress.ip_address(value)
    except ValueError:
        values.refuse('IP address', value)
    return value


def email(value):
    if '\0' in value or not EMAIL.fullmatch(value):
        values.refuse('email address', value)
    return value


def cache_owner(value):
    """The account's `uid:gid`, never root's, since the caches are handed to it."""
    if not values.OWNER_IDS.fullmatch(value) or any(int(part) == 0 for part in value.split(':')):
        values.refuse('cache owner', value)
    return value


certificate_flag = values.one_of(ENGINE_CERTIFICATE_FLAGS, 'certificate flag')
http_method = values.one_of(HTTP_METHODS, 'HTTP method')


def registry_url(value):
    """The cache registry writer's API on the host's loopback."""
    if not HTTP_URL.fullmatch(value) or not value.startswith(REGISTRY_API):
        values.refuse('URL', value)
    return value


header_name = values.one_of(HTTP_HEADERS, 'HTTP header')


def header_value(value):
    if values.LINE_BREAK_OR_NUL.search(value):
        values.refuse('HTTP header', value)
    return value


def no_link_on_the_way(path, root, itself=False):
    """`path` reached from `root` with no symlinked directory on the way (and, with `itself`, not one itself)."""
    location = paths.locate(path, [root])
    if location.parent is None:
        return path
    try:
        if itself and paths.is_symlink(location.parent, location.name):
            raise Refused(f"'{path}' is a symlink")
    finally:
        os.close(location.parent)
    return path


def account_directory(policy, root, account, path):
    expected = posixpath.join(policy.roots[root], account)
    if path != expected:
        raise Refused(f"'{path}' is not {expected}")
    return no_link_on_the_way(path, policy.roots[root], itself=True)


def home(policy, path):
    """A home directly below the homes, as System::projectHomeDirPath() names it."""
    rest = paths.below(policy.roots['homes'], path.rstrip('/'))
    if rest is None or len(rest) != 1:
        raise Refused(f"'{path}' is not a home directly below {policy.roots['homes']}")
    safe_name(rest[0])
    return path



def engine_directory(policy, path):
    if posixpath.normpath(path) != posixpath.normpath(policy.roots['engine']):
        raise Refused(f"'{path}' is not the engine's directory")
    return path


def engine_script(policy, relative):
    """The engine's own script by name, writable only by root since it runs as root; a missing one, or a missing
    engine directory, is returned as is and fails when it runs."""
    engine = policy.roots['engine'].rstrip('/')
    *directories, script = relative.split('/')
    owned = functools.partial(composefiles.check_owner, owners=policy.script_owners, who=composefiles.ROOT_ONLY)
    owned_file = functools.partial(composefiles.owned_file, owners=policy.script_owners, who=composefiles.ROOT_ONLY)
    try:
        top = os.open(engine, paths.DIRECTORY)
    except FileNotFoundError:
        return engine + '/' + relative
    with paths.Walk(owned(top, engine), engine) as walk, walk.refusing():
        with contextlib.suppress(FileNotFoundError):
            walk.down(directories, check=owned).into(script, paths.READ, owned_file)
    return engine + '/' + relative


def node_modules_volume(value):
    """An account's node_modules volume, its account name one a cleanup may remove files for."""
    if not value.startswith(NODE_MODULES_VOLUME):
        values.refuse('node_modules volume', value)
    safe_name(value[len(NODE_MODULES_VOLUME):])
    return value


def read_account_docker_store(given):
    volume = given.one(node_modules_volume)
    return volume, [image for (image,) in given.group(values.image)]


def remove_account_docker_files(context, project_cache, legacy_caches, docker_dir):
    policy = context.policy
    rest = paths.below(policy.roots['homes'], docker_dir)
    if rest is None or len(rest) != 2 or rest[1] != 'docker':
        raise Refused(f"'{docker_dir}' is not an account's ~/docker")
    account = safe_name(rest[0])
    account_directory(policy, 'project-caches', account, project_cache)
    legacy = {posixpath.join(policy.roots[root], account): root for root in ('legacy-js-caches', 'legacy-composer-caches')}
    for (cache,) in legacy_caches:
        if cache not in legacy:
            raise Refused(f"'{cache}' is not one of {account}'s older caches")
        account_directory(policy, legacy[cache], account, cache)
    no_link_on_the_way(docker_dir, policy.roots['homes'])
    return ['rm', '-rf', '--', project_cache, *[cache for (cache,) in legacy_caches], docker_dir]


def own_image(context, image):
    """The image and the cache registry's copy of it, which the host pulls and tags when it lacks the image."""
    return image, values.image(HOST_CACHE_REGISTRY + '/' + values.image(image))


def tenant_network(context):
    """The firewall script's path, checked to be the engine's own and root's alone, and why the limits script it runs
    may not run, None when it may."""
    return engine_script(context.policy, TENANT_NETWORK_FIREWALL), limits_refusal(context.policy)


def build_network(context, network):
    """The firewall script's path, checked to be the engine's own and root's alone, the network's name, and why the
    limits script it runs may not run, None when it may."""
    return engine_script(context.policy, BUILD_NETWORK_FIREWALL), network, limits_refusal(context.policy)


def limits_refusal(policy):
    """Why the outbound limits script may not run as root, None when it is there and root's alone. The isolation
    firewalls run without it then, never not at all."""
    try:
        limits = engine_script(policy, TENANT_EGRESS_LIMITS)
    except Refused as refused:
        return str(refused)
    return None if os.path.isfile(limits) else f"'{limits}' is missing"


def without_limits(output, refusal):
    """The firewall script's flag that skips the limits, with the warning on the action's stderr; none when they run."""
    if refusal is None:
        return ()
    output.stderr(f'the outbound limits were not applied ({refusal}); the isolation firewall is, without them\n'.encode())
    return (tenantports.WITHOUT_LIMITS,)


def apply_build_network_firewall(daemon, output, script, network, refusal):
    """The script run on the network's id, bridge and subnet, read through the host's Engine API, and on no lines when
    the daemon does not answer, as its own read was: its output and exit code are the action's."""
    lines = b''
    with contextlib.suppress(*hostdaemon.UNANSWERED):
        lines = build_network_lines(hostdaemon.network(daemon, network))
    argv = ['sh', script, *without_limits(output, refusal), '--network-on-stdin', network]
    return scriptrun.relayed(output, scriptrun.ran(argv, lines))


def build_network_lines(network):
    """The `id`, `bridge` and `subnet` lines the script reads, each one the network has; none for no network."""
    if network is None:
        return b''
    options = network.get('Options')
    bridge = options.get(hostdaemon.BRIDGE_NAME) if isinstance(options, dict) else None
    fields = (('id', network.get('Id')), ('bridge', bridge), ('subnet', next(iter(hostdaemon.subnets(network)), None)))
    return b''.join(f'{name} {value}\n'.encode() for name, value in fields
                    if isinstance(value, str) and LINE_WORD.fullmatch(value))


def apply_tenant_network_firewall(daemon, output, script, refusal):
    """The script run on the network's subnet and its members' ports as the host's netlink names them, the network
    made through the host's Engine API first when it is missing."""
    return tenantports.firewall(daemon, output, script, without_limits(output, refusal))


def egress_members(daemon):
    return egress.members(daemon, hostdaemon.network(daemon, tenantports.NETWORK))


def egress_limits(context):
    """The limits script's path, checked to be the engine's own and root's alone."""
    return (engine_script(context.policy, TENANT_EGRESS_LIMITS),)


def egress_report(daemon, output, script):
    """The outbound limits as JSON: whether they are in place, the settings in force, and each scan block and recent
    flood, SMTP or scan mark by the account or build that made it."""
    found = egress.reconcile(egress_members(daemon))
    found['settings'] = egress.settings(scriptrun.ran(['sh', script, '--settings']).stdout)
    output.stdout((json.dumps(found, sort_keys=True) + '\n').encode())
    return 0


def egress_unblock(daemon, output, owner):
    """The owner's scan block lifted and its addresses' marks and counters cleared, as JSON naming the addresses; none
    when it had no block."""
    lifted = egress.unblock(owner, egress_members(daemon))
    output.stdout((json.dumps({'owner': owner, 'addresses': lifted}, sort_keys=True) + '\n').encode())
    return 0


def project_stats(context, seconds, usernames):
    """Every account's CPU, memory and I/O from the kernel's cgroups, its containers named by its own daemon, then `L`
    once every running container was checked."""
    return projectstats.Reading(int(seconds), usernames, context.policy.accounts_directory)


def project_disk(context, homes):
    for _, path in homes:
        home(context.policy, path)
    return ['bash', '-c', hostscripts.project_disk(homes)]


def classic_app_types(context, docroots):
    for _, path, docroot, install in docroots:
        home(context.policy, path)
        if paths.below(path, docroot) is None:
            raise Refused(f"'{docroot}' is not inside {path}")
        if install != '' and paths.below(path, install) is None:
            raise Refused(f"'{install}' is not inside {path}")
    return ['bash', '-c', hostscripts.classic_app_types(docroots)]


def prepare_project_cache(context, username, identity):
    policy = context.policy
    directory = account_directory(policy, 'project-caches', username, posixpath.join(policy.roots['project-caches'], username))
    legacy_js = account_directory(policy, 'legacy-js-caches', username, posixpath.join(policy.roots['legacy-js-caches'], username))
    legacy_composer = account_directory(
        policy, 'legacy-composer-caches', username, posixpath.join(policy.roots['legacy-composer-caches'], username),
    )
    return ['sh', '-c', hostscripts.project_cache_preparation(directory, legacy_js, legacy_composer, identity)]


def prune_project_caches(context, age, dry_run, skip):
    return [
        'sh', engine_script(context.policy, PRUNE_PROJECT_CACHES), age,
        *(['--dry-run'] if dry_run else []),
        *(['--skip', ','.join(skip)] if skip else []),
    ]


def http_request(context, method, url, headers):
    options = []
    for name, value in headers:
        options += ['-H', f'{name}: {value}']
    return [
        'curl', '-sS', '-i', '--connect-timeout', '2', '--max-time', '30',
        *(['--head'] if method == 'HEAD' else ['-X', method]),
        *options,
        '--', url,
    ]


def request_engine_certificate(context, engine, domain, mail, address, flags):
    if len(set(flags)) != len(flags):
        values.refuse('certificate flags', ' '.join(flags))
    engine_directory(context.policy, engine)
    engine_script(context.policy, REQUEST_ENGINE_CERTIFICATE)
    options = [f'--{name}={value}' for name, value in (('domain', domain), ('email', mail), ('ip', address)) if value]
    return ['bash', engine + '/' + REQUEST_ENGINE_CERTIFICATE, *options, *[f'--{flag}' for flag in flags]]


def enqueue_engine_update(context, engine, license_key):
    engine_directory(context.policy, engine)
    for script in UPDATER:
        engine_script(context.policy, script)
    return ['bash', '-c', hostscripts.enqueue_engine_update(engine, license_key)]


def change_webserver(context, engine, webserver, serial):
    """The web server switch, run on the broker's own files in the host's network namespace only, so its rebuild in
    core goes through the host's Engine API."""
    engine_directory(context.policy, engine)
    for script in WEBSERVER:
        engine_script(context.policy, script)
    return [
        'bash', engine + '/' + WEBSERVER[0], '--set', webserver,
        *([f'--serial-no={serial}'] if serial else []),
        '--background',
    ]


ACTIONS = {
    'removeAccountDockerStore': hostapi.asking(
        read_account_docker_store, hostimages.remove_account_docker_store, host_only=True,
    ),
    'removeAccountDockerFiles': host(reads(values.path, group(values.path), values.path), remove_account_docker_files),
    'applyBuildNetworkFirewall': hostapi.asking(
        reads(values.name), apply_build_network_firewall, prepare=build_network, host_only=True,
    ),
    'applyTenantNetworkFirewall': hostapi.asking(
        nothing, apply_tenant_network_firewall, prepare=tenant_network, host_only=True,
    ),
    'egressReport': hostapi.asking(nothing, egress_report, prepare=egress_limits, host_only=True),
    'egressUnblock': hostapi.asking(reads(values.name), egress_unblock, host_only=True),
    'ensureOwnImage': hostapi.asking(
        reads(values.image), hostimages.best_effort(hostimages.ensure_own_image), prepare=own_image, host_only=True,
    ),
    'refreshPublicImage': hostapi.asking(
        reads(values.image), hostimages.best_effort(hostimages.refresh_public_image), host_only=True,
    ),
    'projectStats': host(reads(values.positive, rest(values.username)), project_stats, native=True),
    'projectDisk': host(reads(group(values.username, values.path)), project_disk),
    'classicAppTypes': host(reads(group(values.username, values.path, values.path, values.optional(values.path))), classic_app_types),
    'edgeProbe': host(
        reads(plain_hostname, ip, values.integer, flag('https')),
        lambda context, domain, address, seconds, https: ['bash', '-c', hostscripts.edge_probe(domain, address, seconds, https)],
    ),
    'lxcfsFiles': fixed('sh', '-c', hostscripts.lxcfs_files()),
    'prepareProjectCache': host(reads(values.username, cache_owner), prepare_project_cache),
    'pruneProjectCaches': host(reads(values.count, flag('dry-run'), rest(values.username)), prune_project_caches),
    'httpRequest': host(
        reads(http_method, registry_url, group(header_name, header_value)), http_request, namespaces=('net',),
    ),
    'requestEngineCertificate': host(
        reads(values.path, values.optional(plain_hostname), values.optional(email), values.optional(ip),
              rest(certificate_flag)),
        request_engine_certificate,
    ),
    'detectStuckDind': hostapi.asking(nothing, dindrecovery.detect, host_only=True),
    'recoverStuckDind': hostapi.asking(
        reads(flag('wipe-inner'), flag('recreate')), dindrecovery.recover_all, host_only=True,
    ),
    'recoverDindAccount': hostapi.asking(
        reads(values.username, flag('wipe-inner'), flag('recreate')), dindrecovery.recover_account, host_only=True,
    ),
    'enqueueEngineUpdate': host(
        reads(values.path, values.optional(values.operand)), enqueue_engine_update, secret=(1,),
    ),
    'seedEngineUpdatePlaceholder': fixed('bash', '-c', hostscripts.seed_pending_placeholder()),
    'recordEngineUpdateRefusal': host(
        reads(values.text, values.integer),
        lambda context, output, code: script(hostscripts.record_refusal(), 'bash')(output, code),
    ),
    'changeWebserver': host(
        reads(values.path, values.name, values.optional(values.operand)), change_webserver, secret=(2,), detach=True,
        namespaces=('net',),
    ),
    'processExists': host(reads(values.positive), lambda context, pid: ['kill', '-0', '--', pid]),
}
