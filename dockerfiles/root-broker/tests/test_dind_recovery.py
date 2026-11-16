import json
import os
import shutil
import tempfile
import time
import unittest
import urllib.parse

import support
from rootbroker import dindrecovery, hostdaemon, protocol
from rootbroker.actions import host
from test_account_docker import Hijack, HijackHolding, serve

EXEC_ID = 'e' * 64
ALICE = 'a' * 64
BOB = 'b' * 64
BUILD = 'c' * 64
FUSE_ID = '44'
OTHER_FUSE_ID = '7'
COMPOSE = b'services:\n  dind:\n    restart: always\n  other:\n    restart: "always"\n    restart: no\n'


def account(name, container_id, users, **changes):
    container = {
        'Id': container_id,
        'Name': '/' + name,
        'HostConfig': {'Runtime': 'sysbox-runc'},
        'State': {'Running': True, 'Pid': 512, 'StartedAt': '2026-11-10T07:00:00Z'},
        'Config': {'Labels': {'com.docker.compose.project': name,
                              'com.docker.compose.project.working_dir': os.path.join(users, name)}},
    }
    container.update(changes)
    return container


class DindRecoveryTest(support.Serving):
    actions = {f'host.{name}': plan for name, plan in host.ACTIONS.items()
               if name in ('detectStuckDind', 'recoverStuckDind', 'recoverDindAccount')}

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-dind-')
        self.users, self.homes = self.directory('users'), self.directory('homes')
        self.fuse, self.cgroups = self.directory('fuse'), self.directory('cgroup')
        self.sysboxfs = self.directory('sysboxfs')
        self.mountinfo = os.path.join(self.scratch, 'mountinfo')
        open(self.mountinfo, 'w').close()
        self.compose_record = os.path.join(self.scratch, 'compose-record')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked, self.sent, self.routes = [], [], {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
        self.saved = (dindrecovery.FUSE, dindrecovery.CGROUP, dindrecovery.MOUNTINFO, dindrecovery.SYSBOXFS,
                      dindrecovery.USERS, dindrecovery.HOMES, dindrecovery.DOCKER, dindrecovery.SLEEP,
                      dindrecovery.PROBE_SECONDS, hostdaemon.SOCKET)
        dindrecovery.FUSE, dindrecovery.CGROUP, dindrecovery.MOUNTINFO = self.fuse, self.cgroups, self.mountinfo
        dindrecovery.SYSBOXFS, dindrecovery.USERS, dindrecovery.HOMES = self.sysboxfs, self.users, self.homes
        dindrecovery.DOCKER = self.compose_helper()
        dindrecovery.SLEEP = lambda seconds: None
        dindrecovery.PROBE_SECONDS = 2
        hostdaemon.SOCKET = self.daemon_socket
        super().setUp()

    def tearDown(self):
        super().tearDown()
        (dindrecovery.FUSE, dindrecovery.CGROUP, dindrecovery.MOUNTINFO, dindrecovery.SYSBOXFS, dindrecovery.USERS,
         dindrecovery.HOMES, dindrecovery.DOCKER, dindrecovery.SLEEP, dindrecovery.PROBE_SECONDS,
         hostdaemon.SOCKET) = self.saved
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        with open(os.path.join(self.scratch, 'entered'), 'w') as f:
            f.write(str(namespaces))

    def directory(self, name):
        path = os.path.join(self.scratch, name)
        os.makedirs(path)
        return path

    def compose_helper(self):
        path = os.path.join(self.scratch, 'docker')
        with open(path, 'w') as f:
            f.write(f'#!/bin/sh\npwd > {self.compose_record}\necho compose up\nexit 0\n')
        os.chmod(path, 0o755)
        return path

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        answer = self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))
        return answer(path) if callable(answer) else answer

    def container(self, name, container_id, **changes):
        return account(name, container_id, self.users, **changes)

    def listing(self, *containers):
        self.routes[('GET', '/containers/json')] = (
            200, [{'Id': container['Id'], 'Names': [container['Name']]} for container in containers],
        )
        for container in containers:
            self.routes[('GET', f'/containers{container["Name"]}/json')] = (200, container)

    def exec_works(self, name, exec_id=EXEC_ID, code=0):
        self.routes[('POST', f'/containers/{name}/exec')] = (201, {'Id': exec_id})
        self.routes[('POST', f'/exec/{exec_id}/start')] = (101, Hijack(b''))
        self.routes[('GET', f'/exec/{exec_id}/json')] = (200, {'ExitCode': code, 'Running': False})

    def fuse_mount(self, container_id, waiting):
        with open(self.mountinfo, 'a') as f:
            f.write(f'1234 1200 0:{FUSE_ID} / {os.path.join(self.sysboxfs, container_id)} '
                    'rw,nosuid,nodev - fuse.sysboxfs sysboxfs rw\n')
        self.abort_file(FUSE_ID)
        with open(os.path.join(self.fuse, FUSE_ID, 'waiting'), 'w') as f:
            f.write(str(waiting))

    def abort_file(self, fuse_id):
        directory = os.path.join(self.fuse, fuse_id)
        os.makedirs(directory, exist_ok=True)
        path = os.path.join(directory, 'abort')
        open(path, 'w').close()
        return path

    def scope(self, container_id):
        directory = os.path.join(self.cgroups, 'system.slice', f'docker-{container_id}.scope')
        os.makedirs(directory, exist_ok=True)
        path = os.path.join(directory, 'cgroup.kill')
        open(path, 'w').close()
        return path

    def compose_file(self, name, content=COMPOSE):
        directory = os.path.join(self.users, name)
        os.makedirs(directory, exist_ok=True)
        path = os.path.join(directory, 'docker-compose.yml')
        with open(path, 'wb') as f:
            f.write(content)
        return path

    def home(self, name):
        path = os.path.join(self.homes, name)
        os.makedirs(path, exist_ok=True)
        return path

    def recover(self, *values):
        return self.pa_root('host.recoverDindAccount', 'alice', *values, host=True)

    def test_detect_names_a_stuck_account_and_a_healthy_one(self):
        self.listing(self.container('alice', ALICE), self.container('bob', BOB))
        self.exec_works('bob')
        result = self.pa_root('host.detectStuckDind', host=True)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertRegex(result.stdout.decode(), r'\A\[recover-sysbox-dind\] scanning sysbox containers'
                                                r' \(host D=\d+\)\nSTUCK alice\nOK    bob\n\Z')
        self.assertEqual(urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[0][1]).query), {'all': ['1']})

    def test_a_sysbox_container_that_is_not_an_account_is_left_out(self):
        build = self.container('panelalpha-build-1', BUILD, Config={'Labels': {
            'com.docker.compose.project': 'panelalpha-build',
            'com.docker.compose.project.working_dir': '/opt/panelalpha/build',
        }})
        self.listing(build, self.container('bob', BOB))
        self.exec_works('bob')
        result = self.pa_root('host.detectStuckDind', host=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.decode().splitlines()[1:], ['OK    bob'])

    def test_a_privileged_account_is_not_sysbox_s_to_recover(self):
        privileged = self.container('alice', ALICE, HostConfig={'Runtime': 'runc', 'Privileged': True})
        privileged['Config']['Labels']['com.docker.compose.service'] = 'dind'
        self.listing(privileged, self.container('bob', BOB))
        self.exec_works('bob')
        result = self.pa_root('host.detectStuckDind', host=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.decode().splitlines()[1:], ['OK    bob'])

    def test_a_recovery_asks_for_its_steps_in_order(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        kill = self.scope(ALICE)
        abort = os.path.join(self.fuse, FUSE_ID, 'abort')
        self.compose_file('alice')
        self.home('alice')
        os.makedirs(os.path.join(self.sysboxfs, ALICE))
        self.routes[('POST', f'/containers/{ALICE}/update')] = (200, {'Warnings': None})
        self.routes[('DELETE', f'/containers/{ALICE}')] = (204, b'')

        result = self.recover('', '')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stdout.decode(), (
            r'\A\[recover-sysbox-dind\] recovering alice id=aaaaaaaaaaaa \(D=\d+\)\n'
            r'\[recover-sysbox-dind\] fuse connection id=44\n'
            r'\[recover-sysbox-dind\] aborted fuse connection 44\n'
            r'\[recover-sysbox-dind\] wrote cgroup.kill\n'
            r'\[recover-sysbox-dind\] docker rm -f ok\n'
            r'\[recover-sysbox-dind\] done alice \(D=\d+\)\n\Z'
        ))
        self.assertEqual(self.asked, [
            ('GET', '/containers/alice/json'),
            ('POST', '/containers/alice/exec'),
            ('POST', f'/exec/{EXEC_ID}/start'),
            ('GET', f'/exec/{EXEC_ID}/json'),
            ('POST', '/containers/alice/exec'),
            ('POST', f'/exec/{EXEC_ID}/start'),
            ('GET', f'/exec/{EXEC_ID}/json'),
            ('GET', '/containers/alice/json'),
            ('POST', f'/containers/{ALICE}/update'),
            ('DELETE', f'/containers/{ALICE}?force=1'),
        ])
        self.assertEqual(json.loads(next(body for kind, path, body, _ in self.sent
                                         if path == f'/containers/{ALICE}/update')),
                         {'RestartPolicy': {'Name': 'no'}})
        with open(abort, 'rb') as f:
            self.assertEqual(f.read(), b'1\n')
        with open(kill, 'rb') as f:
            self.assertEqual(f.read(), b'1')
        with open(os.path.join(self.users, 'alice', 'docker-compose.yml'), 'rb') as f:
            self.assertEqual(f.read(), COMPOSE.replace(b'restart: always', b'restart: "no"')
                             .replace(b'restart: "always"', b'restart: "no"'))
        self.assertFalse(os.path.exists(os.path.join(self.sysboxfs, ALICE)))

    def test_a_failed_remove_aborts_every_fuse_connection_and_retries(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        other = self.abort_file(OTHER_FUSE_ID)
        attempts = []

        def delete(path):
            attempts.append(path)
            return (500, {'message': 'did not receive an exit event'}) if len(attempts) == 1 else (204, b'')

        self.routes[('DELETE', f'/containers/{ALICE}')] = delete
        result = self.recover('', '')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(b'[recover-sysbox-dind] docker rm -f failed \xe2\x80\x94 aborting all fuse connections and retrying\n',
                      result.stdout)
        self.assertIn(b'[recover-sysbox-dind] docker rm -f ok after global fuse abort\n', result.stdout)
        self.assertEqual(len(attempts), 2)
        with open(other, 'rb') as f:
            self.assertEqual(f.read(), b'1\n')

    def test_a_second_failed_remove_exits_2(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        self.routes[('DELETE', f'/containers/{ALICE}')] = (500, {'message': 'did not receive an exit event'})
        result = self.recover('', '')
        self.assertEqual((result.returncode, result.stderr), (
            2, b'[recover-sysbox-dind] ERROR: still cannot remove alice \xe2\x80\x94 host reboot may be required\n',
        ))
        self.assertNotIn(b'done alice', result.stdout)

    def test_the_wipe_refuses_a_symlinked_inner_data_root(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        self.routes[('DELETE', f'/containers/{ALICE}')] = (204, b'')
        target = self.directory('inner')
        kept = os.path.join(target, 'kept')
        open(kept, 'w').close()
        os.symlink(target, os.path.join(self.home('alice'), 'docker'))

        result = self.recover('wipe-inner', '')
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(result.stderr, (f'[recover-sysbox-dind] ERROR: refusing to wipe '
                                         f'{os.path.join(self.homes, "alice", "docker")}: it is a symlink\n').encode())
        self.assertTrue(os.path.exists(kept))

    def test_the_wipe_removes_and_remakes_the_inner_data_root(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        self.routes[('DELETE', f'/containers/{ALICE}')] = (204, b'')
        data_root = os.path.join(self.home('alice'), 'docker')
        os.makedirs(os.path.join(data_root, 'overlay2'))
        open(os.path.join(data_root, 'overlay2', 'old'), 'w').close()

        result = self.recover('wipe-inner', '')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f'[recover-sysbox-dind] wiping inner docker data-root {data_root}\n'.encode(), result.stdout)
        self.assertEqual(os.listdir(data_root), [])
        self.assertEqual(os.stat(data_root).st_mode & 0o777, 0o710)

    def test_the_compose_file_s_restart_lines_are_turned_off_then_back_on(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        self.routes[('DELETE', f'/containers/{ALICE}')] = (204, b'')
        compose = self.compose_file('alice')
        result = self.recover('', '')
        self.assertEqual(result.returncode, 0, result.stderr)
        with open(compose, 'rb') as f:
            self.assertEqual(f.read(), COMPOSE.replace(b'restart: always', b'restart: "no"')
                             .replace(b'restart: "always"', b'restart: "no"'))

        result = self.recover('', 'recreate')
        self.assertEqual(result.returncode, 0, result.stderr)
        with open(compose, 'rb') as f:
            self.assertEqual(f.read(), (
                b'services:\n  dind:\n    restart: always\n  other:\n    restart: always\n    restart: always\n'
            ))
        with open(self.compose_record) as f:
            self.assertEqual(f.read(), os.path.join(self.users, 'alice') + '\n')
        self.assertIn(b'[recover-sysbox-dind] recreating via docker compose up -d in '
                      + os.path.join(self.users, 'alice').encode() + b'\n', result.stdout)
        self.assertIn(b'compose up\n', result.stdout)
        self.assertIn(b'[recover-sysbox-dind] inner docker healthy after recreate\n', result.stdout)

    def test_a_recreated_container_whose_inner_docker_stays_quiet_exits_3(self):
        self.listing(self.container('alice', ALICE))
        self.exec_works('alice')
        self.fuse_mount(ALICE, 1)
        self.routes[('DELETE', f'/containers/{ALICE}')] = (204, b'')
        self.compose_file('alice')
        creates = []

        def create(path):
            creates.append(path)
            if len(creates) > 2:
                return 404, {'message': 'No such container: alice'}
            return 201, {'Id': EXEC_ID}

        self.routes[('POST', '/containers/alice/exec')] = create
        result = self.recover('', 'recreate')
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertEqual(result.stderr, (b'[recover-sysbox-dind] ERROR: recreated outer container but inner docker '
                                         b'not responding yet\n'))

    def test_a_healthy_named_account_is_left_alone(self):
        self.routes[('GET', '/containers/alice/json')] = (200, self.container('alice', ALICE))
        self.exec_works('alice')
        result = self.recover('', '')
        self.assertEqual((result.returncode, result.stdout), (
            0, b'[recover-sysbox-dind] alice is up and its inner docker answers; nothing to recover\n',
        ))
        self.assertNotIn(('DELETE', f'/containers/{ALICE}?force=1'), self.asked)

    def test_a_missing_or_non_sysbox_named_account_exits_1(self):
        result = self.recover('', '')
        self.assertEqual((result.returncode, result.stderr), (1, b'[recover-sysbox-dind] ERROR: container not found: alice\n'))
        self.routes[('GET', '/containers/alice/json')] = (
            200, self.container('alice', ALICE, HostConfig={'Runtime': 'runc'}),
        )
        result = self.recover('', '')
        self.assertEqual((result.returncode, result.stderr), (
            1, b'[recover-sysbox-dind] ERROR: alice is not runtime=sysbox-runc\n',
        ))

    def test_a_named_sysbox_container_that_is_not_the_account_s_exits_1(self):
        self.routes[('GET', '/containers/alice/json')] = (200, self.container('alice', ALICE, Config={'Labels': {
            'com.docker.compose.project': 'alice', 'com.docker.compose.project.working_dir': '/srv/elsewhere/alice',
        }}))
        result = self.recover('', '')
        self.assertEqual((result.returncode, result.stderr), (
            1, b"[recover-sysbox-dind] ERROR: alice is not the account's own container\n",
        ))
        self.assertNotIn(('POST', '/containers/alice/exec'), self.asked)

    def test_all_stuck_recovers_only_stuck_accounts(self):
        self.listing(self.container('alice', ALICE), self.container('bob', BOB))
        self.exec_works('bob')
        self.routes[('DELETE', f'/containers/{ALICE}')] = (204, b'')
        self.routes[('POST', f'/containers/{ALICE}/update')] = (200, {'Warnings': None})
        result = self.pa_root('host.recoverStuckDind', '', '', host=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(b'[recover-sysbox-dind] stuck targets: alice\n', result.stdout)
        self.assertNotIn(b'recovering bob', result.stdout)

    def test_all_stuck_with_nothing_stuck_says_so(self):
        self.listing(self.container('bob', BOB))
        self.exec_works('bob')
        result = self.pa_root('host.recoverStuckDind', 'wipe-inner', 'recreate', host=True)
        self.assertEqual((result.returncode, result.stdout), (
            0, b'[recover-sysbox-dind] no stuck sysbox containers detected\n',
        ))

    def test_an_exec_that_starts_and_never_ends_counts_as_stuck_once_its_bound_is_up(self):
        self.listing(self.container('alice', ALICE))
        self.routes[('POST', '/containers/alice/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, HijackHolding(b''))
        started = time.monotonic()
        result = self.pa_root('host.detectStuckDind', host=True)
        self.assertLess(time.monotonic() - started, 5)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertTrue(result.stdout.endswith(b'STUCK alice\n'), result.stdout)

    def test_the_actions_run_in_the_host_s_namespaces(self):
        self.listing()
        self.pa_root('host.detectStuckDind', host=True)
        with open(os.path.join(self.scratch, 'entered')) as f:
            self.assertEqual(f.read(), 'None')
        self.assertEqual(self.pa_root('host.detectStuckDind').returncode, protocol.EXIT_REFUSED)



if __name__ == '__main__':
    unittest.main()
