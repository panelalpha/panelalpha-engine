"""What an account's compose file may hold: the shape of the engine's account templates and nothing more.

The broker renders the file itself, with no filesystem around compose but the plugin, so the model it checks is the
file's own bytes and cannot name another file. The same bytes then run.
"""

import json
import os
import posixpath
import re
import stat
import subprocess

from . import dockerapi, ownership, paths, runner
from .refused import Refused

PLUGIN = '/usr/libexec/docker/cli-plugins/docker-compose'
UNSHARE = ('unshare', '--mount')
SECONDS = 30
LARGEST = 1 << 20

SANDBOX = '''set -e
mount -t tmpfs -o size=1m,mode=0755 compose /mnt
: > /mnt/compose
mount --bind "$1" /mnt/compose
shift
cd /mnt
exec chroot /mnt /compose "$@"'''

IMAGE = re.compile(
    r'ghcr\.io/panelalpha/engine-user-[a-z0-9]+(?:-[a-z0-9]+)*(?::[\w][\w.-]{0,127})?(?:@sha256:[0-9a-f]{64})?', re.ASCII)
NETWORKS = ('pash-tenants', 'pash-default-network')
SERVICES = ('php', 'dind')
DIND = 'dind'
DOCKERFILES = ('Dockerfile', './Dockerfile')
HOST_GATEWAY = ('host.docker.internal=host-gateway', 'host.docker.internal:host-gateway')
LXCFS = '/var/lib/lxcfs/proc'
LXCFS_FILES = ('meminfo', 'cpuinfo', 'stat', 'loadavg', 'diskstats', 'uptime', 'swaps')
BLOCK_DEVICE = re.compile(r'/dev/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*')
LIMIT = re.compile(r'[0-9]+(?:\.[0-9]+)?[a-zA-Z]{0,2}')
RESTART = ('always', 'unless-stopped', 'no', 'on-failure')
PULL = ('missing', 'always', 'never', 'build', 'if_not_present')
BLKIO = ('device_read_bps', 'device_write_bps')
TOP = ('name', 'services', 'networks')
RUNTIME_FILE = '.env-core'
RUNTIME_SETTING = 'DIND_RUNTIME'
PRIVILEGED = 'privileged'


def render(content, options, unshare=UNSHARE, plugin=PLUGIN):
    """`docker compose <options> config --format json` of `content`, in a mount namespace holding only the plugin."""
    argv = [*unshare, 'sh', '-c', SANDBOX, 'sh', plugin, *options, '-f', '-', 'config', '--format', 'json']
    try:
        done = subprocess.run(argv, input=content, capture_output=True, env=runner.ENVIRONMENT, timeout=SECONDS, cwd='/')
    except subprocess.TimeoutExpired as e:
        raise Refused('the compose file took too long to render') from e
    if done.returncode != 0:
        raise Refused('the compose file does not render: ' + done.stderr.decode(errors='replace').strip()[-300:])
    try:
        return json.loads(done.stdout)
    except ValueError as e:
        raise Refused('the compose file rendered to something other than JSON') from e


def is_empty(value):
    return value is None or value is False or value == '' or value == {} or value == []


def present(where, value):
    """The keys of a mapping that hold anything, since an empty or false value asks for nothing."""
    return {key: item for key, item in entries(where, value) if not is_empty(item)}


def entries(where, value):
    if not isinstance(value, dict):
        refuse(where, 'is not a mapping')
    return value.items()


def refuse(where, why):
    raise Refused(f"the account's compose file is not one the engine writes: {where} {why}")


class Account:
    """The account a compose file belongs to, with the paths its templates may bind."""

    def __init__(self, user, project, directory, directory_fd, home, privileged):
        self.user = user
        self.project = project
        self.directory = directory
        self.directory_fd = directory_fd
        self.home = home
        self.privileged = privileged


def check(model, account):
    if not isinstance(model, dict):
        raise Refused('the compose file rendered to no project')
    model = present('the project', model)
    for key in model:
        if key not in TOP:
            refuse(key, 'is set')
    if model.get('name') != account.project:
        refuse('name', f'is {model.get("name")!r}, not the account')
    networks = model.get('networks', {})
    for name, network in entries('networks', networks):
        check_network(f'networks.{name}', network)
    for name, service in entries('services', model.get('services', {})):
        check_service(f'services.{name}', name, service, account, networks)


def check_network(where, network):
    network = present(where, network or {})
    if network.get('external') is not True or network.get('name') not in NETWORKS:
        refuse(where, 'is not an external tenant network')
    for key in network:
        if key not in ('external', 'name', 'ipam'):
            refuse(f'{where}.{key}', 'is set')


def check_service(where, name, service, account, networks):
    if name not in SERVICES:
        refuse(where, 'is not an account service')
    service = present(where, service)
    for key, value in service.items():
        checker = SERVICE_KEYS.get(key)
        if checker is None:
            refuse(f'{where}.{key}', 'is set')
        checker(f'{where}.{key}', value, name, account, networks)


def equal(expected):
    def check_value(where, value, *_):
        if value not in expected:
            refuse(where, f'is {value!r}')
    return check_value


def to_user(where, value, _name, account, _networks):
    if value != account.user:
        refuse(where, f'is {value!r}, not the account')


def image(where, value, *_):
    if not isinstance(value, str) or not IMAGE.fullmatch(value):
        refuse(where, f'is {value!r}, not an engine account image')


def build(where, value, _name, account, _networks):
    value = present(where, value)
    for key in value:
        if key not in ('context', 'dockerfile'):
            refuse(f'{where}.{key}', 'is set')
    if value.get('context', '').rstrip('/') != account.directory:
        refuse(f'{where}.context', f"is {value.get('context')!r}, not the account's directory")
    if value.get('dockerfile', 'Dockerfile') not in DOCKERFILES:
        refuse(f'{where}.dockerfile', f"is {value.get('dockerfile')!r}")


def privileged(where, value, name, account, _networks):
    if value is not True or name != DIND or not account.privileged:
        refuse(where, 'is set')


def runtime(where, value, *_):
    if value != dockerapi.SYSBOX_RUNTIME:
        refuse(where, f'is {value!r}')


def strings(where, value, *_):
    if not isinstance(value, list) or not all(isinstance(item, str) for item in value):
        refuse(where, 'is not a list of strings')


def extra_hosts(where, value, *_):
    if isinstance(value, dict):
        value = [f'{host}={address}' for host, addresses in value.items()
                 for address in (addresses if isinstance(addresses, list) else [addresses])]
    strings(where, value)
    for item in value:
        if item not in HOST_GATEWAY:
            refuse(where, f'adds {item!r}')


def limit(where, value, *_):
    if isinstance(value, bool) or not LIMIT.fullmatch(str(value)):
        refuse(where, f'is {value!r}')


def flag(where, value, *_):
    if value is not True:
        refuse(where, f'is {value!r}')


def service_networks(where, value, _name, _account, networks):
    for name, attached in entries(where, value):
        if name not in networks:
            refuse(f'{where}.{name}', 'is not a network the file declares')
        for key in present(f'{where}.{name}', attached or {}):
            if key not in ('aliases', 'ipv4_address', 'ipv6_address'):
                refuse(f'{where}.{name}.{key}', 'is set')


def blkio(where, value, *_):
    for key, devices in present(where, value).items():
        if key not in BLKIO or not isinstance(devices, list):
            refuse(f'{where}.{key}', 'is set')
        for device in devices:
            device = {name.lower(): item for name, item in entries(f'{where}.{key}', device)}
            if set(device) - {'path', 'rate'} or not BLOCK_DEVICE.fullmatch(str(device.get('path'))):
                refuse(f'{where}.{key}', f'names {device!r}')
            if '..' in device['path'].split('/'):
                refuse(f'{where}.{key}', f'names {device!r}')


def volumes(where, value, _name, account, _networks):
    if not isinstance(value, list):
        refuse(where, 'is not a list')
    for index, volume in enumerate(value):
        check_volume(f'{where}[{index}]', present(f'{where}[{index}]', volume), account)


def check_volume(where, volume, account):
    if volume.get('type') != 'bind':
        refuse(where, f"is a {volume.get('type')!r} mount, not a bind")
    for key in volume:
        if key not in ('type', 'source', 'target', 'read_only', 'bind'):
            refuse(f'{where}.{key}', 'is set')
    for key in present(f'{where}.bind', volume.get('bind', {})):
        if key != 'create_host_path':
            refuse(f'{where}.bind.{key}', 'is set')
    source = volume.get('source')
    if not isinstance(source, str) or not source.startswith('/') or {'.', '..'} & set(source.split('/')):
        refuse(f'{where}.source', f'is {source!r}')
    if not isinstance(volume.get('target'), str):
        refuse(f'{where}.target', 'is not a path')
    check_source(where, posixpath.normpath(source), volume.get('read_only') is True, account)


def check_source(where, source, read_only, account):
    if source == account.home:
        if os.path.islink(source):
            refuse(f'{where}.source', f'{source!r} is a symlink')
        return
    if source == LXCFS or posixpath.dirname(source) == LXCFS and posixpath.basename(source) in LXCFS_FILES:
        if not read_only:
            refuse(f'{where}.source', f'binds {source!r} writable')
        return
    relative = paths.below(account.directory, source)
    if not relative:
        refuse(f'{where}.source', f"is {source!r}, outside the account's own files")
    no_symlinks(where, account.directory_fd, relative, source)


def no_symlinks(where, directory_fd, relative, source):
    """Every component of a bind source below the account's directory that exists, opened without following."""
    fd = os.dup(directory_fd)
    try:
        for index, part in enumerate(relative):
            try:
                status = os.stat(part, dir_fd=fd, follow_symlinks=False)
            except FileNotFoundError:
                return
            if stat.S_ISLNK(status.st_mode):
                refuse(f'{where}.source', f'{source!r} passes through a symlink')
            if index == len(relative) - 1 or not stat.S_ISDIR(status.st_mode):
                return
            child = paths.open_beneath(fd, part)
            os.close(fd)
            fd = child
    finally:
        os.close(fd)


SERVICE_KEYS = {
    'build': build,
    'image': image,
    'pull_policy': equal(PULL),
    'restart': equal(RESTART),
    'hostname': to_user,
    'container_name': to_user,
    'extra_hosts': extra_hosts,
    'privileged': privileged,
    'runtime': runtime,
    'tmpfs': strings,
    'volumes': volumes,
    'tty': flag,
    'cpus': limit,
    'mem_limit': limit,
    'memswap_limit': limit,
    'blkio_config': blkio,
    'networks': service_networks,
}


def privileged_runtime(engine_fd, owners):
    """Whether `.env-core`, owned as the engine's compose files must be, sets DIND_RUNTIME=privileged."""
    try:
        fd = os.open(RUNTIME_FILE, paths.READ, dir_fd=engine_fd)
    except OSError:
        return False
    try:
        status = os.fstat(fd)
        if not stat.S_ISREG(status.st_mode) or not ownership.written_only_by(status, owners):
            return False
        content = os.read(fd, LARGEST).decode(errors='replace')
    finally:
        os.close(fd)
    return setting(content, RUNTIME_SETTING).strip().lower() == PRIVILEGED


def setting(content, name):
    for line in content.splitlines():
        key, sign, value = line.strip().removeprefix('export ').partition('=')
        if sign and key.strip() == name:
            value = value.strip()
            return value[1:-1] if len(value) > 1 and value[0] == value[-1] and value[0] in '"\'' else value
    return ''
