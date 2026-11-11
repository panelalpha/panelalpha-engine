"""The `docker` area's container, network and registry actions: the host's daemon through its Engine API."""

import json
import re
from fractions import Fraction

from .. import cli, dockerapi, replies, values
from ..dockerapi import quoted
from .builders import script
from .hostapi import asking
from .sequence import nothing, reads, rest

REGISTRY_CONTAINERS = ('panelalpha-cache-registry-writer', 'panelalpha-registry-proxy')
REGISTRY_TAG_LINKS = '*/_manifests/tags/*/current/link'
REGISTRY_TAG_LINE = 'for f do printf "%s %s %s\\n" "$(stat -c %Y "$f")" "$(cat "$f")" "$f"; done'
REGISTRY_STORAGE = '/var/lib/registry'
REGISTRY_REPOSITORY = re.compile(r'[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*')
NO_SUCH_OBJECT = 'error: no such object: {}'
CONTAINER_SECONDS = 300
MEGABYTE = 1 << 20
NANO = 10 ** 9


registry_container = values.one_of(REGISTRY_CONTAINERS, 'registry container')


def registry_repository(value):
    """A repository name as the registry's grammar has it, so the directory removed below its repositories cannot be
    another one."""
    if not REGISTRY_REPOSITORY.fullmatch(value):
        values.refuse('registry repository', value)
    return value


def info(daemon, output):
    """`docker info`: the daemon's `/info`."""
    output.stdout(cli.json_line(daemon.json('GET', '/info')))
    return 0


def registry_mirrors(daemon, output):
    """The registry mirrors the daemon pulls through."""
    config = (daemon.json('GET', '/info') or {}).get('RegistryConfig') or {}
    output.stdout(cli.json_line(config.get('Mirrors')))
    return 0


def listed(daemon, every, filters):
    query = {'filters': json.dumps(filters, sort_keys=True, separators=(',', ':')), **({'all': '1'} if every else {})}
    return daemon.containers.listed(query, replies.unchecked) or []


def name_filter(name):
    return '^/' + re.escape(name) + '$'


def running_container_names(daemon, output):
    """`docker ps --format {{.Names}}`."""
    return cli.printed(output, map(cli.names, listed(daemon, False, {})))


def container_images(daemon, output):
    """`docker ps -a --format {{.Image}}`."""
    return cli.printed(output, (container.get('Image', '') for container in listed(daemon, True, {})))


def container_named(daemon, output, name):
    """`docker ps -a` of the one container with that exact name."""
    return cli.printed(output, map(cli.names, listed(daemon, True, {'name': [name_filter(name)]})))


def container_named_with_label(daemon, output, name, key, value):
    """`docker ps -a` of the container with that exact name and label."""
    filters = {'name': [name_filter(name)], 'label': [f'{key}={value}']}
    return cli.printed(output, map(cli.names, listed(daemon, True, filters)))


def running_container_ids_labelled(daemon, output, key):
    """The short IDs of the running containers that carry the label."""
    return cli.printed(output, map(cli.short_id, listed(daemon, False, {'label': [key]})))


def running_container_ids_with_label(daemon, output, key, value):
    """The short IDs of the running containers whose label has that value."""
    return cli.printed(output, map(cli.short_id, listed(daemon, False, {'label': [f'{key}={value}']})))


def stopped_container_ids_labelled(daemon, output, key):
    """The short IDs of the exited or created containers that carry the label."""
    filters = {'label': [key], 'status': ['exited', 'created']}
    return cli.printed(output, map(cli.short_id, listed(daemon, True, filters)))


def containers_labelled(daemon, output, key):
    """Name, state and label value of every container that has the label."""
    return cli.printed(output, (
        f'{cli.names(container)}\t{container.get("State", "")}\t{(container.get("Labels") or {}).get(key, "")}'
        for container in listed(daemon, True, {'label': [key]})
    ))


def inspected(daemon, name):
    return daemon.containers.inspected(name, replies.unchecked, NO_SUCH_OBJECT)


def container_line(container):
    state = container.get('State') or {}
    return cli.line('\t'.join((
        container.get('Name', ''),
        (container.get('HostConfig') or {}).get('Runtime', ''),
        dockerapi.labels(container).get(dockerapi.WORKING_DIR, ''),
        state.get('StartedAt', ''),
        json.dumps(state.get('Error') or '', ensure_ascii=False),
    )))


def inspect(daemon, output, names):
    """Name, runtime, compose working directory, start time as `{{.State.StartedAt}}` prints it and last start error
    of each container, a line apiece."""
    return daemon.containers.each_line(output, names, replies.unchecked, NO_SUCH_OBJECT, container_line)


def is_running(daemon, output, name):
    """`{{.State.Running}}` of a container: `true` or `false`."""
    state = inspected(daemon, name).get('State') or {}
    return cli.printed(output, ['true' if state.get('Running') is True else 'false'])


def started_at(daemon, output, name):
    """When a container last started."""
    return cli.printed(output, [(inspected(daemon, name).get('State') or {}).get('StartedAt', '')])


def nano_cpus(daemon, output, name):
    """`{{.HostConfig.NanoCpus}}` of a container: its CPU limit in billionths of a CPU, 0 for none."""
    limit = (inspected(daemon, name).get('HostConfig') or {}).get('NanoCpus')
    return cli.printed(output, [str(limit) if isinstance(limit, int) and not isinstance(limit, bool) else '0'])


def container_networks(daemon, output, name):
    """`{{json .NetworkSettings.Networks}}` of a container."""
    output.stdout(cli.json_line((inspected(daemon, name).get('NetworkSettings') or {}).get('Networks')))
    return 0


def running_environment(daemon, output, name):
    """A running container's environment, a variable a line; nothing when it is stopped."""
    container = inspected(daemon, name)
    running = (container.get('State') or {}).get('Running') is True
    return cli.printed(output, ((container.get('Config') or {}).get('Env') or ()) if running else ())


def cpu_percent(daemon, name):
    """The CLI's formula over the container's stats sample."""
    return cli.cpu_percent(replies.unchecked_object(
        daemon.json('GET', f'/containers/{quoted(name)}/stats?stream=false', missing=())))


def cpu_percents(daemon, output, names):
    """`docker stats --no-stream` CPU percent of each container, in the order given."""
    return cli.printed(output, [f'{cpu_percent(daemon, name):.2f}%' for name in names])


def limit_memory(daemon, output, name, megabytes):
    """`docker update --memory`: the memory and swap limit in megabytes."""
    memory = int(megabytes) * MEGABYTE
    daemon.json('POST', f'/containers/{quoted(name)}/update', missing=(),
                body={'Memory': memory, 'MemorySwap': memory})
    return cli.printed(output, [name])


def limit_cpu(daemon, output, name, cpus):
    """`docker update --cpus`: the limit in billionths of a CPU, as the CLI sends it. The daemon ignores 0, which the
    value check refuses."""
    daemon.json('POST', f'/containers/{quoted(name)}/update', missing=(),
                body={'NanoCpus': int(Fraction(cpus) * NANO)})
    return cli.printed(output, [name])


def restart(daemon, output, name):
    """`docker restart`."""
    daemon.json('POST', f'/containers/{quoted(name)}/restart', missing=())
    return cli.printed(output, [name])


def force_remove(daemon, output, *names):
    """Every name is tried, as `docker rm` does; with force a missing one is no failure and prints nothing."""
    return daemon.containers.remove_each(output, names, 'force=1', forgiving=True, told=removed)


def removed(name, body):
    """The name `docker rm` prints of a container it removed; the daemon's reply holds nothing."""
    return cli.line(name)


def network(daemon, name):
    return daemon.networks.inspect(name, replies.unchecked_object, missing=())


def network_subnets_and_gateways(daemon, output, name):
    """A network's subnets and gateways, a pair a line."""
    configs = (network(daemon, name).get('IPAM') or {}).get('Config') or ()
    return cli.printed(output, (f'{config.get("Subnet", "")} {config.get("Gateway", "")}' for config in configs))


def network_id(daemon, output, name):
    """A network's ID."""
    return cli.printed(output, [network(daemon, name).get('Id', '')])


def create_bridge_network(daemon, output, name, bridge, role):
    """`docker network create` of a bridge under the engine's bridge name and role label; prints its ID."""
    created = daemon.json('POST', '/networks/create', missing=(), body={
        'Name': name,
        'Driver': 'bridge',
        'Options': {'com.docker.network.bridge.name': bridge},
        'Labels': {'com.panelalpha.role': role},
    })
    return cli.printed(output, [replies.unchecked_object(created).get('Id', '')])


def connect_network(daemon, output, network_name, container):
    """`docker network connect`."""
    daemon.json('POST', f'/networks/{quoted(network_name)}/connect', missing=(), body={'Container': container})
    return 0


def disconnect_network(daemon, output, network_name, container):
    """`docker network disconnect`."""
    daemon.json('POST', f'/networks/{quoted(network_name)}/disconnect', missing=(), body={'Container': container})
    return 0


def registry_garbage_collect(daemon, output, container, config):
    """`registry garbage-collect --delete-untagged` in one of the engine's registry containers."""
    return daemon.exec(output, container, ['registry', 'garbage-collect', '--delete-untagged', config])


def registry_tag_links(daemon, output, container, repositories):
    """Each tag link of the registry's repositories, with its time and digest."""
    return daemon.exec(output, container, [
        'find', repositories, '-path', REGISTRY_TAG_LINKS, '-exec', *script(REGISTRY_TAG_LINE)('{}'), '+',
    ])


def remove_registry_repository(daemon, output, container, repositories, name):
    """Removes one repository's directory from the registry's repositories."""
    return daemon.exec(output, container, ['rm', '-rf', '--', repositories + '/' + name])


def registry_storage_kilobytes(daemon, output, container):
    """The size of a registry container's storage, as `du -sk` gives it."""
    return daemon.exec(output, container, ['du', '-sk', '--', REGISTRY_STORAGE])


def wipe_registry_storage(daemon, output, container):
    """Empties a registry container's storage."""
    return daemon.exec(output, container,
                       ['rm', '-rf', '--', REGISTRY_STORAGE + '/docker', REGISTRY_STORAGE + '/scheduler-state.json'])


ACTIONS = {
    'info': asking(nothing, info),
    'registryMirrors': asking(nothing, registry_mirrors),
    'runningContainerNames': asking(nothing, running_container_names),
    'containerImages': asking(nothing, container_images),
    'containerNamed': asking(reads(values.name), container_named),
    'containerNamedWithLabel': asking(reads(values.name, values.name, values.operand), container_named_with_label),
    'runningContainerIdsLabelled': asking(reads(values.name), running_container_ids_labelled),
    'runningContainerIdsWithLabel': asking(reads(values.name, values.operand), running_container_ids_with_label),
    'stoppedContainerIdsLabelled': asking(reads(values.name), stopped_container_ids_labelled),
    'containersLabelled': asking(reads(values.name), containers_labelled),
    'inspect': asking(reads(rest(values.name)), inspect),
    'isRunning': asking(reads(values.name), is_running),
    'startedAt': asking(reads(values.name), started_at),
    'nanoCpus': asking(reads(values.name), nano_cpus),
    'containerNetworks': asking(reads(values.name), container_networks),
    'cpuPercents': asking(reads(rest(values.name)), cpu_percents),
    'runningEnvironment': asking(reads(values.name), running_environment),
    'limitMemory': asking(reads(values.name, values.positive), limit_memory),
    'limitCpu': asking(reads(values.name, values.cpus), limit_cpu),
    'restart': asking(reads(values.name), restart, seconds=CONTAINER_SECONDS),
    'forceRemoveContainer': asking(reads(values.name), force_remove, seconds=CONTAINER_SECONDS),
    'forceRemoveContainers': asking(lambda given: tuple(given.rest(values.name)), force_remove,
                                    seconds=CONTAINER_SECONDS),
    'networkSubnetsAndGateways': asking(reads(values.name), network_subnets_and_gateways),
    'networkId': asking(reads(values.name), network_id),
    'createBridgeNetwork': asking(reads(values.name, values.name, values.name), create_bridge_network),
    'connectNetwork': asking(reads(values.name, values.name), connect_network),
    'disconnectNetwork': asking(reads(values.name, values.name), disconnect_network),
    'registryGarbageCollect': asking(reads(registry_container, values.path), registry_garbage_collect),
    'registryTagLinks': asking(reads(registry_container, values.path), registry_tag_links),
    'removeRegistryRepository': asking(
        reads(registry_container, values.path, registry_repository), remove_registry_repository,
    ),
    'registryStorageKilobytes': asking(reads(registry_container), registry_storage_kilobytes),
    'wipeRegistryStorage': asking(reads(registry_container), wipe_registry_storage),
}
