"""`host.projectStats` on a fake host daemon and a fake account daemon, with the cgroups in a scratch tree.

The host's socket and the cgroup root are the module's own constants, so the broker forked for the test reads them.
"""

import os
import shutil
import socket
import tempfile
import time
import unittest
import urllib.parse

import support
from rootbroker import accountdaemon, dockerapi, projectstats
from test_account_docker import serve

ACCOUNTS = 'users'
OUTER = 'a' * 64
INNER = 'b' * 64
OTHER = 'c' * 64
OUTER_LINE = b'P\tu\t1048576\tmax\t4096\t3000000\n'
INNER_LINE = b'C\tu\t' + INNER.encode() + b'\tredis\tcache\t524288\t1073741824\t512\t900000\n'
INNER_WITHOUT_NAMES = b'C\tu\t' + INNER.encode() + b'\t\t\t524288\t1073741824\t512\t900000\n'
LISTED = b'L\n'


def summary(name, container_id, service=''):
    """A container as the Engine API lists it."""
    return {'Id': container_id, 'Names': ['/' + name], 'Labels': {dockerapi.SERVICE: service} if service else {}}


def own_container(name, accounts_directory, runtime=dockerapi.SYSBOX_RUNTIME, service='', canonical=None, project=None,
                  working_dir=None):
    """A container as the host's daemon describes it, in the account's project by default."""
    labels = {
        dockerapi.PROJECT: project or name,
        'com.docker.compose.project.working_dir': working_dir or os.path.join(accounts_directory, name),
    }
    if service:
        labels[dockerapi.SERVICE] = service
    return {
        'Id': OUTER,
        'Name': '/' + (canonical or name),
        'HostConfig': {'Runtime': runtime},
        'Config': {'Labels': labels},
        'State': {'Running': True, 'Pid': 812},
    }


class ProjectStatsTest(support.Serving):
    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-stats-')
        self.host_socket = os.path.join(self.scratch, 'host.sock')
        self.cgroups = os.path.join(self.scratch, 'cgroup')
        os.makedirs(self.cgroups)
        self.asked = []
        self.routes = {}
        self.daemons = {}
        self.answers = {}
        self.account_asked = {}
        self.listeners = []
        self.host = serve(self.host_socket, self.answer, self.asked)
        self.saved = (projectstats.SOCKET, projectstats.CGROUP_ROOT, accountdaemon.open_daemon)
        projectstats.SOCKET = self.host_socket
        projectstats.CGROUP_ROOT = self.cgroups
        accountdaemon.open_daemon = self.open_daemon
        super().setUp()

    def tearDown(self):
        super().tearDown()
        projectstats.SOCKET, projectstats.CGROUP_ROOT, accountdaemon.open_daemon = self.saved
        self.host.shutdown()
        self.host.server_close()
        for daemon in self.daemons.values():
            daemon.shutdown()
            daemon.server_close()
        for listener in self.listeners:
            listener.close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        with open(os.path.join(self.base, 'entered'), 'w') as f:
            f.write(str(namespaces))

    def open_daemon(self, account, accounts_directory, host_socket=None, seconds=None):
        """In the forked broker: the account's fake daemon, or nothing when the test gave it none."""
        with open(os.path.join(self.scratch, 'accounts-directory'), 'w') as f:
            f.write(accounts_directory)
        path = os.path.join(self.scratch, account.name + '.sock')
        if not os.path.exists(path):
            raise accountdaemon.Unavailable(f'the Docker daemon of container {account.name} is not running')
        return accountdaemon.Daemon(account.name, os.open(path, os.O_PATH | os.O_CLOEXEC))

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        return self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))

    def account_daemon(self, name, listing):
        """A fake daemon of `name`, answering its container listing with `listing`."""
        path = os.path.join(self.scratch, name + '.sock')
        self.answers[name] = listing
        asked = []
        self.daemons[name] = serve(path, lambda method, listed: (200, self.answers[name]), asked)
        self.account_asked[name] = asked

    def silent_daemon(self, name):
        """A daemon that takes the connection and never answers."""
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        listener.bind(os.path.join(self.scratch, name + '.sock'))
        listener.listen(1)
        self.listeners.append(listener)

    def accounts_directory(self):
        return os.path.join(self.roots['engine'], ACCOUNTS)

    def running(self, *summaries, **described):
        """The host's daemon lists the running containers and describes each container given after them."""
        self.routes[('GET', '/containers/json')] = (200, list(summaries))
        for container in described.values():
            self.routes[('GET', f'/containers/{container["Id"]}/json')] = (200, container)

    def cgroup(self, container_id, layout='systemd'):
        if layout == 'systemd':
            directory = os.path.join(self.cgroups, 'system.slice', 'docker-' + container_id + '.scope')
        else:
            directory = os.path.join(self.cgroups, 'docker', container_id)
        os.makedirs(directory)
        return directory

    def readings(self, directory, current='1048576', limit='max', inactive='4096', usec='3000000'):
        self.write(directory, 'memory.current', current)
        self.write(directory, 'memory.max', limit)
        self.write(directory, 'memory.stat', f'anon 8192\ninactive_file {inactive}\nactive_file 16')
        self.write(directory, 'cpu.stat', f'usage_usec {usec}')

    def write(self, directory, name, text):
        with open(os.path.join(directory, name), 'w') as f:
            f.write(text + '\n')

    def nested(self, directory, container_id=INNER, current='524288', limit='1073741824', inactive='512',
               usec='900000'):
        inside = os.path.join(directory, 'docker', container_id)
        os.makedirs(inside)
        self.readings(inside, current, limit, inactive, usec)
        return inside

    def ask(self, *usernames, seconds='45'):
        return self.pa_root('host.projectStats', seconds, *usernames, host=True)

    def test_a_project_and_the_containers_inside_it(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))
        self.account_daemon('u', [summary('redis', INNER, 'cache'), summary('sidecar', OTHER)])

        result = self.ask('u')

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertEqual(result.stdout, OUTER_LINE + INNER_LINE + LISTED)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.account_asked['u'][-1][1]).query)
        self.assertEqual(query, {'all': ['1']})
        with open(os.path.join(self.scratch, 'accounts-directory')) as f:
            self.assertEqual(f.read(), self.accounts_directory())

    def test_the_cgroupfs_layout_is_read_as_well(self):
        directory = self.cgroup(OUTER, layout='cgroupfs')
        self.readings(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))

        self.assertEqual(self.ask('u').stdout, OUTER_LINE + LISTED)

    def test_a_daemon_reply_that_is_not_a_list_keeps_the_names_empty(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))
        self.account_daemon('u', {'not': 'a list'})

        result = self.ask('u')

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertEqual(result.stdout, OUTER_LINE + INNER_WITHOUT_NAMES + LISTED)

    def test_a_daemon_listing_a_container_without_its_id_keeps_the_names_empty(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))
        self.account_daemon('u', [summary('redis', INNER, 'cache'), {'Names': ['/sidecar']}])

        result = self.ask('u')

        self.assert_answered(result, 0, b'')
        self.assertEqual(result.stdout, OUTER_LINE + INNER_WITHOUT_NAMES + LISTED)

    def test_a_host_listing_a_container_without_its_id_writes_nothing(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.running({'Names': ['/u']}, summary('u', OUTER), u=own_container('u', self.accounts_directory()))

        result = self.ask('u')

        self.assert_answered(result, 0, b'')
        self.assertEqual(result.stdout, b'')

    def test_a_running_container_that_is_not_the_accounts_own_is_left_out(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        for changed in ({'runtime': 'runc'}, {'runtime': 'runc', 'service': 'web'}, {'canonical': 'u-2'},
                        {'project': 'v'}, {'working_dir': '/home/u'}):
            with self.subTest(changed=changed):
                self.asked.clear()
                self.running(summary('u', OUTER), summary('u-2', OTHER), summary('outside', OTHER),
                             u=own_container('u', self.accounts_directory(), **changed))
                result = self.ask('u')
                self.assertEqual(result.stdout, LISTED)
                self.assertEqual([path for _, path in self.asked], ['/containers/json', f'/containers/{OUTER}/json'])

    def test_an_account_whose_daemon_does_not_answer_keeps_its_lines(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))

        result = self.ask('u')

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertEqual(result.stdout, OUTER_LINE + INNER_WITHOUT_NAMES + LISTED)

    def test_a_classic_account_has_no_inner_daemon_to_ask(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER),
                     u=own_container('u', self.accounts_directory(), runtime='runc', service='php'))
        self.account_daemon('u', [summary('redis', INNER, 'cache')])

        result = self.ask('u')

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertEqual(result.stdout, OUTER_LINE + INNER_WITHOUT_NAMES + LISTED)
        self.assertEqual(self.account_asked['u'], [])

    def test_a_tenant_name_or_service_cannot_write_a_line_of_its_own(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))
        self.account_daemon('u', [summary('re\tdis\nP\tfake', INNER, 'ca\tche')])

        lines = self.ask('u').stdout.splitlines()

        self.assertEqual(len(lines), 3, 'a tenant names no line of its own')
        self.assertEqual(lines[1].split(b'\t')[:5], [b'C', b'u', INNER.encode(), b'redisPfake', b'cache'])

    def test_a_missing_cgroup_file_is_an_empty_field(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        os.unlink(os.path.join(directory, 'memory.max'))
        os.unlink(os.path.join(directory, 'cpu.stat'))
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))

        self.assertEqual(self.ask('u').stdout, b'P\tu\t1048576\t\t4096\t\n' + LISTED)

    def test_a_fifo_in_place_of_a_cgroup_file_does_not_block_the_read(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        os.unlink(os.path.join(directory, 'memory.current'))
        os.mkfifo(os.path.join(directory, 'memory.current'))
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))

        started = time.monotonic()
        result = self.ask('u')

        self.assertEqual(result.stdout, b'P\tu\t\tmax\t4096\t3000000\n' + LISTED)
        self.assertLess(time.monotonic() - started, 5)

    def test_the_whole_read_stays_within_its_seconds(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.nested(directory)
        self.running(summary('u', OUTER), u=own_container('u', self.accounts_directory()))
        self.silent_daemon('u')

        started = time.monotonic()
        result = self.ask('u', seconds='1')

        self.assertEqual((result.returncode, result.stderr), (0, b''), result.stderr)
        self.assertTrue(result.stdout.startswith(OUTER_LINE), result.stdout)
        self.assertLess(time.monotonic() - started, 5)

    def test_a_host_daemon_that_does_not_answer_writes_nothing(self):
        result = self.ask('u')

        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))

    def test_no_running_account_is_still_a_complete_listing(self):
        self.running(summary('outside', OTHER))

        self.assertEqual(self.ask('u').stdout, LISTED)

    def test_a_container_the_host_cannot_describe_leaves_the_listing_incomplete(self):
        directory = self.cgroup(OUTER)
        self.readings(directory)
        self.running(summary('v', OTHER), summary('u', OUTER), u=own_container('u', self.accounts_directory()))
        self.routes[('GET', f'/containers/{OTHER}/json')] = (200, 'not a container')

        self.assertEqual(self.ask('u', 'v').stdout, OUTER_LINE)

    def test_the_action_runs_once_the_broker_entered_the_hosts_namespaces(self):
        self.ask('u')

        with open(os.path.join(self.base, 'entered')) as f:
            self.assertEqual(f.read(), 'None')


if __name__ == '__main__':
    unittest.main()
