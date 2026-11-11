"""`rootbroker.tenantports` and the script it feeds: netlink bytes and replies, members, their lines, the nft binding.

The netlink numbers are the kernel's own, written out again so a change in the module cannot move them unnoticed.
"""

import json
import os
import shutil
import struct
import subprocess
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import hostdaemon, tenantports
from rootbroker.actions import Policy
from test_account_docker import serve

SCRIPT = os.path.join(support.HERE, '..', '..', '..', 'scripts', 'tenant-network-firewall.sh')
NETWORK = 'pash-tenants'
BRIDGE = 'br-pa-tenants'
PREFIX = '10.200'
SITES_DB = PREFIX + '.0.2'
ALICE = PREFIX + '.128.1'
BOB = PREFIX + '.128.2'
RUNNING = 'a' * 64
OTHER = 'b' * 64
STOPPED = 'c' * 64
BRIDGE_INDEX = 5

RTM_NEWLINK = 16
RTM_GETLINK = 18
RTM_GETNSID = 90
NLM_F_REQUEST = 1
NLM_F_DUMP = 0x300
NLMSG_DONE = 3
NETNSA_NSID = 1
NETNSA_FD = 3
IFLA_IFNAME = 3
IFLA_MASTER = 10
IFLA_LINK_NETNSID = 37
NLMSG_HDR = struct.Struct('IHHII')
IFINFO = struct.Struct('BBHiII')
RTGEN = struct.Struct('Bxxx')


def nlmsg(kind, body, flags=NLM_F_REQUEST, seq=1):
    """One netlink message as the kernel writes and reads them."""
    return NLMSG_HDR.pack(NLMSG_HDR.size + len(body), kind, flags, seq, 0) + body


def nlattr(kind, value):
    return struct.pack('HH', 4 + len(value), kind) + value + bytes(-len(value) % 4)


def newlink(name, index, master=None, netnsid=None):
    fields = nlattr(IFLA_IFNAME, name.encode() + b'\0')
    fields += nlattr(IFLA_MASTER, struct.pack('I', master)) if master is not None else b''
    fields += nlattr(IFLA_LINK_NETNSID, struct.pack('i', netnsid)) if netnsid is not None else b''
    return nlmsg(RTM_NEWLINK, IFINFO.pack(0, 0, 1, index, 0, 0) + fields)


def recorded_dump():
    """A dump as the host's kernel sends it: the loopback, the bridge, its ports and a port of another network."""
    return b''.join([
        newlink('lo', 1),
        newlink(BRIDGE, BRIDGE_INDEX),
        newlink('vethcore0', 9, master=2, netnsid=7),
        newlink('veth03d7e26', 12, master=BRIDGE_INDEX, netnsid=3),
        nlmsg(NLMSG_DONE, struct.pack('i', 0)),
    ])


def network(attached, subnets=()):
    """The `/networks/{id}` reply for the containers given as `(id, name, mac, address)`, on the subnets given."""
    configs = [{'Subnet': subnet} for subnet in subnets]
    return {'Name': NETWORK, 'Id': NETWORK, 'IPAM': {'Config': configs}, 'Containers': {
        container: {'Name': name, 'MacAddress': mac, 'IPv4Address': address, 'EndpointID': container}
        for container, name, mac, address in attached
    }}


class NetlinkTest(unittest.TestCase):
    """The requests the module sends and the replies it reads."""

    def test_the_dump_request_is_the_one_the_kernel_reads(self):
        body = IFINFO.pack(0, 0, 0, 0, 0, 0)
        self.assertEqual(tenantports.dump_request(), nlmsg(RTM_GETLINK, body, NLM_F_REQUEST | NLM_F_DUMP))

    def test_the_nsid_request_names_the_namespace_only_by_its_file_descriptor(self):
        body = RTGEN.pack(0) + nlattr(NETNSA_FD, struct.pack('I', 7))
        self.assertEqual(tenantports.nsid_request(7), nlmsg(RTM_GETNSID, body))

    def test_a_recorded_dump_is_read_as_the_host_s_interfaces(self):
        links = [tenantports.interface(body) for kind, body in tenantports.received(recorded_dump())
                 if kind == RTM_NEWLINK]
        self.assertEqual([(link.index, link.name, link.master, link.netnsid) for link in links], [
            (1, 'lo', None, None),
            (BRIDGE_INDEX, BRIDGE, None, None),
            (9, 'vethcore0', 2, 7),
            (12, 'veth03d7e26', BRIDGE_INDEX, 3),
        ])
        self.assertEqual([kind for kind, _ in tenantports.received(recorded_dump())][-1], NLMSG_DONE)

    def test_a_reply_carries_the_id_the_host_has_for_the_namespace(self):
        self.assertEqual(tenantports.numbered(RTGEN.pack(0) + nlattr(NETNSA_NSID, struct.pack('i', 3))), 3)
        self.assertIsNone(tenantports.numbered(RTGEN.pack(0) + nlattr(NETNSA_NSID, struct.pack('i', -1))))


class MembersTest(unittest.TestCase):
    """The members from a fake host daemon, and the lines each one gets."""

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-ports-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, [])
        self.ids = {}
        self.saved = tenantports.namespace_id
        tenantports.namespace_id = self.namespace_id

    def tearDown(self):
        tenantports.namespace_id = self.saved
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def namespace_id(self, pid, proc='/proc'):
        return self.ids.get(pid)

    def client(self):
        return hostdaemon.open_host(socket_path=self.daemon_socket)

    def attached(self):
        client = self.client()
        return tenantports.attached(client, hostdaemon.network(client, NETWORK))

    def lines(self, links):
        client = self.client()
        return tenantports.lines(client, hostdaemon.network(client, NETWORK), links=links)

    def running(self, container, pid=4242):
        self.routes[('GET', f'/containers/{container}/json')] = (200, {'State': {'Running': True, 'Pid': pid}})

    def test_the_members_are_the_containers_the_daemon_lists_on_the_network(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([
            (RUNNING, 'alice', '26:E3:F2:D4:B6:68', ALICE + '/16'),
            (STOPPED, 'stopped', '02:00:00:00:00:09', BOB + '/16'),
        ]))
        self.running(RUNNING)
        self.routes[('GET', f'/containers/{STOPPED}/json')] = (200, {'State': {'Running': False, 'Pid': 0}})
        self.assertEqual(self.attached(), [
            ('alice', '26:e3:f2:d4:b6:68', ALICE, 4242),
            ('stopped', '02:00:00:00:00:09', BOB, None),
        ])

    def test_a_member_whose_name_or_mac_no_line_can_carry_is_left_out(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, {'Containers': {
            RUNNING: {'Name': 'with space', 'MacAddress': '26:e3:f2:d4:b6:68', 'IPv4Address': ALICE},
            OTHER: {'Name': 'alice', 'MacAddress': 'not a mac', 'IPv4Address': ALICE},
        }})
        self.assertEqual(self.attached(), [])

    def test_the_lines_name_the_members_and_the_one_port_of_each(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([
            (RUNNING, 'sites-db', '9a:2a:26:9f:10:1c', SITES_DB + '/16'),
            (OTHER, 'alice', '26:e3:f2:d4:b6:68', ALICE + '/16'),
        ]))
        self.running(RUNNING, pid=4242)
        self.running(OTHER, pid=4343)
        self.ids.update({4242: 3, 4343: 4})
        links = [tenantports.interface(body) for kind, body in tenantports.received(recorded_dump())
                 if kind == RTM_NEWLINK]
        self.assertEqual(self.lines(links), (
            f'member sites-db {SITES_DB}\nport veth03d7e26 9a:2a:26:9f:10:1c {SITES_DB}\n'
            f'member alice {ALICE}\n'
        ).encode())

    def test_the_network_s_first_subnet_leads_the_lines(self):
        subnets = ('', PREFIX + '.0.0/16', 'fd00:1::/64')
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([], subnets))
        self.assertEqual(self.lines([]), f'subnet {PREFIX}.0.0/16\n'.encode())
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([], subnets=('not a subnet',)))
        self.assertEqual(self.lines([]), b'')

    def test_a_member_with_two_ports_on_the_bridge_is_bound_to_neither(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([
            (RUNNING, 'alice', '26:e3:f2:d4:b6:68', ALICE + '/16'),
        ]))
        self.running(RUNNING)
        self.ids.update({4242: 4})
        links = [tenantports.interface(body) for kind, body in tenantports.received(
            newlink('veth4d66117', 11, master=BRIDGE_INDEX, netnsid=4) + newlink('vethdead', 13, master=BRIDGE_INDEX,
                                                                               netnsid=4))
                 if kind == RTM_NEWLINK]
        self.assertEqual(self.lines(links), f'member alice {ALICE}\n'.encode())

    def test_a_member_whose_container_is_not_running_is_named_but_bound_to_no_port(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([
            (RUNNING, 'alice', '26:e3:f2:d4:b6:68', ALICE + '/16'),
        ]))
        self.running(RUNNING, pid=0)
        self.assertEqual(self.lines([]), f'member alice {ALICE}\n'.encode())


class ActionTest(support.Serving):
    """`host.applyTenantNetworkFirewall`: the script run in the host's namespaces, the lines on its stdin, and a
    missing network made through the daemon."""

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-firewall-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.routes = {}
        self.sent = []
        self.routes[('GET', '/networks')] = (200, [])
        self.daemon = serve(self.daemon_socket, self.answer, [], self.sent)
        self.argv_file = os.path.join(self.scratch, 'script.argv')
        self.stdin_file = os.path.join(self.scratch, 'script.stdin')
        self.saved = (hostdaemon.SOCKET, tenantports.host_links, tenantports.namespace_id)
        hostdaemon.SOCKET = self.daemon_socket
        tenantports.host_links = lambda: [tenantports.interface(body) for kind, body in tenantports.received(
            recorded_dump()) if kind == tenantports.RTM_NEWLINK]
        tenantports.namespace_id = lambda pid, proc='/proc': {4242: 3}.get(pid)
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET, tenantports.host_links, tenantports.namespace_id = self.saved
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        if (method, route) == ('POST', '/networks/create') and (method, route) not in self.routes:
            body = json.loads(self.sent[-1][2])
            self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([], [body['IPAM']['Config'][0]['Subnet']]))
            return 201, {'Id': 'n' * 64, 'Warning': ''}
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def created(self):
        return [json.loads(body) for method, path, body, _ in self.sent if path == '/networks/create']

    def runs(self):
        with open(self.argv_file) as f:
            return f.read()

    def broker_policy(self):
        return Policy(self.roots, accounts_db=self.accounts, become=self.become,
                      owners={os.getuid()}, script_owners={os.getuid()})

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        with open(os.path.join(self.base, 'entered'), 'w') as f:
            f.write(str(namespaces))

    def stand_in(self, picked=0):
        """The engine's script, as a shell script that keeps each run's argv and stdin and says what it did: `--prefix`
        picks 10.201, exiting `picked`."""
        self.make('engine', 'scripts/tenant-network-firewall.sh', (
            '#!/bin/sh\n'
            f'echo "$*" >> "{self.argv_file}"\n'
            f'cat >> "{self.stdin_file}"\n'
            f'[ "$1" = --prefix ] && {{ echo 10.201; echo picked >&2; exit {picked}; }}\n'
            'echo applied\n'
            'echo refused >&2\n'
            'exit 3\n'
        ).encode())
        os.chmod(self.path('engine', 'scripts/tenant-network-firewall.sh'), 0o755)

    def test_the_script_runs_in_the_host_s_namespaces_on_the_lines_their_ports_are_named_on(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([
            (RUNNING, 'alice', '26:e3:f2:d4:b6:68', ALICE + '/16'),
            (OTHER, 'bob', '26:e3:f2:d4:b6:69', BOB + '/16'),
        ], [PREFIX + '.0.0/16']))
        self.routes[('GET', f'/containers/{RUNNING}/json')] = (200, {'State': {'Running': True, 'Pid': 4242}})
        self.routes[('GET', f'/containers/{OTHER}/json')] = (200, {'State': {'Running': True, 'Pid': 4343}})
        self.stand_in()

        result = self.pa_root('host.applyTenantNetworkFirewall', host=True)

        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'applied\n', b'refused\n'))
        self.assertEqual(self.runs(), '--ports-on-stdin\n')
        with open(self.stdin_file) as f:
            self.assertEqual(f.read(), f'subnet {PREFIX}.0.0/16\nmember alice {ALICE}\n'
                                       f'port veth03d7e26 26:e3:f2:d4:b6:68 {ALICE}\nmember bob {BOB}\n')
        with open(os.path.join(self.base, 'entered')) as f:
            self.assertEqual(f.read(), 'None')
        self.assertEqual(self.created(), [])

    def test_a_missing_network_is_made_through_the_daemon_on_the_prefix_the_script_picks(self):
        self.routes[('GET', '/networks')] = (200, [
            {'Name': 'bridge', 'IPAM': {'Config': [{'Subnet': '172.17.0.0/16', 'Gateway': '172.17.0.1'}]}},
            {'Name': 'host', 'IPAM': {'Config': []}},
            {'Name': 'dual', 'IPAM': {'Config': [{'Subnet': 'fd00:1::/64'}, {'Subnet': '10.200.0.0/20'}]}},
        ])
        self.stand_in()

        result = self.pa_root('host.applyTenantNetworkFirewall', host=True)

        self.assertEqual((result.returncode, result.stdout, result.stderr), (
            3, b'tenant-network-firewall: created pash-tenants on 10.201.0.0/16\napplied\n', b'picked\nrefused\n'))
        self.assertEqual(self.runs(), '--prefix\n--ports-on-stdin\n')
        with open(self.stdin_file) as f:
            self.assertEqual(f.read(), 'network 10.200.0.0/20\nnetwork 172.17.0.0/16\nnetwork fd00:1::/64\n'
                                       'subnet 10.201.0.0/16\n')
        self.assertEqual(self.created(), [{
            'Name': NETWORK, 'Driver': 'bridge',
            'IPAM': {'Driver': 'default', 'Options': {}, 'Config': [
                {'Subnet': '10.201.0.0/16', 'Gateway': '10.201.0.1', 'IPRange': '10.201.128.0/17'},
            ]},
            'Options': {'com.docker.network.bridge.name': BRIDGE, 'com.docker.network.bridge.enable_icc': 'false',
                        'com.docker.network.driver.mtu': '1500'},
            'Labels': {'com.panelalpha.role': 'tenants'},
        }])

    def test_no_prefix_picked_leaves_the_network_unmade_with_the_script_s_words_and_code(self):
        self.stand_in(picked=1)

        result = self.pa_root('host.applyTenantNetworkFirewall', host=True)

        self.assertEqual((result.returncode, result.stdout, result.stderr), (1, b'10.201\n', b'picked\n'))
        self.assertEqual(self.runs(), '--prefix\n')
        self.assertEqual(self.created(), [])

    def test_a_network_the_daemon_will_not_make_fails_in_its_words(self):
        self.routes[('POST', '/networks/create')] = (409, {'message': 'network with name pash-tenants already exists'})
        self.stand_in()

        result = self.pa_root('host.applyTenantNetworkFirewall', host=True)

        self.assertEqual((result.returncode, result.stderr), (
            1, b'picked\nError response from daemon: network with name pash-tenants already exists\n'))
        self.assertEqual(self.runs(), '--prefix\n')

    def test_a_missing_script_fails_with_sh_s_own_words_and_status(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([]))

        result = self.pa_root('host.applyTenantNetworkFirewall', host=True)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'cannot open', result.stderr)
        self.assertIn(b'tenant-network-firewall.sh', result.stderr)


class AppliedTest(unittest.TestCase):
    """The service's round: the script on the lines while the network is there, and not run while it is missing."""

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-applied-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, [])
        self.script = os.path.join(self.scratch, 'tenant-network-firewall.sh')
        self.stdin_file = os.path.join(self.scratch, 'script.stdin')
        with open(self.script, 'w') as f:
            f.write(f'cat > "{self.stdin_file}"\n')
        library = os.path.join(self.scratch, 'host-script.sh')
        with open(library, 'w') as f:
            f.write('check_host_script() { :; }\nrun_host_script() { shift 5; "$@"; }\n')
        self.saved = (tenantports.HOST_SCRIPT, tenantports.host_links)
        tenantports.HOST_SCRIPT, tenantports.host_links = library, lambda: []

    def tearDown(self):
        tenantports.HOST_SCRIPT, tenantports.host_links = self.saved
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def applied(self):
        tenantports.applied(hostdaemon.open_host(socket_path=self.daemon_socket), self.script)

    def test_a_network_that_is_not_there_runs_no_script(self):
        self.applied()
        self.assertFalse(os.path.exists(self.stdin_file))

    def test_the_script_reads_the_network_s_subnet_off_the_lines(self):
        self.routes[('GET', f'/networks/{NETWORK}')] = (200, network([], [PREFIX + '.0.0/16']))
        self.applied()
        with open(self.stdin_file) as f:
            self.assertEqual(f.read(), f'subnet {PREFIX}.0.0/16\n')


class FirewallScriptTest(unittest.TestCase):
    """The script itself, against stand-ins for the commands it runs."""

    def setUp(self):
        self.base = tempfile.mkdtemp(prefix='broker-script-')
        self.bin = os.path.join(self.base, 'bin')
        os.makedirs(self.bin)
        self.written = {'nft': os.path.join(self.base, 'nft.in'), 'restore': os.path.join(self.base, 'restore.in')}
        self.stub('docker', 'case "$*" in\n'
                            '*"network inspect -f"*) echo "' + PREFIX + '.0.0/16 " ;;\n'
                            '*"network inspect"*) exit 0 ;;\n'
                            'esac\nexit 1\n')
        self.stub('iptables', 'exit 0\n')
        self.stub('iptables-restore', f'cat >"{self.written["restore"]}"\n')
        self.stub('nft', f'cat >"{self.written["nft"]}"\n')
        self.api_access = os.path.join(self.base, 'api-access')
        self.environment = {
            **os.environ,
            'PATH': self.bin + os.pathsep + os.environ['PATH'],
            'PA_ENV_FILE': os.path.join(self.base, '.env'),
            'PA_TENANT_LOCK': os.path.join(self.base, 'lock'),
            'PA_API_ACCESS_FILE': self.api_access,
        }
        open(self.environment['PA_ENV_FILE'], 'w').close()

    def tearDown(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def stub(self, name, body):
        path = os.path.join(self.bin, name)
        with open(path, 'w') as f:
            f.write('#!/bin/sh\n' + body)
        os.chmod(path, 0o755)

    def run_script(self, *arguments, lines=b''):
        return subprocess.run(['sh', SCRIPT, *arguments], input=lines, capture_output=True,
                              env=self.environment, timeout=support.SECONDS)

    def written_text(self, name):
        with open(self.written[name]) as f:
            return f.read()

    def test_the_script_is_valid_shell(self):
        result = subprocess.run(['sh', '-n', SCRIPT], capture_output=True, timeout=support.SECONDS)
        self.assertEqual((result.returncode, result.stderr), (0, b''))

    def test_the_lines_bind_each_port_and_open_the_engine_port_for_the_account_they_name(self):
        with open(self.api_access, 'w') as f:
            f.write('alice\n')
        result = self.run_script('--ports-on-stdin', lines=(
            f'member sites-db {SITES_DB}\n'
            f'port veth03d7e26 9a:2a:26:9f:10:1c {SITES_DB}\n'
            f'member alice {ALICE}\n'
            f'port veth4d66117 26:e3:f2:d4:b6:68 {ALICE}\n'
            f'member bob {BOB}\n'
        ).encode())

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertIn('2 port(s) bound', result.stdout.decode())
        table = self.written_text('nft')
        self.assertIn(f'"veth03d7e26" . 9a:2a:26:9f:10:1c . 9a:2a:26:9f:10:1c . {SITES_DB}', table)
        self.assertIn(f'"veth4d66117" . 26:e3:f2:d4:b6:68 . {ALICE}', table)
        self.assertNotIn(BOB, table)
        rules = self.written_text('restore')
        self.assertIn(f'-A PA-TENANT-NET -i {BRIDGE} -s {ALICE}/32 -p tcp -m conntrack --ctstate DNAT '
                      '--ctorigdstport 2011 -j ACCEPT', rules)
        self.assertNotIn(f'-s {SITES_DB}/32 -p tcp -m conntrack', rules)

    def test_without_the_lines_the_bound_ports_are_kept_and_no_account_reaches_the_engine_port(self):
        with open(self.api_access, 'w') as f:
            f.write('alice\n')
        result = self.run_script()

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertIn('its port bindings kept', result.stdout.decode())
        table = self.written_text('nft')
        self.assertIn('flush table bridge pa_tenants', table)
        self.assertNotIn('delete table', table)
        self.assertNotIn('elements', table)
        self.assertIn('counter drop', table)
        self.assertNotIn('--ctorigdstport 2011', self.written_text('restore'))


class Stopped(Exception):
    """Ends the service loop of the test once it has done what the test watches for."""


class Stream:
    """A daemon's events stream: one event line, then quiet for as long as the connection's bound allowed."""

    def __init__(self, lines):
        self.lines = list(lines)
        self.status = 200
        self.closed = False

    def readline(self, limit):
        if self.lines:
            return self.lines.pop(0)
        raise TimeoutError

    def close(self):
        self.closed = True


class Connection:
    """The connection the daemon hands over with its response, which is what closing it closes."""

    def __init__(self, response):
        self.response = response

    def close(self):
        self.response.close()


class FakeDaemon:
    def __init__(self):
        self.streams = []

    def stream(self, method, path, seconds, headers=None):
        response = Stream([b'{"Type":"network","Action":"connect"}\n'])
        self.streams.append(response)
        return Connection(response), response


class Refusing:
    """A daemon that will not stream the network's events at all."""

    def stream(self, method, path, seconds, headers=None):
        raise ConnectionRefusedError


class ServeTest(unittest.TestCase):
    """`--serve`, bounded: the host's network namespace entered once, an apply at start, per event and when quiet."""

    def setUp(self):
        self.calls = []
        self.entered = []
        self.daemon = FakeDaemon()
        self.saved = (tenantports.hostns, hostdaemon.open_host, tenantports.applied, tenantports.time.sleep)
        tenantports.hostns = self
        hostdaemon.open_host = lambda seconds=hostdaemon.SECONDS: self.daemon
        tenantports.applied = self.applied
        tenantports.time.sleep = self.slept

    def tearDown(self):
        tenantports.hostns, hostdaemon.open_host, tenantports.applied, tenantports.time.sleep = self.saved

    def enter(self, only=None, target='/proc/1'):
        self.entered.append(only)

    def applied(self, daemon, script=tenantports.SCRIPT):
        self.calls.append(script)
        if len(self.calls) > 3:
            raise Stopped

    def slept(self, seconds):
        raise AssertionError(f'the loop slept for {seconds}s')

    def test_the_loop_applies_at_start_on_each_event_and_again_once_the_stream_has_been_quiet(self):
        with self.assertRaises(Stopped):
            tenantports.serve(seconds=1)

        self.assertEqual(self.entered, [('net',)])
        self.assertEqual(self.calls, [tenantports.SCRIPT] * 4)
        self.assertEqual(len(self.daemon.streams), 2)
        self.assertTrue(all(stream.closed for stream in self.daemon.streams))

    def test_a_daemon_that_will_not_stream_is_tried_again_after_the_wait(self):
        hostdaemon.open_host = lambda seconds=hostdaemon.SECONDS: Refusing()

        with self.assertRaises(AssertionError) as caught:
            tenantports.serve(seconds=1)

        self.assertIn('slept for 1s', str(caught.exception))
        self.assertEqual(len(self.calls), 1)

