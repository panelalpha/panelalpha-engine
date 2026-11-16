"""The members of the accounts' network and each container's port, from the host's own netlink, for its firewall script.

A port is the host link on the network's bridge whose peer is in the container's netns, so no container is entered.
Everything reads the process's own network namespace, so each caller enters the host's first.
"""

import http.client
import ipaddress
import json
import os
import re
import socket
import struct
import subprocess
import sys
import time
import urllib.parse

from . import egress, hostdaemon, hostns, scriptrun

NETWORK = 'pash-tenants'
BRIDGE = 'br-pa-tenants'
MTU = '1500'
ROLE = 'com.panelalpha.role'
SCRIPT = '/opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh'
LIMITS = 'tenant-egress-limits.sh'
WITHOUT_LIMITS = '--without-limits'
HOST_SCRIPT = '/etc/s6/root-broker/.lib/host-script.sh'
CHECK_HOST_SCRIPT = '. "$0" && check_host_script "$@"'
RECHECK_HOST_SCRIPT = '. "$0" && recheck_host_script "$@"'
RUN_HOST_SCRIPT = '. "$0" && run_host_script "$@"'
FAILED = '/opt/panelalpha/log/tenant-network-firewall.failed'
LIMITS_FAILED = '/opt/panelalpha/log/tenant-egress-limits.failed'
TAG = 'tenant-network'
SUBJECT = 'the tenant firewall'
LIMITS_SUBJECT = 'the outbound limits script'
LOST = 'an account container Docker starts on its own gets no network until the engine starts it'
LIMITS_LOST = 'new outbound connections of projects and builds are not limited; the tenant firewall still applies'
MINUTE = 60
NETLINK_SECONDS = 5
RECEIVING = 1 << 16
LINE_LIMIT = 1 << 16
NAME = re.compile(r'[a-zA-Z0-9][a-zA-Z0-9_.-]*')
MAC = re.compile(r'(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}')
INTERFACE = re.compile(r'[a-zA-Z0-9_.-]{1,15}')

NETLINK_ROUTE = 0
RTM_NEWLINK = 16
RTM_GETLINK = 18
RTM_NEWNSID = 88
RTM_GETNSID = 90
NLM_F_REQUEST = 1
NLM_F_DUMP = 0x300
NLMSG_ERROR = 2
NLMSG_DONE = 3
NETNSA_NSID = 1
NETNSA_FD = 3
IFLA_IFNAME = 3
IFLA_MASTER = 10
IFLA_LINK_NETNSID = 37
NOT_ASSIGNED = -1
SEQ = 1

NLMSGHDR = struct.Struct('IHHII')
IFINFOMSG = struct.Struct('BBHiII')
RTGENMSG = struct.Struct('Bxxx')
NLATTR = struct.Struct('HH')


class Interface:
    """One interface of the namespace a link dump was made in: its index, name, bridge and its peer's nsid."""

    def __init__(self, index, name, master, netnsid):
        self.index = index
        self.name = name
        self.master = master
        self.netnsid = netnsid


def message(kind, flags, body):
    """One netlink request."""
    return NLMSGHDR.pack(NLMSGHDR.size + len(body), kind, flags, SEQ, 0) + body


def attribute(kind, value):
    """One netlink attribute, padded as the kernel aligns them."""
    return NLATTR.pack(NLATTR.size + len(value), kind) + value + bytes(-len(value) % 4)


def dump_request():
    """RTM_GETLINK without a name: every interface of this network namespace."""
    return message(RTM_GETLINK, NLM_F_REQUEST | NLM_F_DUMP, IFINFOMSG.pack(socket.AF_UNSPEC, 0, 0, 0, 0, 0))


def nsid_request(fd):
    """RTM_GETNSID for the network namespace open at `fd`: the id this namespace has for it."""
    body = RTGENMSG.pack(socket.AF_UNSPEC) + attribute(NETNSA_FD, struct.pack('I', fd))
    return message(RTM_GETNSID, NLM_F_REQUEST, body)


def received(data):
    """The `(type, body)` of each message in one datagram, up to a malformed length."""
    at = 0
    while at + NLMSGHDR.size <= len(data):
        length, kind = NLMSGHDR.unpack_from(data, at)[:2]
        if length < NLMSGHDR.size or at + length > len(data):
            return
        yield kind, data[at + NLMSGHDR.size:at + length]
        at += (length + 3) & ~3


def attributes(body):
    """The attributes at the start of one message body, by type."""
    found = {}
    at = 0
    while at + NLATTR.size <= len(body):
        length, kind = NLATTR.unpack_from(body, at)
        if length < NLATTR.size:
            return found
        found[kind] = body[at + NLATTR.size:at + length]
        at += (length + 3) & ~3
    return found


def number(value):
    """A `u32` attribute's value, or None where the message has none."""
    return struct.unpack('I', value[:4])[0] if value is not None and len(value) >= 4 else None


def signed(value):
    """A signed `i32` attribute's value, or None where the message has none."""
    return struct.unpack('i', value[:4])[0] if value is not None and len(value) >= 4 else None


def failure(body):
    """The `OSError` a NLMSG_ERROR message carries, or None for its acknowledgement."""
    code = struct.unpack_from('i', body)[0]
    return None if code == 0 else OSError(-code, os.strerror(-code))


def talk(request, ends, seconds=NETLINK_SECONDS):
    """The `(type, body)` of each message the kernel sends for one request, up to one of the `ends` types."""
    with socket.socket(socket.AF_NETLINK, socket.SOCK_RAW, NETLINK_ROUTE) as sock:
        sock.settimeout(seconds)
        sock.bind((0, 0))
        sock.send(request)
        answers = []
        while True:
            for kind, body in received(sock.recv(RECEIVING)):
                error = failure(body) if kind == NLMSG_ERROR else None
                if error is not None:
                    raise error
                answers.append((kind, body))
                if kind in ends:
                    return answers


def interface(body):
    """One RTM_NEWLINK message as an `Interface`, or None for a name the script could not read on a line."""
    index = IFINFOMSG.unpack_from(body)[3]
    fields = attributes(body[IFINFOMSG.size:])
    name = fields.get(IFLA_IFNAME, b'').rstrip(b'\0').decode('utf-8', 'replace')
    if not INTERFACE.fullmatch(name):
        return None
    return Interface(index, name, number(fields.get(IFLA_MASTER)), signed(fields.get(IFLA_LINK_NETNSID)))


def host_links():
    """The host's interfaces, from a dump made in this network namespace; the dump is also what makes the kernel
    number the namespaces of the ports' peers."""
    links = []
    for kind, body in talk(dump_request(), (NLMSG_DONE,)):
        link = interface(body) if kind == RTM_NEWLINK else None
        if link is not None:
            links.append(link)
    return links


def numbered(body):
    """The id in one RTM_NEWNSID answer, or None while the kernel has numbered no such namespace."""
    value = signed(attributes(body[RTGENMSG.size:]).get(NETNSA_NSID))
    return None if value is None or value == NOT_ASSIGNED else value


def namespace_id(pid, proc='/proc'):
    """The id this network namespace gives the one of process `pid`, or None when it has none to give."""
    try:
        fd = os.open(f'{proc}/{pid}/ns/net', os.O_RDONLY | os.O_CLOEXEC)
    except OSError:
        return None
    try:
        (_, answer), = talk(nsid_request(fd), (RTM_NEWNSID,))
        return numbered(answer)
    finally:
        os.close(fd)


def endpoint(described):
    """What the script needs of one attached container: its name, MAC and address, each None when Docker did not say."""
    if not isinstance(described, dict):
        return None, None, None
    name, mac, address = (described.get(field) for field in ('Name', 'MacAddress', 'IPv4Address'))
    if not isinstance(name, str) or not NAME.fullmatch(name) or not isinstance(mac, str) or not MAC.fullmatch(mac):
        return None, None, None
    try:
        return name, mac.lower(), str(ipaddress.IPv4Address(address.partition('/')[0]))
    except (AttributeError, ValueError):
        return None, None, None


def pid(daemon, container):
    """The host pid of one container on the network, or None while it is not running."""
    state = (daemon.json('GET', f'/containers/{urllib.parse.quote(container)}/json') or {}).get('State') or {}
    running = state.get('Pid')
    return running if state.get('Running') is True and isinstance(running, int) and running > 0 else None


def attached(daemon, network):
    """`(name, mac, address, pid)` per container the host's daemon lists on the network, the pid while it runs."""
    found = network.get('Containers') or {}
    members = []
    for container, described in sorted(found.items()):
        name, mac, address = endpoint(described)
        if name is not None:
            members.append((name, mac, address, pid(daemon, container)))
    return members


def port(links, bridge, running, proc='/proc'):
    """The one link on the bridge whose peer is the namespace of `running`, or None for none and for several."""
    wanted = namespace_id(running, proc)
    if bridge is None or wanted is None:
        return None
    found = [link.name for link in links if link.master == bridge and link.netnsid == wanted]
    return found[0] if len(found) == 1 else None


def lines(daemon, network, links=None, proc='/proc'):
    """The lines the script reads, newline-terminated: the network's first subnet, its members and their ports."""
    interfaces = host_links() if links is None else links
    bridge = next((link.index for link in interfaces if link.name == BRIDGE), None)
    written = [f'subnet {subnet}\n'.encode() for subnet in hostdaemon.subnets(network)[:1]]
    for name, mac, address, running in attached(daemon, network):
        written.append(f'member {name} {address}\n'.encode())
        found = port(interfaces, bridge, running, proc) if running is not None else None
        if found is not None:
            written.append(f'port {found} {mac} {address}\n'.encode())
    written.extend(f'source {name}\n'.encode() for name in egress.bridges(daemon))
    return b''.join(written)


def firewall(daemon, output, script=SCRIPT, flags=()):
    """`sh <script> --ports-on-stdin <flags>` on the lines, the network made first when it is missing: the output and
    exit code are the action's."""
    network = hostdaemon.network(daemon, NETWORK)
    if network is None:
        code = create(daemon, output, script)
        if code != 0:
            return code
        network = hostdaemon.network(daemon, NETWORK) or {}
    return scriptrun.relayed(output, scriptrun.ran(['sh', script, '--ports-on-stdin', *flags], lines(daemon, network)))


def create(daemon, output, script):
    """The network made through the host's Engine API on the prefix `--prefix` picks among the host's subnets; the
    script's own words and code when it picks none."""
    used = b''.join(f'network {subnet}\n'.encode() for subnet in hostdaemon.every_subnet(daemon))
    chosen = scriptrun.ran(['sh', script, '--prefix'], used)
    output.stderr(chosen.stderr)
    if chosen.returncode != 0:
        output.stdout(chosen.stdout)
        return chosen.returncode
    prefix = chosen.stdout.decode(errors='replace').strip()
    daemon.json('POST', '/networks/create', missing=(), body=creation(prefix))
    output.stdout(f'tenant-network-firewall: created {NETWORK} on {prefix}.0.0/16\n'.encode())
    return 0


def creation(prefix):
    """What `docker network create` sends for the network on `prefix`: accounts get addresses from the upper half
    only, the bridge has the network's own name, its ports do not reach each other, and the MTU is Docker's default."""
    return {
        'Name': NETWORK,
        'Driver': 'bridge',
        'IPAM': {'Driver': 'default', 'Options': {}, 'Config': [
            {'Subnet': f'{prefix}.0.0/16', 'Gateway': f'{prefix}.0.1', 'IPRange': f'{prefix}.128.0/17'},
        ]},
        'Options': {
            hostdaemon.BRIDGE_NAME: BRIDGE,
            'com.docker.network.bridge.enable_icc': 'false',
            'com.docker.network.driver.mtu': MTU,
        },
        'Labels': {ROLE: 'tenants'},
    }


def host_script(program, script, *command, lines=b''):
    """`program`, one of the fixed shell programs above, on host-script.sh with the tenant firewall's values, each
    one an argument of the shell and none of its program."""
    return subprocess.run(
        ['sh', '-c', program, HOST_SCRIPT, TAG, SUBJECT, LOST, FAILED, script, *command],
        input=lines, stdout=subprocess.DEVNULL,
    )


def limits_flags(script):
    """No flags when the limits script beside `script` may run as root, else the one that skips it. host-script.sh
    logs a refusal, and that it applies again, as it does the firewall's."""
    limits = os.path.join(os.path.dirname(script), LIMITS)
    checked = subprocess.run(
        ['sh', '-c', RECHECK_HOST_SCRIPT, HOST_SCRIPT, TAG, LIMITS_SUBJECT, LIMITS_LOST, LIMITS_FAILED, limits],
        stdout=subprocess.DEVNULL,
    )
    return () if checked.returncode == 0 else (WITHOUT_LIMITS,)


def applied(daemon, script=SCRIPT):
    """Runs the script on the lines through run_host_script while the network is there. The check comes first, so a
    refusal is reported even with no network or daemon; a failure waits for the next round. A limits script that may
    not run is left out, and the firewall runs without it."""
    if host_script(CHECK_HOST_SCRIPT, script).returncode != 0:
        return
    try:
        network = hostdaemon.network(daemon, NETWORK)
        if network is None:
            return
        command = ('sh', script, '--ports-on-stdin', *limits_flags(script))
        host_script(RUN_HOST_SCRIPT, script, *command, lines=lines(daemon, network))
        egress.reconcile(egress.members(daemon, network))
    except hostdaemon.UNANSWERED:
        pass
    except (OSError, ValueError, subprocess.SubprocessError) as failed:
        print(f'{TAG}: the outbound limits\' marks were not read ({failed})', file=sys.stderr)


def event(response):
    """True while the events stream carries a line, False when it ended or stayed quiet for the connection's bound."""
    try:
        return bool(response.readline(LINE_LIMIT))
    except TimeoutError:
        return False


def watched(daemon):
    """The daemon's events of a container joining or leaving the accounts' network or a build network."""
    names = [NETWORK, *(network.get('Name') for network in egress.build_networks(daemon))]
    return '/events?' + urllib.parse.urlencode({'filters': json.dumps({
        'type': ['network'], 'network': [name for name in names if isinstance(name, str)],
        'event': ['connect', 'disconnect'],
    })})


def events(daemon, script=SCRIPT, seconds=MINUTE):
    """Applies on every attach and detach the daemon streams; False when it does not stream them at all."""
    try:
        conn, response = daemon.stream('GET', watched(daemon), seconds)
    except hostdaemon.UNANSWERED:
        return False
    try:
        if response.status != 200:
            return False
        while event(response):
            applied(daemon, script)
    except (OSError, http.client.HTTPException):
        pass
    finally:
        conn.close()
    return True


def serve(seconds=MINUTE):
    """Applies at start, on every attach or detach of the network, and in full every `seconds`."""
    hostns.enter(only=('net',))
    daemon = hostdaemon.open_host()
    while True:
        applied(daemon)
        if not events(daemon, seconds=seconds):
            time.sleep(seconds)


def main(arguments):
    if arguments != ['--serve']:
        print('usage: python3 -m rootbroker.tenantports --serve', file=sys.stderr)
        return 2
    serve()
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
