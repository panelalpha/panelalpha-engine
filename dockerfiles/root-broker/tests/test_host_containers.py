"""The host daemon's Engine API calls for the container, network and registry actions, and what they print."""

import json
import os
import shutil
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import hostdaemon
from rootbroker.actions import registry
from test_account_docker import Hijack, frame, serve

CONTAINER_ID = 'a' * 64
SECOND_ID = 'b' * 64
NETWORK_ID = 'c' * 64
CREATED_ID = 'd' * 64
EXEC_ID = 'e' * 64
WORKING_DIR = 'com.docker.compose.project.working_dir'
ALICE_DIR = '/opt/panelalpha/shared-hosting/users/alice'
BOB_DIR = '/opt/panelalpha/shared-hosting/users/bob'
STARTED_AT = '2026-11-10T06:29:50.633260254Z'

LISTED = [
    {'Id': CONTAINER_ID, 'Names': ['/alice'], 'Image': 'alpine:3', 'State': 'running', 'Status': 'Up 2 hours',
     'Labels': {WORKING_DIR: ALICE_DIR, 'panelalpha.step': 'x'}},
    {'Id': SECOND_ID, 'Names': ['/bob'], 'Image': 'debian:12', 'State': 'created', 'Status': 'Created',
     'Labels': {WORKING_DIR: BOB_DIR}},
]
INSPECTED = {
    'Name': '/alice',
    'HostConfig': {'Runtime': 'sysbox-runc'},
    'State': {'Running': True, 'StartedAt': STARTED_AT, 'Error': ''},
    'Config': {'Labels': {WORKING_DIR: ALICE_DIR}, 'Env': ['GTV=1', 'PATH=/usr/bin']},
    'NetworkSettings': {'Networks': {'bridge': {
        'IPAMConfig': None, 'Links': None, 'Aliases': None, 'DriverOpts': None, 'GwPriority': 0,
        'NetworkID': '7' * 64, 'EndpointID': '5' * 64, 'Gateway': '172.20.0.1', 'IPAddress': '172.20.0.2',
        'MacAddress': '4e:0c:78:4a:e6:b4', 'IPPrefixLen': 16, 'IPv6Gateway': '', 'GlobalIPv6Address': '',
        'GlobalIPv6PrefixLen': 0, 'DNSNames': None,
    }}},
}
NETWORK = {
    'Name': 'gt-net', 'Id': NETWORK_ID, 'Driver': 'bridge',
    'IPAM': {'Driver': 'default',
             'Config': [{'Subnet': '172.25.2.0/24', 'Gateway': '172.25.2.1'},
                        {'Subnet': 'fd00::/64', 'Gateway': 'fd00::1'}]},
    'Options': {'com.docker.network.bridge.name': 'br-gt'},
    'Labels': {'com.panelalpha.role': 'gt'},
}


def encoded(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':')) + '\n'


class ContainerTest(support.Serving):
    """A fake host daemon in place of the host's socket, with the real action table."""

    actions = registry()

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-containers-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
        self.original = hostdaemon.SOCKET
        hostdaemon.SOCKET = self.daemon_socket
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET = self.original
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        answer = self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))
        return answer(path) if callable(answer) else answer

    def asked_last(self):
        """The method, the unquoted path and the query of the request the action sent last."""
        method, path = self.asked[-1]
        split = urllib.parse.urlsplit(path)
        return method, urllib.parse.unquote(split.path), urllib.parse.parse_qs(split.query)

    def sent_body(self):
        return json.loads(self.sent[-1][2])

    def assert_asked(self, method, path, query=None):
        self.assertEqual(self.asked_last(), (method, path, query or {}))


class InfoTest(ContainerTest):
    def test_info_is_the_daemons_own_json(self):
        info = {'ID': 'f' * 64, 'Name': 'host', 'ServerVersion': '29.9', 'Runtimes': {'runc': {}}}
        self.routes[('GET', '/info')] = (200, info)
        result = self.pa_root('docker.info')
        self.assert_asked('GET', '/info')
        self.assertEqual((result.returncode, result.stdout), (0, encoded(info).encode()), result.stderr)
        self.assertEqual(result.stderr, b'')

    def test_the_registry_mirrors_are_the_daemons_own(self):
        self.routes[('GET', '/info')] = (200, {'RegistryConfig': {'Mirrors': ['http://127.0.0.1:5001/']}})
        result = self.pa_root('docker.registryMirrors')
        self.assert_asked('GET', '/info')
        self.assertEqual((result.returncode, result.stdout), (0, b'["http://127.0.0.1:5001/"]\n'), result.stderr)

    def test_no_mirrors_are_null_as_the_cli_printed_them(self):
        self.routes[('GET', '/info')] = (200, {'RegistryConfig': {'Mirrors': None}})
        result = self.pa_root('docker.registryMirrors')
        self.assertEqual((result.returncode, result.stdout), (0, b'null\n'), result.stderr)

    def test_a_daemon_error_is_told_in_the_cli_s_words(self):
        self.routes[('GET', '/info')] = (500, {'message': 'something  went wrong'})
        result = self.pa_root('docker.info')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: something went wrong\n')


class ListingTest(ContainerTest):
    def test_container_images_are_listed_with_all(self):
        self.routes[('GET', '/containers/json')] = (200, LISTED)
        result = self.pa_root('docker.containerImages')
        self.assert_asked('GET', '/containers/json', {'all': ['1'], 'filters': ['{}']})
        self.assertEqual((result.returncode, result.stdout), (0, b'alpine:3\ndebian:12\n'), result.stderr)

    def test_a_container_is_named_exactly_as_the_cli_asked_for_it(self):
        self.routes[('GET', '/containers/json')] = (200, [LISTED[0]])
        result = self.pa_root('docker.containerNamed', 'alice.1')
        method, path, query = self.asked_last()
        self.assertEqual((method, path), ('GET', '/containers/json'))
        self.assertEqual(query['all'], ['1'])
        self.assertEqual(json.loads(query['filters'][0]), {'name': [r'^/alice\.1$']})
        self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)

    def test_a_named_container_is_narrowed_by_its_label(self):
        self.routes[('GET', '/containers/json')] = (200, [LISTED[0]])
        result = self.pa_root('docker.containerNamedWithLabel', 'alice', WORKING_DIR, ALICE_DIR)
        method, path, query = self.asked_last()
        self.assertEqual((method, path), ('GET', '/containers/json'))
        self.assertEqual(query['all'], ['1'])
        self.assertEqual(json.loads(query['filters'][0]), {'label': [f'{WORKING_DIR}={ALICE_DIR}'],
                                                           'name': ['^/alice$']})
        self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)

    def test_running_ids_labelled_are_cut_to_twelve_characters(self):
        self.routes[('GET', '/containers/json')] = (200, LISTED)
        result = self.pa_root('docker.runningContainerIdsLabelled', 'panelalpha.step')
        self.assert_asked('GET', '/containers/json', {'filters': ['{"label":["panelalpha.step"]}']})
        self.assertEqual((result.returncode, result.stdout), (0, b'aaaaaaaaaaaa\nbbbbbbbbbbbb\n'), result.stderr)

    def test_running_ids_with_a_label_value_are_cut_to_twelve_characters(self):
        self.routes[('GET', '/containers/json')] = (200, LISTED[:1])
        result = self.pa_root('docker.runningContainerIdsWithLabel', 'panelalpha.step', 'x')
        self.assert_asked('GET', '/containers/json', {'filters': ['{"label":["panelalpha.step=x"]}']})
        self.assertEqual((result.returncode, result.stdout), (0, b'aaaaaaaaaaaa\n'), result.stderr)

    def test_stopped_ids_are_of_exited_and_created_containers_only(self):
        self.routes[('GET', '/containers/json')] = (200, LISTED[1:])
        result = self.pa_root('docker.stoppedContainerIdsLabelled', WORKING_DIR)
        self.assert_asked('GET', '/containers/json', {
            'all': ['1'],
            'filters': ['{"label":["' + WORKING_DIR + '"],"status":["exited","created"]}'],
        })
        self.assertEqual((result.returncode, result.stdout), (0, b'bbbbbbbbbbbb\n'), result.stderr)

    def test_labelled_containers_print_name_state_and_the_labels_value(self):
        self.routes[('GET', '/containers/json')] = (200, LISTED)
        result = self.pa_root('docker.containersLabelled', WORKING_DIR)
        self.assert_asked('GET', '/containers/json', {
            'all': ['1'], 'filters': ['{"label":["' + WORKING_DIR + '"]}'],
        })
        self.assertEqual(
            (result.returncode, result.stdout),
            (0, f'alice\trunning\t{ALICE_DIR}\nbob\tcreated\t{BOB_DIR}\n'.encode()),
            result.stderr,
        )

    def test_a_daemon_error_is_told_in_the_cli_s_words(self):
        self.routes[('GET', '/containers/json')] = (500, {'message': 'the daemon  fell over'})
        result = self.pa_root('docker.containerImages')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: the daemon fell over\n')


class InspectTest(ContainerTest):
    def route(self, name, state='', running=True):
        self.routes[('GET', f'/containers/{name}/json')] = (200, {
            **INSPECTED, 'Name': '/' + name, 'State': {**INSPECTED['State'], 'Error': state, 'Running': running},
        })

    def test_each_container_is_its_name_runtime_working_directory_start_and_last_start_error(self):
        self.route('alice')
        self.route('bob', state='boom')
        result = self.pa_root('docker.inspect', 'alice', 'bob')
        self.assertEqual(
            (result.returncode, result.stdout),
            (0, f'/alice\tsysbox-runc\t{ALICE_DIR}\t{STARTED_AT}\t""\n'
                f'/bob\tsysbox-runc\t{ALICE_DIR}\t{STARTED_AT}\t"boom"\n'.encode()),
            result.stderr,
        )
        self.assertEqual(self.asked, [('GET', '/containers/alice/json'), ('GET', '/containers/bob/json')])

    def test_a_container_gone_since_it_was_listed_is_an_error_while_the_others_print(self):
        self.route('alice')
        result = self.pa_root('docker.inspect', 'alice', 'gone')
        self.assertEqual((result.returncode, result.stderr), (1, b'error: no such object: gone\n'))
        self.assertEqual(result.stdout, f'/alice\tsysbox-runc\t{ALICE_DIR}\t{STARTED_AT}\t""\n'.encode())

    def test_every_container_gone_prints_nothing_and_exits_one(self):
        result = self.pa_root('docker.inspect', 'gone', 'gone2')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'error: no such object: gone\nerror: no such object: gone2\n')

    def test_a_daemon_error_is_told_in_the_cli_s_words(self):
        self.routes[('GET', '/containers/alice/json')] = (500, {'message': 'no  time'})
        result = self.pa_root('docker.inspect', 'alice')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: no time\n')


class ContainerStateTest(ContainerTest):
    def test_a_running_container_answers_true(self):
        self.routes[('GET', '/containers/alice/json')] = (200, INSPECTED)
        result = self.pa_root('docker.isRunning', 'alice')
        self.assert_asked('GET', '/containers/alice/json')
        self.assertEqual((result.returncode, result.stdout), (0, b'true\n'), result.stderr)

    def test_a_stopped_container_answers_false(self):
        self.routes[('GET', '/containers/alice/json')] = (200, {
            **INSPECTED, 'State': {**INSPECTED['State'], 'Running': False},
        })
        result = self.pa_root('docker.isRunning', 'alice')
        self.assertEqual((result.returncode, result.stdout), (0, b'false\n'), result.stderr)

    def test_the_start_time_is_the_daemons_own(self):
        self.routes[('GET', '/containers/alice/json')] = (200, INSPECTED)
        result = self.pa_root('docker.startedAt', 'alice')
        self.assert_asked('GET', '/containers/alice/json')
        self.assertEqual((result.returncode, result.stdout),
                         (0, b'2026-11-10T06:29:50.633260254Z\n'), result.stderr)

    def test_the_cpu_limit_is_the_daemons_nano_cpus_and_0_without_one(self):
        for host_config, printed in (({'Runtime': 'sysbox-runc', 'NanoCpus': 1500000000}, b'1500000000\n'),
                                     ({'Runtime': 'sysbox-runc', 'NanoCpus': 0}, b'0\n'),
                                     ({'Runtime': 'sysbox-runc'}, b'0\n')):
            with self.subTest(host_config=host_config):
                self.routes[('GET', '/containers/alice/json')] = (200, {**INSPECTED, 'HostConfig': host_config})
                result = self.pa_root('docker.nanoCpus', 'alice')
                self.assert_asked('GET', '/containers/alice/json')
                self.assertEqual((result.returncode, result.stdout), (0, printed), result.stderr)

    def test_the_networks_are_the_daemons_own_json(self):
        self.routes[('GET', '/containers/alice/json')] = (200, INSPECTED)
        result = self.pa_root('docker.containerNetworks', 'alice')
        expected = encoded(INSPECTED['NetworkSettings']['Networks']).encode()
        self.assert_asked('GET', '/containers/alice/json')
        self.assertEqual((result.returncode, result.stdout), (0, expected), result.stderr)

    def test_a_running_containers_environment_is_one_variable_per_line(self):
        self.routes[('GET', '/containers/alice/json')] = (200, INSPECTED)
        result = self.pa_root('docker.runningEnvironment', 'alice')
        self.assert_asked('GET', '/containers/alice/json')
        self.assertEqual((result.returncode, result.stdout), (0, b'GTV=1\nPATH=/usr/bin\n'), result.stderr)

    def test_a_stopped_containers_environment_is_nothing(self):
        self.routes[('GET', '/containers/alice/json')] = (200, {
            **INSPECTED, 'State': {**INSPECTED['State'], 'Running': False},
        })
        result = self.pa_root('docker.runningEnvironment', 'alice')
        self.assertEqual((result.returncode, result.stdout), (0, b''), result.stderr)

    def test_a_missing_container_is_no_such_object(self):
        for action in ('docker.isRunning', 'docker.startedAt', 'docker.nanoCpus', 'docker.containerNetworks',
                       'docker.runningEnvironment'):
            with self.subTest(action=action):
                result = self.pa_root(action, 'gone')
                self.assertEqual((result.returncode, result.stdout), (1, b''))
                self.assertEqual(result.stderr, b'error: no such object: gone\n')

    def test_a_daemon_error_is_told_in_the_cli_s_words(self):
        self.routes[('GET', '/containers/alice/json')] = (500, {'message': 'too  busy'})
        result = self.pa_root('docker.isRunning', 'alice')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: too busy\n')


class StatsTest(ContainerTest):
    def stats(self, total, system, online=4, per_cpu=()):
        """Usage `total` and host time `system` since the previous sample, which the daemon reports beside them."""
        return {'cpu_stats': {'cpu_usage': {'total_usage': 5000 + total, 'percpu_usage': list(per_cpu)},
                              'system_cpu_usage': 90000 + system, 'online_cpus': online},
                'precpu_stats': {'cpu_usage': {'total_usage': 5000}, 'system_cpu_usage': 90000}}

    def test_the_percentage_is_the_cli_s_own_formula(self):
        self.routes[('GET', '/containers/alice/stats')] = (200, self.stats(200, 1000, online=4))
        result = self.pa_root('docker.cpuPercents', 'alice')
        self.assert_asked('GET', '/containers/alice/stats', {'stream': ['false']})
        self.assertEqual((result.returncode, result.stdout), (0, b'80.00%\n'), result.stderr)

    def test_the_online_cpus_fall_back_to_the_per_cpu_figures(self):
        self.routes[('GET', '/containers/alice/stats')] = (200, self.stats(100, 1000, online=0, per_cpu=(1, 2)))
        result = self.pa_root('docker.cpuPercents', 'alice')
        self.assertEqual((result.returncode, result.stdout), (0, b'20.00%\n'), result.stderr)

    def test_a_container_with_no_time_yet_is_zero(self):
        self.routes[('GET', '/containers/alice/stats')] = (200, self.stats(0, 0))
        result = self.pa_root('docker.cpuPercents', 'alice')
        self.assertEqual((result.returncode, result.stdout), (0, b'0.00%\n'), result.stderr)

    def test_one_line_per_name_in_the_order_given(self):
        self.routes[('GET', '/containers/alice/stats')] = (200, self.stats(200, 1000))
        self.routes[('GET', '/containers/bob/stats')] = (200, self.stats(100, 1000))
        result = self.pa_root('docker.cpuPercents', 'alice', 'bob')
        self.assertEqual((result.returncode, result.stdout), (0, b'80.00%\n40.00%\n'), result.stderr)
        self.assertEqual([path for _, path in self.asked], ['/containers/alice/stats?stream=false',
                                                            '/containers/bob/stats?stream=false'])

    def test_a_figure_that_is_no_number_reads_as_zero_as_the_service_stats_do(self):
        sample = self.stats(200, 1000)
        sample['cpu_stats']['system_cpu_usage'] = 'busy'
        self.routes[('GET', '/containers/alice/stats')] = (200, sample)
        result = self.pa_root('docker.cpuPercents', 'alice')
        self.assertEqual((result.returncode, result.stdout), (0, b'0.00%\n'), result.stderr)

    def test_a_sample_read_from_an_empty_reply_is_unexpected(self):
        self.routes[('GET', '/containers/alice/stats')] = (200, b'')
        result = self.pa_root('docker.cpuPercents', 'alice')
        self.assert_answered(result, 1, b"the host's Docker answered with an unexpected reply\n")
        self.assertEqual(result.stdout, b'')

    def test_a_missing_container_fails_the_whole_without_a_line(self):
        self.routes[('GET', '/containers/alice/stats')] = (200, self.stats(200, 1000))
        self.routes[('GET', '/containers/gone/stats')] = (404, {'message': 'No such container: gone'})
        result = self.pa_root('docker.cpuPercents', 'alice', 'gone')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: No such container: gone\n')


class UpdateTest(ContainerTest):
    def test_the_memory_limit_is_megabytes_as_bytes_and_swap_alike(self):
        self.routes[('POST', '/containers/alice/update')] = (200, {'Warnings': []})
        result = self.pa_root('docker.limitMemory', 'alice', '1536')
        self.assert_asked('POST', '/containers/alice/update')
        self.assertEqual(self.sent_body(), {'Memory': 1536 << 20, 'MemorySwap': 1536 << 20})
        self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)

    def test_the_cpu_limit_is_billionths_of_a_cpu_as_the_cli_sends_it(self):
        self.routes[('POST', '/containers/alice/update')] = (200, {'Warnings': []})
        for cpus, nano in (('1.5', 1_500_000_000), ('2', 2_000_000_000), ('0.000001', 1000)):
            with self.subTest(cpus=cpus):
                self.sent.clear()
                result = self.pa_root('docker.limitCpu', 'alice', cpus)
                self.assert_asked('POST', '/containers/alice/update')
                self.assertEqual(self.sent_body(), {'NanoCpus': nano})
                self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)

    def test_a_missing_container_is_the_daemons_error(self):
        self.routes[('POST', '/containers/alice/update')] = (404, {'message': 'No such container: alice'})
        result = self.pa_root('docker.limitMemory', 'alice', '64')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: No such container: alice\n')

    def test_a_restart_is_one_post_and_prints_the_name(self):
        self.routes[('POST', '/containers/alice/restart')] = (204, b'')
        result = self.pa_root('docker.restart', 'alice')
        self.assert_asked('POST', '/containers/alice/restart')
        self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)

    def test_a_restart_of_a_missing_container_is_the_daemons_error(self):
        self.routes[('POST', '/containers/alice/restart')] = (404, {'message': 'No such container: alice'})
        result = self.pa_root('docker.restart', 'alice')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: No such container: alice\n')


class RemoveTest(ContainerTest):
    def test_a_forced_removal_is_one_delete_and_prints_the_name(self):
        self.routes[('DELETE', '/containers/alice')] = (204, b'')
        result = self.pa_root('docker.forceRemoveContainer', 'alice')
        self.assert_asked('DELETE', '/containers/alice', {'force': ['1']})
        self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)

    def test_a_missing_container_is_no_failure_and_prints_nothing(self):
        result = self.pa_root('docker.forceRemoveContainer', 'gone')
        self.assert_asked('DELETE', '/containers/gone', {'force': ['1']})
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))

    def test_every_name_is_tried_and_a_missing_one_does_not_stop_the_rest(self):
        self.routes[('DELETE', '/containers/alice')] = (200, b'')
        result = self.pa_root('docker.forceRemoveContainers', 'alice', 'gone', 'bob')
        self.assertEqual((result.returncode, result.stdout), (0, b'alice\n'), result.stderr)
        self.assertEqual([path for _, path in self.asked], ['/containers/alice?force=1', '/containers/gone?force=1',
                                                            '/containers/bob?force=1'])

    def test_a_daemon_error_is_told_and_the_next_is_still_tried(self):
        self.routes[('DELETE', '/containers/alice')] = (500, {'message': 'device  busy'})
        self.routes[('DELETE', '/containers/bob')] = (204, b'')
        result = self.pa_root('docker.forceRemoveContainers', 'alice', 'bob')
        self.assertEqual((result.returncode, result.stdout), (1, b'bob\n'))
        self.assertEqual(result.stderr, b'Error response from daemon: device busy\n')


class NetworkTest(ContainerTest):
    def test_a_networks_subnets_and_gateways_are_one_pair_per_ipam_config(self):
        self.routes[('GET', '/networks/gt-net')] = (200, NETWORK)
        result = self.pa_root('docker.networkSubnetsAndGateways', 'gt-net')
        self.assert_asked('GET', '/networks/gt-net')
        self.assertEqual((result.returncode, result.stdout),
                         (0, b'172.25.2.0/24 172.25.2.1\nfd00::/64 fd00::1\n'), result.stderr)

    def test_a_networks_id_is_printed_whole(self):
        self.routes[('GET', '/networks/gt-net')] = (200, NETWORK)
        result = self.pa_root('docker.networkId', 'gt-net')
        self.assert_asked('GET', '/networks/gt-net')
        self.assertEqual((result.returncode, result.stdout), (0, (NETWORK_ID + '\n').encode()), result.stderr)

    def test_a_missing_network_is_the_daemons_error(self):
        self.routes[('GET', '/networks/gt-missing')] = (404, {'message': 'network gt-missing not found'})
        for action in ('docker.networkId', 'docker.networkSubnetsAndGateways'):
            with self.subTest(action=action):
                result = self.pa_root(action, 'gt-missing')
                self.assertEqual((result.returncode, result.stdout), (1, b''))
                self.assertEqual(result.stderr, b'Error response from daemon: network gt-missing not found\n')

    def test_a_network_read_from_an_empty_reply_or_one_that_is_no_object_is_unexpected(self):
        cases = [(action, reply) for action in ('docker.networkId', 'docker.networkSubnetsAndGateways')
                 for reply in (b'', b'[]')]
        for request, (action, reply) in enumerate(cases, start=1):
            with self.subTest(action=action, reply=reply):
                self.routes[('GET', '/networks/gt-net')] = (200, reply)
                result = self.pa_root(action, 'gt-net')
                self.assert_answered(result, 1, b"the host's Docker answered with an unexpected reply\n", request)
                self.assertEqual(result.stdout, b'')

    def test_a_bridge_network_created_with_an_empty_reply_is_unexpected(self):
        self.routes[('POST', '/networks/create')] = (201, b'')
        result = self.pa_root('docker.createBridgeNetwork', 'panelalpha-build', 'br-pa-build', 'build')
        self.assert_answered(result, 1, b"the host's Docker answered with an unexpected reply\n")
        self.assertEqual(result.stdout, b'')

    def test_a_bridge_network_is_created_with_its_bridge_name_and_role(self):
        self.routes[('POST', '/networks/create')] = (201, {'Id': CREATED_ID, 'Warning': ''})
        result = self.pa_root('docker.createBridgeNetwork', 'panelalpha-build', 'br-pa-build', 'build')
        self.assert_asked('POST', '/networks/create')
        self.assertEqual(self.sent_body(), {
            'Name': 'panelalpha-build', 'Driver': 'bridge',
            'Options': {'com.docker.network.bridge.name': 'br-pa-build'},
            'Labels': {'com.panelalpha.role': 'build'},
        })
        self.assertEqual((result.returncode, result.stdout), (0, (CREATED_ID + '\n').encode()), result.stderr)

    def test_a_network_a_name_is_taken_is_the_daemons_error(self):
        self.routes[('POST', '/networks/create')] = (409, {'message': 'network with name x already exists'})
        result = self.pa_root('docker.createBridgeNetwork', 'x', 'br-x', 'build')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr, b'Error response from daemon: network with name x already exists\n')

    def test_a_container_is_connected_by_name(self):
        self.routes[('POST', '/networks/gt-net/connect')] = (200, b'')
        result = self.pa_root('docker.connectNetwork', 'gt-net', 'alice')
        self.assert_asked('POST', '/networks/gt-net/connect')
        self.assertEqual(self.sent_body(), {'Container': 'alice'})
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))

    def test_connecting_one_already_on_the_network_is_the_daemons_error(self):
        self.routes[('POST', '/networks/gt-net/connect')] = (
            403, {'message': 'endpoint with name alice already exists in network gt-net'},
        )
        result = self.pa_root('docker.connectNetwork', 'gt-net', 'alice')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(
            result.stderr,
            b'Error response from daemon: endpoint with name alice already exists in network gt-net\n',
        )

    def test_a_container_is_disconnected_by_name(self):
        self.routes[('POST', '/networks/gt-net/disconnect')] = (200, b'')
        result = self.pa_root('docker.disconnectNetwork', 'gt-net', 'alice')
        self.assert_asked('POST', '/networks/gt-net/disconnect')
        self.assertEqual(self.sent_body(), {'Container': 'alice'})
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))

    def test_disconnecting_one_not_on_the_network_is_the_daemons_error(self):
        self.routes[('POST', '/networks/gt-net/disconnect')] = (
            403, {'message': 'container ' + CONTAINER_ID + ' is not connected to network gt-net'},
        )
        result = self.pa_root('docker.disconnectNetwork', 'gt-net', 'alice')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(
            result.stderr,
            f'Error response from daemon: container {CONTAINER_ID} is not connected to network gt-net\n'.encode(),
        )


class RegistryTest(ContainerTest):
    def exec_routes(self, container, code=0):
        self.routes[('POST', f'/containers/{container}/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(frame(1, b'ok\n')))
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': code, 'Running': False})

    def command(self, container):
        return next(json.loads(body) for method, path, body, _ in self.sent
                    if (method, path) == ('POST', f'/containers/{container}/exec'))

    def test_garbage_collection_runs_the_same_argv_in_the_registry(self):
        self.exec_routes('panelalpha-cache-registry-writer')
        result = self.pa_root('docker.registryGarbageCollect', 'panelalpha-cache-registry-writer',
                              '/etc/distribution/config.yml')
        created = self.command('panelalpha-cache-registry-writer')
        self.assertEqual(created['Cmd'], ['registry', 'garbage-collect', '--delete-untagged',
                                          '/etc/distribution/config.yml'])
        self.assertEqual((result.returncode, result.stdout), (0, b'ok\n'), result.stderr)

    def test_the_tag_links_are_read_with_find_as_the_cli_read_them(self):
        self.exec_routes('panelalpha-cache-registry-writer')
        result = self.pa_root('docker.registryTagLinks', 'panelalpha-cache-registry-writer',
                              '/var/lib/registry/docker/registry/v2/repositories')
        created = self.command('panelalpha-cache-registry-writer')
        self.assertEqual(created['Cmd'], [
            'find', '/var/lib/registry/docker/registry/v2/repositories', '-path',
            '*/_manifests/tags/*/current/link', '-exec', 'sh', '-c',
            'for f do printf "%s %s %s\\n" "$(stat -c %Y "$f")" "$(cat "$f")" "$f"; done', 'sh', '{}', '+',
        ])
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_a_repository_s_directory_is_removed_below_the_repositories_by_its_name(self):
        self.exec_routes('panelalpha-cache-registry-writer')
        result = self.pa_root('docker.removeRegistryRepository', 'panelalpha-cache-registry-writer',
                              '/var/lib/registry/docker/registry/v2/repositories', 'panelalpha/php')
        self.assertEqual(self.command('panelalpha-cache-registry-writer')['Cmd'], [
            'rm', '-rf', '--', '/var/lib/registry/docker/registry/v2/repositories/panelalpha/php',
        ])
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_the_storage_is_measured_with_du(self):
        self.exec_routes('panelalpha-registry-proxy')
        result = self.pa_root('docker.registryStorageKilobytes', 'panelalpha-registry-proxy')
        self.assertEqual(self.command('panelalpha-registry-proxy')['Cmd'], ['du', '-sk', '--', '/var/lib/registry'])
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_wiping_the_storage_removes_what_the_cli_removed(self):
        self.exec_routes('panelalpha-registry-proxy')
        result = self.pa_root('docker.wipeRegistryStorage', 'panelalpha-registry-proxy')
        self.assertEqual(self.command('panelalpha-registry-proxy')['Cmd'], [
            'rm', '-rf', '--', '/var/lib/registry/docker', '/var/lib/registry/scheduler-state.json',
        ])
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_the_command_s_exit_code_is_the_action_s(self):
        self.exec_routes('panelalpha-cache-registry-writer', code=3)
        result = self.pa_root('docker.registryStorageKilobytes', 'panelalpha-cache-registry-writer')
        self.assertEqual(result.returncode, 3, result.stderr)

    def test_only_the_two_registry_containers_may_be_asked(self):
        result = self.pa_root('docker.wipeRegistryStorage', 'core')
        self.assertEqual(result.returncode, 77, result.stderr)
        self.assertEqual(self.asked, [])

    def test_a_container_that_is_not_running_is_the_daemons_error(self):
        self.routes[('POST', '/containers/panelalpha-registry-proxy/exec')] = (
            409, {'message': 'Container panelalpha-registry-proxy is not running'},
        )
        result = self.pa_root('docker.wipeRegistryStorage', 'panelalpha-registry-proxy')
        self.assertEqual((result.returncode, result.stdout), (1, b''))
        self.assertEqual(result.stderr,
                         b'Error response from daemon: Container panelalpha-registry-proxy is not running\n')


class NoDaemonTest(support.Serving):
    actions = registry()

    def setUp(self):
        self.original = hostdaemon.SOCKET
        hostdaemon.SOCKET = os.path.join(tempfile.gettempdir(), 'no-such-docker.sock')
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET = self.original

    def test_a_daemon_that_is_not_there_is_told_as_the_cli_tells_it(self):
        result = self.pa_root('docker.info')
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, (hostdaemon.CANNOT_CONNECT + '\n').encode())


if __name__ == '__main__':
    unittest.main()
