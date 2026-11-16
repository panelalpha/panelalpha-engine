"""`host.applyBuildNetworkFirewall` and `firewall.applyTrustedLists`: the engine's firewall scripts run in the host's
namespaces with what the broker read of the host's Docker, and the build network script on the broker's lines."""

import os
import shutil
import subprocess
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import hostdaemon, runner
from rootbroker.actions import Policy, firewall
from test_account_docker import serve

BUILD_SCRIPT = os.path.join(support.HERE, '..', '..', '..', 'scripts', 'build-network-firewall.sh')
BUILD_NETWORK = 'panelalpha-build'
BUILD_ID = 'f34d1417449f4dc6ea14b952478362347203d796daa239d47097dc6145236a6d'
BUILD_SUBNET = '172.25.1.0/24'
NETWORKS = [
    {'Name': 'pash-tenants', 'IPAM': {'Config': [{'Subnet': '10.200.0.0/16', 'IPRange': '10.200.128.0/17'}]}},
    {'Name': 'bridge', 'IPAM': {'Config': [{'Subnet': '172.17.0.0/16'}, {'Subnet': 'fd00:1::/64'}]}},
    {'Name': 'host', 'IPAM': {'Config': []}},
    {'Name': 'again', 'IPAM': {'Config': [{'Subnet': '172.17.0.0/16'}]}},
]


class Scripted(support.Serving):
    """The broker over a fake host daemon, with an engine script that keeps its argv, stdin and environment."""

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-scripts-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.kept = os.path.join(self.scratch, 'kept')
        self.routes = {('GET', '/networks'): (200, NETWORKS)}
        self.daemon = serve(self.daemon_socket, self.answer, [])
        self.saved = hostdaemon.SOCKET
        hostdaemon.SOCKET = self.daemon_socket
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET = self.saved
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def broker_policy(self):
        return Policy(self.roots, accounts_db=self.accounts, become=self.become,
                      owners={os.getuid()}, script_owners={os.getuid()})

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        with open(os.path.join(self.base, 'entered'), 'w') as f:
            f.write(str(namespaces))

    def stand_in(self, path):
        """A script at `path` that keeps what it was given and says what it did."""
        with open(path, 'w') as f:
            f.write('#!/bin/sh\n'
                    f'{{ echo "argv $*"; echo "subnets ${{PA_DOCKER_SUBNETS-unset}}"; echo "path $PATH"; cat; }}'
                    f' > "{self.kept}"\n'
                    'echo applied\n'
                    'echo refused >&2\n'
                    'exit 3\n')
        os.chmod(path, 0o755)

    def given(self):
        with open(self.kept) as f:
            return f.read()


class BuildNetworkTest(Scripted):
    def setUp(self):
        super().setUp()
        self.stand_in(self.make('engine', 'scripts/build-network-firewall.sh'))
        self.make('engine', 'scripts/tenant-egress-limits.sh')

    def build_network(self, described):
        self.routes[('GET', f'/networks/{BUILD_NETWORK}')] = (200, {'Name': BUILD_NETWORK, 'Id': BUILD_ID, **described})

    def test_the_script_runs_in_the_host_s_namespaces_on_the_network_s_id_bridge_and_subnet(self):
        self.build_network({'Options': {'com.docker.network.bridge.name': 'br-pa-build'},
                            'IPAM': {'Config': [{'Subnet': BUILD_SUBNET, 'Gateway': '172.25.1.1'}]}})

        result = self.pa_root('host.applyBuildNetworkFirewall', BUILD_NETWORK, host=True)

        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'applied\n', b'refused\n'))
        self.assertEqual(self.given(), (
            f'argv --network-on-stdin {BUILD_NETWORK}\nsubnets unset\npath {runner.ENVIRONMENT["PATH"]}\n'
            f'id {BUILD_ID}\nbridge br-pa-build\nsubnet {BUILD_SUBNET}\n'
        ))
        with open(os.path.join(self.base, 'entered')) as f:
            self.assertEqual(f.read(), 'None')

    def test_a_network_without_a_bridge_name_or_a_subnet_is_named_by_its_id_alone(self):
        self.build_network({'Options': {}, 'IPAM': {'Config': []}})
        self.pa_root('host.applyBuildNetworkFirewall', BUILD_NETWORK, host=True)
        self.assertTrue(self.given().endswith(f'\nid {BUILD_ID}\n'))

    def test_a_network_that_is_not_there_gives_the_script_no_lines(self):
        self.pa_root('host.applyBuildNetworkFirewall', BUILD_NETWORK, host=True)
        self.assertTrue(self.given().endswith(f'path {runner.ENVIRONMENT["PATH"]}\n'))

    def test_a_daemon_that_does_not_answer_gives_the_script_no_lines_as_its_own_read_did(self):
        self.routes[('GET', f'/networks/{BUILD_NETWORK}')] = (500, {'message': 'boom'})
        result = self.pa_root('host.applyBuildNetworkFirewall', BUILD_NETWORK, host=True)
        self.assertEqual((result.returncode, result.stderr), (3, b'refused\n'))
        self.assertTrue(self.given().endswith(f'path {runner.ENVIRONMENT["PATH"]}\n'))

    def test_a_limits_script_someone_else_could_have_written_is_left_out_and_the_firewall_runs(self):
        os.chmod(self.path('engine', 'scripts/tenant-egress-limits.sh'), 0o666)
        result = self.pa_root('host.applyBuildNetworkFirewall', BUILD_NETWORK, host=True)
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertIn(b'the outbound limits were not applied (', result.stderr)
        self.assertTrue(self.given().startswith(f'argv --without-limits --network-on-stdin {BUILD_NETWORK}\n'))

    def test_a_script_someone_else_could_have_written_is_refused(self):
        os.chmod(self.path('engine', 'scripts/build-network-firewall.sh'), 0o777)
        result = self.pa_root('host.applyBuildNetworkFirewall', BUILD_NETWORK, host=True)
        self.assertEqual(result.returncode, 77, result.stderr)
        self.assertFalse(os.path.exists(self.kept))


class TrustedListsTest(Scripted):
    def setUp(self):
        super().setUp()
        self.stand_in(self.make('engine', firewall.FIREWALL_SCRIPT))

    def test_the_script_has_every_subnet_of_the_host_s_networks_once_in_its_environment(self):
        result = self.pa_root('firewall.applyTrustedLists', host=True)

        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'applied\n', b'refused\n'))
        self.assertEqual(self.given(), (
            f'argv --fail2ban\nsubnets 10.200.0.0/16 172.17.0.0/16 fd00:1::/64\npath {runner.ENVIRONMENT["PATH"]}\n'
        ))

    def test_no_subnet_is_an_empty_list_the_script_still_takes_as_given(self):
        self.routes[('GET', '/networks')] = (200, [])
        self.pa_root('firewall.applyTrustedLists', host=True)
        self.assertIn('\nsubnets \n', self.given())

    def test_a_daemon_that_does_not_answer_gives_the_script_no_subnet_as_its_own_read_did(self):
        self.routes[('GET', '/networks')] = (500, {'message': 'boom'})
        result = self.pa_root('firewall.applyTrustedLists', host=True)
        self.assertEqual((result.returncode, result.stderr), (3, b'refused\n'))
        self.assertIn('\nsubnets \n', self.given())

    def test_a_script_its_own_group_could_have_written_is_refused(self):
        os.chmod(self.path('engine', firewall.FIREWALL_SCRIPT), 0o775)
        result = self.pa_root('firewall.applyTrustedLists', host=True)
        self.assertEqual(result.returncode, 77, result.stderr)
        self.assertIn(b'could have been written by someone other than root', result.stderr)
        self.assertFalse(os.path.exists(self.kept))


class BuildNetworkScriptTest(unittest.TestCase):
    """The script itself on the broker's lines, against stand-ins for iptables and docker."""

    def setUp(self):
        self.base = tempfile.mkdtemp(prefix='broker-build-script-')
        self.bin = os.path.join(self.base, 'bin')
        self.calls = os.path.join(self.base, 'calls')
        os.makedirs(self.bin)
        self.stub('iptables', f'echo "iptables $*" >> "{self.calls}"\ncase " $* " in *" -C "*) exit 1 ;; esac\n')
        self.stub('docker', f'echo "docker $*" >> "{self.calls}"\n')
        self.limits = os.path.join(self.base, 'limits')
        self.stub('nft', f'[ "$1" = -f ] || exit 1\ncat > "{self.limits}"\n')
        self.environment = {**os.environ, 'PATH': self.bin + os.pathsep + os.environ['PATH'],
                            'PA_BUILD_LOCK': os.path.join(self.base, 'lock'),
                            'PA_ENV_FILE': os.path.join(self.base, 'env')}

    def tearDown(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def stub(self, name, body):
        path = os.path.join(self.bin, name)
        with open(path, 'w') as f:
            f.write('#!/bin/sh\n' + body)
        os.chmod(path, 0o755)

    def run_script(self, *arguments, lines=b''):
        return subprocess.run(['sh', BUILD_SCRIPT, *arguments, BUILD_NETWORK], input=lines, capture_output=True,
                              env=self.environment, timeout=support.SECONDS)

    def called(self):
        with open(self.calls) as f:
            return f.read()

    def test_the_script_is_valid_shell(self):
        result = subprocess.run(['sh', '-n', BUILD_SCRIPT], capture_output=True, timeout=support.SECONDS)
        self.assertEqual((result.returncode, result.stderr), (0, b''))

    def test_the_broker_s_lines_are_the_network_the_rules_are_for(self):
        result = self.run_script('--network-on-stdin',
                                 lines=f'id {BUILD_ID}\nbridge br-pa-build\nsubnet {BUILD_SUBNET}\n'.encode())

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertEqual(result.stdout, f'build-network-firewall: {BUILD_NETWORK} (br-pa-build, {BUILD_SUBNET}) '
                                        'egress to the internet only\n'.encode())
        self.assertIn('iptables -w 30 -I DOCKER-USER -i br-pa-build -j PA-BUILD-EGRESS\n', self.called())
        self.assertIn(f'iptables -w 30 -t nat -A POSTROUTING -s {BUILD_SUBNET} ! -o br-pa-build -j MASQUERADE\n',
                      self.called())
        self.assertNotIn('docker', self.called())
        with open(self.limits) as f:
            self.assertIn('add element inet pa_egress sources { "br-pa-tenants", "br-pa-build" }', f.read(),
                          'the outbound limits cover the build network too')

    def test_without_limits_the_rules_stand_and_the_limits_are_left_out(self):
        result = self.run_script('--without-limits', '--network-on-stdin', lines=f'id {BUILD_ID}\nbridge br-pa-build\n'.encode())
        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertIn('iptables -w 30 -I DOCKER-USER -i br-pa-build -j PA-BUILD-EGRESS\n', self.called())
        self.assertFalse(os.path.exists(self.limits))

    def test_a_missing_limits_script_leaves_the_rules_standing_and_says_so(self):
        scripts = os.path.join(self.base, 'scripts')
        os.makedirs(scripts)
        shutil.copy(BUILD_SCRIPT, scripts)
        result = subprocess.run(['sh', os.path.join(scripts, 'build-network-firewall.sh'), '--network-on-stdin', BUILD_NETWORK],
                                input=f'id {BUILD_ID}\nbridge br-pa-build\n'.encode(), capture_output=True,
                                env=self.environment, timeout=support.SECONDS)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, f'build-network-firewall: {scripts}/tenant-egress-limits.sh is missing; the outbound '
                                        'limits are not applied\n'.encode())
        self.assertIn('iptables -w 30 -I DOCKER-USER -i br-pa-build -j PA-BUILD-EGRESS\n', self.called())

    def test_a_network_without_a_bridge_name_is_on_docker_s_own_bridge_and_without_a_subnet_is_not_masqueraded(self):
        result = self.run_script('--network-on-stdin', lines=f'id {BUILD_ID}\n'.encode())

        self.assertEqual(result.stdout, f'build-network-firewall: {BUILD_NETWORK} (br-{BUILD_ID[:12]}) egress to the '
                                        'internet only\n'.encode(), result.stderr)
        self.assertNotIn('MASQUERADE', self.called())

    def test_no_lines_are_no_network(self):
        result = self.run_script('--network-on-stdin')
        self.assertEqual((result.returncode, result.stderr),
                         (1, f'build-network-firewall: no docker network {BUILD_NETWORK}\n'.encode()))
        self.assertFalse(os.path.exists(self.calls))

    def test_without_the_broker_docker_s_cli_reads_the_network(self):
        self.stub('docker', f'echo "docker $*" >> "{self.calls}"\n'
                            f'printf "id {BUILD_ID}\\nbridge \\nsubnet {BUILD_SUBNET} \\n"\n')

        result = self.run_script()

        self.assertEqual(result.stdout, f'build-network-firewall: {BUILD_NETWORK} (br-{BUILD_ID[:12]}, {BUILD_SUBNET}) '
                                        'egress to the internet only\n'.encode(), result.stderr)
        self.assertEqual(self.called().count('docker network inspect'), 1)


if __name__ == '__main__':
    unittest.main()
