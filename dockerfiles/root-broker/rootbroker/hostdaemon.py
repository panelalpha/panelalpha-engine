"""The host's own dockerd through its Engine API, for the actions on the engine's containers, images and networks.

The daemon is root's, so its replies are the host's own; each is still read up to a size limit. The engine's
services are the containers of the broker's own compose project.
"""

import http.client
import ipaddress
import json
import urllib.parse

from . import dockerapi, peer
from .dockerapi import Failed, Unavailable, quoted

SOCKET = dockerapi.SOCKET
NAME = 'the host'
SECONDS = 30
CANNOT_CONNECT = f'Cannot connect to the Docker daemon at unix://{SOCKET}. Is the docker daemon running?'
CONTAINER_NUMBER = 'com.docker.compose.container-number'
BRIDGE_NAME = 'com.docker.network.bridge.name'
UNANSWERED = (Failed, Unavailable, OSError, http.client.HTTPException)


WORDING = dockerapi.Wording(
    too_large="the host's Docker answered {path} with {error}",
    refused=CANNOT_CONNECT,
    silent="the host's Docker did not answer within {seconds}s",
)


def open_host(seconds=SECONDS, socket_path=None):
    """The host's daemon at its socket, failing in the docker CLI's words."""
    return dockerapi.Daemon(NAME, socket_path or SOCKET, seconds, WORDING)


def own_container():
    try:
        return peer.own_container(peer.MOUNTINFO)
    except OSError:
        return None


OWN = own_container()


def engine_project(daemon, own=None):
    """The compose project of the broker's own container, read from its mounts when the broker loads, since a request
    in the host's namespaces sees the host's."""
    own = own or OWN
    project = dockerapi.labels(daemon.json('GET', f'/containers/{own}/json')).get(dockerapi.PROJECT) if own else None
    if not isinstance(project, str) or project == '':
        raise Failed('the root broker could not tell which compose project it runs in')
    return project


def engine_service(daemon, service):
    """The id of the engine service's running container as `docker compose exec` picks it: the first by number, a
    one-off `run` container only when none of the service's own runs."""
    filters = json.dumps(dockerapi.compose_filters(engine_project(daemon), service, one_offs=True))
    found = daemon.json('GET', '/containers/json?' + urllib.parse.urlencode({'filters': filters}), missing=())
    running = [c for c in (found if isinstance(found, list) else ()) if isinstance((c or {}).get('Id'), str)]
    if not running:
        raise Failed(f'service "{service}" is not running')
    return min(running, key=compose_rank)['Id']


def compose_rank(container):
    """Compose's order for `exec`: the service's containers by number, a number it cannot read as 0, one-offs last."""
    labels = container.get('Labels') or {}
    if labels.get(dockerapi.ONEOFF) == 'True':
        return 1, 0
    try:
        return 0, int(labels.get(CONTAINER_NUMBER, ''))
    except ValueError:
        return 0, 0


def network(daemon, name):
    """`/networks/{name}`, None while the daemon has no such network."""
    found = daemon.json('GET', f'/networks/{quoted(name)}')
    return found if isinstance(found, dict) else None


def subnets(network):
    """The subnets the network's IPAM names, in its order, as `{{range .IPAM.Config}}{{.Subnet}} {{end}}` prints them;
    one that is not a network is left out."""
    ipam = network.get('IPAM')
    configs = ipam.get('Config') if isinstance(ipam, dict) else None
    return [config['Subnet'] for config in (configs if isinstance(configs, list) else ())
            if isinstance(config, dict) and is_network(config.get('Subnet'))]


def every_subnet(daemon):
    """Every subnet of the daemon's networks, sorted and each once."""
    found = daemon.json('GET', '/networks', missing=())
    return sorted({subnet for listed in (found if isinstance(found, list) else ()) if isinstance(listed, dict)
                   for subnet in subnets(listed)})


def is_network(value):
    try:
        ipaddress.ip_network(value, strict=False)
    except (TypeError, ValueError):
        return False
    return True
