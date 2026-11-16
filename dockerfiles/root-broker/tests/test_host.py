import json
import os
import re
import shutil
import socket
import stat
import subprocess
import tempfile
import time
import unittest

import dockerwork
import hostwork
import support
from rootbroker import hostns, peer, protocol
from rootbroker.actions import Policy, buildcache, host, hostscripts, registry
from rootbroker.actions.sequence import Command
from rootbroker.audit import Audit
from rootbroker.refused import Refused
from rootbroker.server import Broker

PRIVILEGED = os.path.join(support.HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged')
GATEWAYS = {'host': 'RootHost.php', 'build-cache': 'RootBuildCache.php'}
WORK = '/opt/panelalpha/shared-hosting/data/build-cache/0a1b2c3d4e5f'
BUILDCTL = ['build', '--progress=plain', '--frontend', 'dockerfile.v0', '--local', 'context=/src', '--local', 'dockerfile=/src',
            '--opt', 'filename=Dockerfile',
            '--export-cache', 'type=local,dest=/out,mode=max,oci-mediatypes=true,image-manifest=true']


class CasesTest(unittest.TestCase):
    def test_each_case_runs_the_sudo_command_and_script_word_for_word(self):
        for case in hostwork.load():
            with self.subTest(case=case['name']):
                scratch = hostwork.Scratch()
                try:
                    plan, _, passing = hostwork.plan_case(scratch, case)
                    expected = hostwork.sudo_argv(case)
                    if expected is None:
                        self.assertIsNone(plan.argv, 'an action the broker answers itself runs no command')
                        self.assertIsNotNone(plan.native)
                    else:
                        self.assertEqual(hostwork.resolved_argv(scratch, plan), expected)
                    self.assertEqual(passing, [])
                    dockerwork.close(plan)
                finally:
                    scratch.close()

    def test_each_host_action_enters_the_namespaces_sudo_entered(self):
        for case in hostwork.load():
            entered = hostwork.entered(case)
            host_request, _, name, _ = dockerwork.split_broker(case['broker'])
            action = registry()[name]
            with self.subTest(case=case['name']):
                if entered is None:
                    self.assertTrue(action.native)
                    self.assertEqual(host_request, action.host_only)
                    continue
                self.assertEqual(host_request, entered != 'sudo')
                self.assertEqual(action.host_only, entered != 'sudo')
                self.assertEqual(action.namespaces, ('net',) if entered == '--net' else None)

    @unittest.skipUnless(os.path.isdir(PRIVILEGED), 'needs the engine checkout around the broker')
    def test_every_gateway_method_has_an_action_and_a_case(self):
        asked = {dockerwork.split_broker(case['broker'])[2] for case in hostwork.load()}
        for area, name in GATEWAYS.items():
            with open(os.path.join(PRIVILEGED, name)) as f:
                methods = set(re.findall(r'public function (\w+)\(', f.read()))
            actions = {action: plan for action, plan in registry().items() if action.startswith(area + '.')}
            with self.subTest(area=area):
                self.assertEqual(methods, {action.removeprefix(area + '.') for action in actions})
                self.assertEqual(set(actions), {action for action in asked if action.startswith(area + '.')})

    @unittest.skipUnless(os.path.isdir(PRIVILEGED), 'needs the engine checkout around the broker')
    def test_the_caches_the_broker_hands_over_are_the_gateways(self):
        with open(os.path.join(PRIVILEGED, '..', '..', 'Lib', 'Deploy', 'ProjectCache.php')) as f:
            names = re.search(r'public const NAMES = \[(.*?)\];', f.read()).group(1).replace('self::COMPOSER', "'composer'")
        self.assertEqual(tuple(re.findall(r"'([^']+)'", names)), hostscripts.CACHE_NAMES)


class Scratch(support.Tree):
    def setUp(self):
        super().setUp()
        self.roots.update({name: os.path.join(self.base, name) for name in ('legacy-js-caches', 'legacy-composer-caches')})
        for name in ('legacy-js-caches', 'legacy-composer-caches'):
            os.makedirs(self.roots[name])
        self.policy = Policy(self.roots, owners={os.getuid()}, script_owners={os.getuid()}, host_root='')
        for directory in (self.base, *self.roots.values()):
            os.chmod(directory, 0o755)

    def plan(self, action, *given, host=True):
        found = registry()[action]
        plan = found.plan(found.check(list(given)), self.policy, host)
        dockerwork.close(plan)
        return plan

    def refused(self, action, *given, host=True, why=None):
        with self.assertRaises(Refused) as caught:
            self.plan(action, *given, host=host)
        if why:
            self.assertIn(why, str(caught.exception))

    def cache(self, account='alice'):
        return self.path('project-caches', account)

    def legacy(self, account='alice'):
        return [os.path.join(self.roots['legacy-js-caches'], account), os.path.join(self.roots['legacy-composer-caches'], account)]

    def script(self, relative, mode=0o644):
        path = os.path.join(self.roots['engine'], relative)
        os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
        with open(path, 'w') as f:
            f.write('#!/bin/sh\n')
        os.chmod(path, mode)
        return path


class FirewallScriptTest(Scratch):
    ACTIONS = ('firewall.refusedRules', 'firewall.applyTrustedLists')

    def setUp(self):
        super().setUp()
        owners = {os.getuid(), os.getgid()}
        self.policy = Policy(self.roots, owners=owners, script_owners=owners, host_root='')
        self.firewall = self.script('scripts/firewall.sh')
        self.provider = self.script('scripts/firewall/ufw.sh')

    def refuses_both(self, why):
        for action in self.ACTIONS:
            with self.subTest(action=action):
                self.refused(action, why=why)

    def test_the_firewall_script_root_alone_can_change_runs_by_its_name(self):
        self.assertEqual(self.plan('firewall.refusedRules').argv, ['bash', self.firewall, '--refused'])
        self.assertIsNotNone(self.plan('firewall.applyTrustedLists').native, 'the broker runs it on the host daemon')

    def test_a_firewall_script_its_own_group_could_write_is_refused(self):
        os.chmod(self.firewall, 0o664)
        self.refuses_both(f"'{self.firewall}' could have been written by someone other than root")

    def test_a_provider_script_or_its_directory_anyone_else_could_write_is_refused(self):
        for path, mode in ((self.provider, 0o664), (self.provider, 0o646), (os.path.dirname(self.provider), 0o775)):
            with self.subTest(path=path, mode=oct(mode)):
                kept = os.stat(path).st_mode & 0o777
                os.chmod(path, mode)
                try:
                    self.refuses_both(f"'{path}' could have been written by someone other than root")
                finally:
                    os.chmod(path, kept)

    def test_a_provider_directory_that_is_a_symlink_is_refused(self):
        shutil.rmtree(os.path.dirname(self.provider))
        elsewhere = os.path.join(self.roots['exchange'], 'firewall')
        os.makedirs(elsewhere, mode=0o755)
        self.make('exchange', 'firewall/ufw.sh')
        os.symlink(elsewhere, os.path.dirname(self.provider))
        self.refuses_both('symlink')

    def test_a_firewall_script_someone_else_owns_is_refused(self):
        self.policy = Policy(self.roots, owners={os.getuid()}, script_owners={os.getuid() + 1}, host_root='')
        self.refuses_both('someone other than root')


class HostOnlyTest(Scratch):
    NATIVE = {
        'detectStuckDind': [],
        'recoverStuckDind': ['wipe-inner', 'recreate'],
        'recoverDindAccount': ['alice', 'wipe-inner', 'recreate'],
    }

    def test_every_host_action_refuses_a_request_outside_the_hosts_namespaces(self):
        for name, action in host.ACTIONS.items():
            with self.subTest(action=name):
                self.assertTrue(action.host_only)
                with self.assertRaises(Refused):
                    action.plan(action.check(self.some_values(name)), self.policy, False)

    def test_no_build_cache_action_runs_in_the_hosts_namespaces(self):
        for name, action in buildcache.ACTIONS.items():
            with self.subTest(action=name):
                self.assertFalse(action.host_only or action.on_host)

    def some_values(self, name):
        if name in self.NATIVE:
            return self.NATIVE[name]
        for case in hostwork.load():
            _, _, asked, given = dockerwork.split_broker(case['broker'])
            if asked == 'host.' + name:
                return given
        raise AssertionError(name)


class AccountDockerTest(Scratch):
    def removal(self, cache=None, legacy=None, docker=None):
        legacy = self.legacy() if legacy is None else legacy
        return [cache or self.cache(), str(len(legacy)), *legacy, docker or self.path('homes', 'alice', 'docker')]

    def store(self, volume='pa-nm-alice', images=('redis:7',)):
        return [volume, str(len(images)), *images]

    def test_the_accounts_caches_and_docker_directory_are_removed_by_name(self):
        plan = self.plan('host.removeAccountDockerFiles', *self.removal())
        self.assertEqual(plan.argv, ['rm', '-rf', '--', self.cache(), *self.legacy(), self.path('homes', 'alice', 'docker')])

    def test_the_volume_and_the_images_are_left_to_the_broker_and_its_daemon(self):
        found = registry()['host.removeAccountDockerStore']
        plan = found.plan(found.check(self.store()), self.policy, host=True)
        self.assertIsNone(plan.argv)
        self.assertIsNotNone(plan.native)

    def test_another_accounts_cache_or_an_unrelated_path_is_refused(self):
        self.refused('host.removeAccountDockerFiles', *self.removal(cache=self.cache('bob')))
        self.refused('host.removeAccountDockerFiles', *self.removal(cache=self.path('homes', 'alice')))
        self.refused('host.removeAccountDockerFiles', *self.removal(legacy=[self.path('engine', 'alice')]))
        self.refused('host.removeAccountDockerFiles', *self.removal(docker=self.path('homes', 'alice', 'project')))
        self.refused('host.removeAccountDockerFiles', *self.removal(docker=self.path('homes', 'docker')))
        self.refused('host.removeAccountDockerStore', *self.store(volume='alice'))
        self.refused('host.removeAccountDockerStore', *self.store(volume='pa-nm-..'))
        self.refused('host.removeAccountDockerStore', *self.store(images=('redis:7; id',)))

    def test_a_home_that_is_a_symlink_is_refused(self):
        os.symlink(self.roots['engine'], self.path('homes', 'alice'))
        self.refused('host.removeAccountDockerFiles', *self.removal(), why='is a symlink')

    def test_a_cache_directory_that_is_a_symlink_is_refused(self):
        os.symlink(self.roots['engine'], self.cache())
        self.refused('host.removeAccountDockerFiles', *self.removal(), why='is a symlink')


class ProjectCacheTest(Scratch):
    def test_the_caches_are_prepared_for_the_account_and_never_for_root(self):
        plan = self.plan('host.prepareProjectCache', 'alice', '1001:1002')
        self.assertEqual(plan.argv[:2], ['sh', '-c'])
        for owner in ('0:0', '0:1002', '1001:0', '1001', 'root:root', '1001:1002; id'):
            with self.subTest(owner=owner):
                self.refused('host.prepareProjectCache', 'alice', owner)

    def test_a_cache_directory_that_is_a_symlink_is_refused(self):
        os.symlink(self.roots['engine'], self.cache())
        self.refused('host.prepareProjectCache', 'alice', '1001:1002', why='is a symlink')
        os.unlink(self.cache())
        os.symlink('/etc', self.legacy()[0])
        self.refused('host.prepareProjectCache', 'alice', '1001:1002', why='is a symlink')


FOLLOWING_CHOWN = (
    'for word; do [ "$word" = -R ] && recursive=1; done; for word; do case "$word" in /*) '
    'if [ -n "$recursive" ]; then find -L "$word" -exec realpath -- {} +; else realpath -- "$word"; fi;; esac; done'
)


class CachePreparationScriptTest(unittest.TestCase):
    """The generated script on a scratch tree, with `chown` recorded instead of run, and what a `chown` that follows
    every link (as uutils' does for a link to a directory, -h or not) would have reached."""

    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-cache-'))
        self.directory = os.path.join(self.base, 'projects', 'alice')
        self.legacy_js = os.path.join(self.base, 'js', 'alice')
        self.legacy_composer = os.path.join(self.base, 'pa-composer', 'alice')
        self.outside = os.path.join(self.base, 'outside')
        os.makedirs(self.directory)
        os.makedirs(self.outside)
        with open(os.path.join(self.outside, 'keep'), 'w') as f:
            f.write('keep')
        self.shim = os.path.join(self.base, 'bin')
        os.makedirs(self.shim)
        self.calls = os.path.join(self.base, 'chown-calls')
        self.reached = os.path.join(self.base, 'chown-reached')
        with open(os.path.join(self.shim, 'chown'), 'w') as f:
            f.write(f'#!/bin/sh\nprintf "%s\\n" "$*" >> {self.calls}\n{FOLLOWING_CHOWN} >> {self.reached}\n')
        os.chmod(os.path.join(self.shim, 'chown'), 0o755)

    def tearDown(self):
        subprocess.run(['rm', '-rf', '--', self.base])

    def run_script(self, identity='1001:1002'):
        script = hostscripts.project_cache_preparation(self.directory, self.legacy_js, self.legacy_composer, identity)
        env = {**os.environ, 'PATH': self.shim + ':' + os.environ['PATH']}
        return subprocess.run(['sh', '-c', script], env=env, capture_output=True)

    def chowned(self):
        with open(self.calls) as f:
            return f.read().splitlines()

    def reached_outside(self):
        with open(self.reached) as f:
            return [path for path in f.read().splitlines() if path == self.outside or path.startswith(self.outside + '/')]

    def test_the_directory_is_roots_and_only_the_caches_inside_it_are_the_accounts(self):
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        calls = self.chowned()
        self.assertEqual(calls[0], f'-h -- 0:1002 {self.directory}')
        self.assertEqual(calls[1:], [f'-h -- 1001:1002 {self.directory}/{name}' for name in hostscripts.CACHE_NAMES])

    def test_a_link_the_build_left_inside_a_cache_never_takes_roots_chown_outside_it(self):
        os.makedirs(os.path.join(self.directory, 'node_modules', 'pkg'))
        os.symlink(self.outside, os.path.join(self.directory, 'node_modules', 'pkg', 'escape'))
        os.makedirs(os.path.join(self.directory, 'npm'))
        os.symlink(os.path.join(self.outside, 'keep'), os.path.join(self.directory, 'npm', 'escape'))
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(self.reached_outside())
        self.assertTrue(os.path.islink(os.path.join(self.directory, 'node_modules', 'pkg', 'escape')))
        self.assertEqual(os.listdir(self.outside), ['keep'])
        self.assertEqual(stat.S_IMODE(os.stat(self.directory).st_mode), 0o750)
        for name in hostscripts.CACHE_NAMES:
            self.assertTrue(stat.S_ISDIR(os.lstat(os.path.join(self.directory, name)).st_mode), name)

    def test_a_cache_the_build_turned_into_a_link_or_a_file_is_a_directory_again(self):
        os.symlink(self.outside, os.path.join(self.directory, 'node_modules'))
        with open(os.path.join(self.directory, 'composer'), 'w') as f:
            f.write('x')
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        for name in ('node_modules', 'composer'):
            self.assertTrue(stat.S_ISDIR(os.lstat(os.path.join(self.directory, name)).st_mode), name)
        self.assertEqual(os.listdir(self.outside), ['keep'])
        self.assertFalse([call for call in self.chowned() if self.outside in call])

    def test_the_old_layout_moves_in_and_a_link_it_carried_is_replaced(self):
        os.makedirs(self.legacy_js)
        os.makedirs(os.path.join(self.legacy_js, 'npm'))
        os.symlink(self.outside, os.path.join(self.legacy_js, 'node_modules'))
        os.makedirs(self.legacy_composer)
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(os.path.exists(self.legacy_js))
        self.assertFalse(os.path.exists(self.legacy_composer))
        self.assertTrue(stat.S_ISDIR(os.lstat(os.path.join(self.directory, 'node_modules')).st_mode))
        self.assertEqual(os.listdir(self.outside), ['keep'])

    def test_nothing_is_handed_over_when_the_directory_cannot_be_made_roots(self):
        with open(os.path.join(self.shim, 'chown'), 'w') as f:
            f.write(f'#!/bin/sh\nprintf "%s\\n" "$*" >> {self.calls}\nexit 1\n')
        result = self.run_script()
        self.assertEqual(result.returncode, 1)
        self.assertEqual(len(self.chowned()), 1)
        self.assertFalse(os.path.exists(os.path.join(self.directory, 'npm')))


@support.needs_root
class CachePreparationAsRootTest(CachePreparationScriptTest):
    def run_script(self, identity='54321:54322'):
        script = hostscripts.project_cache_preparation(self.directory, self.legacy_js, self.legacy_composer, identity)
        return subprocess.run(['sh', '-c', script], capture_output=True)

    def chowned(self):
        return []

    def reached_outside(self):
        return [path for path in (self.outside, os.path.join(self.outside, 'keep')) if os.lstat(path).st_uid == 54321]

    def test_the_directory_is_roots_and_only_the_caches_inside_it_are_the_accounts(self):
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        status = os.stat(self.directory)
        self.assertEqual((status.st_uid, status.st_gid, stat.S_IMODE(status.st_mode)), (0, 54322, 0o750))
        for name in hostscripts.CACHE_NAMES:
            cache = os.stat(os.path.join(self.directory, name))
            self.assertEqual((cache.st_uid, cache.st_gid), (54321, 54322), name)

    def test_nothing_is_handed_over_when_the_directory_cannot_be_made_roots(self):
        pass


class EngineScriptTest(Scratch):
    def test_a_script_the_engine_ships_runs_by_its_name(self):
        script = self.script('scripts/prune-project-caches.sh')
        self.assertEqual(self.plan('host.pruneProjectCaches', '60', '').argv, ['sh', script, '60'])

    def test_a_missing_engine_directory_fails_as_it_did(self):
        shutil.rmtree(self.roots['engine'])
        self.assertEqual(self.plan('host.pruneProjectCaches', '60', '').argv,
                         ['sh', self.roots['engine'] + '/scripts/prune-project-caches.sh', '60'])
        self.assertEqual(self.plan('firewall.refusedRules').argv, ['bash', self.roots['engine'] + '/scripts/firewall.sh', '--refused'])
        for action in ('host.applyTenantNetworkFirewall', 'firewall.applyTrustedLists'):
            with self.subTest(action=action):
                self.assertIsNotNone(self.plan(action).native)

    def test_a_script_that_is_a_symlink_or_below_one_is_refused(self):
        elsewhere = self.make('exchange', 'evil.sh')
        os.symlink(elsewhere, os.path.join(self.roots['engine'], 'webserver.sh'))
        self.refused('host.changeWebserver', self.roots['engine'], 'nginx', '', why='symlink')
        os.symlink(self.roots['exchange'], os.path.join(self.roots['engine'], 'scripts'))
        self.refused('host.pruneProjectCaches', '60', '', why='symlink')

    def test_a_script_someone_else_could_have_written_is_refused(self):
        self.script('scripts/letsencrypt-request-cert.sh', 0o666)
        self.refused('host.requestEngineCertificate', self.roots['engine'], '', '', '', why='someone other')

    def test_a_script_or_a_directory_above_it_its_own_group_could_write_is_refused(self):
        script = self.script('scripts/letsencrypt-request-cert.sh')
        owners = {os.getuid(), os.getgid()}
        self.policy = Policy(self.roots, owners=owners, script_owners=owners, host_root='')
        self.plan('host.requestEngineCertificate', self.roots['engine'], '', '', '')
        for path, mode in ((script, 0o664), (os.path.dirname(script), 0o775), (self.roots['engine'], 0o775)):
            with self.subTest(path=path, mode=oct(mode)):
                kept = os.stat(path).st_mode & 0o777
                os.chmod(path, mode)
                try:
                    self.refused('host.requestEngineCertificate', self.roots['engine'], '', '', '',
                                 why=f"'{path}' could have been written by someone other than root; once it is known to be "
                                     f"the engine's own, run on the host: chmod go-w {path}")
                finally:
                    os.chmod(path, kept)

    def test_the_engine_directory_must_be_the_engines(self):
        for action, given in (('host.changeWebserver', ('nginx', '')), ('host.enqueueEngineUpdate', ('',)),
                              ('host.requestEngineCertificate', ('', '', ''))):
            with self.subTest(action=action):
                self.refused(action, self.roots['exchange'], *given, why="engine's directory")

    def test_the_certificate_takes_only_known_flags_once(self):
        engine = self.roots['engine']
        self.refused('host.requestEngineCertificate', engine, '', '', '', 'staging', 'staging')
        self.refused('host.requestEngineCertificate', engine, '', '', '', '--agree-tos')
        self.refused('host.requestEngineCertificate', engine, '*.example.com', '', '')
        self.refused('host.requestEngineCertificate', engine, '', '-a@b.c', '')
        self.refused('host.requestEngineCertificate', engine, '', '', 'fe80::1%eth0')

    def test_the_license_key_and_the_serial_never_reach_the_audit(self):
        self.assertEqual(host.ACTIONS['enqueueEngineUpdate'].audited(['/e', 'LIC-1']), ['/e', '<secret>'])
        self.assertEqual(host.ACTIONS['changeWebserver'].audited(['/e', 'litespeed', 'SER-1']), ['/e', 'litespeed', '<secret>'])
        self.refused('host.enqueueEngineUpdate', self.roots['engine'], '--no-verify')


class ReadingsTest(Scratch):
    def test_a_home_must_be_directly_below_the_homes(self):
        self.plan('host.projectDisk', '1', 'shop', self.path('homes', 'shop') + '/')
        self.refused('host.projectDisk', '1', 'shop', self.path('homes', 'shop', 'docker'))
        self.refused('host.projectDisk', '1', 'shop', self.path('engine', 'shop'))
        self.refused('host.classicAppTypes', '1', 'shop', self.path('homes', 'shop'), self.path('homes', 'bob', 'public_html'), '')
        self.refused('host.classicAppTypes', '1', 'shop', self.path('homes', 'shop'), self.path('homes', 'shop', 'public_html'),
                     self.path('homes', 'bob', 'public_html', 'blog'))

    def test_the_edge_probe_takes_a_plain_name_and_an_address(self):
        self.plan('host.edgeProbe', 'shop.example.com', '::ffff:203.0.113.7', '4', '')
        for domain, address in (('*.example.com', '203.0.113.7'), ('shop.example.com', '203.0.113.7;id'),
                                ('shop.example.com', ' 203.0.113.7'), ('shop.example.com', 'fe80::1%eth0')):
            with self.subTest(domain=domain, address=address):
                self.refused('host.edgeProbe', domain, address, '4', '')

    def test_a_pid_or_a_timeout_must_be_positive(self):
        self.refused('host.processExists', '0')
        self.refused('host.processExists', '-1')
        self.refused('host.projectStats', '0', 'shop')


class TerminalTest(unittest.TestCase):
    def test_only_an_accounts_exec_runs_in_a_terminal_and_no_host_action_does(self):
        self.assertEqual([name for name, action in registry().items() if getattr(action, 'terminal', False)],
                         ['compose.execWithTty'])


class HttpRequestTest(Scratch):
    def test_curl_reaches_only_the_cache_registry_writer(self):
        self.plan('host.httpRequest', 'GET', 'http://127.0.0.1:5000/v2/_catalog?n=1000', '1', 'Accept', 'application/json')
        for given in (
            ('GET', 'http://127.0.0.1:5000/debug/vars', '0'),
            ('GET', 'http://169.254.169.254/latest/meta-data/', '0'),
            ('GET', 'file:///etc/shadow', '0'),
            ('GET', 'http://127.0.0.1:5000/v2/ x', '0'),
            ('PUT', 'http://127.0.0.1:5000/v2/a/manifests/1', '0'),
            ('GET', 'http://127.0.0.1:5000/v2/', '1', 'Authorization', 'Basic eA=='),
            ('GET', 'http://127.0.0.1:5000/v2/', '1', 'Accept', 'a\r\nX-Injected: 1'),
        ):
            with self.subTest(given=given):
                self.refused('host.httpRequest', *given)

    def test_it_enters_only_the_hosts_network(self):
        self.assertEqual(host.ACTIONS['httpRequest'].namespaces, ('net',))


class BuildCacheTest(Scratch):
    def work(self, leaf, directory='0a1b2c3d4e5f'):
        return os.path.join(self.roots['engine'], 'data', 'build-cache', directory, leaf)

    def build(self, read_only=None, writable=None, arguments=None, network='panelalpha-build', limit='4g'):
        read_only = [('/src', self.work('src'))] if read_only is None else read_only
        writable = [('/out', self.work('out-0a1b2c3d'))] if writable is None else writable
        given = [network, limit, '4096', str(len(read_only))]
        for pair in read_only:
            given += pair
        given.append(str(len(writable)))
        for pair in writable:
            given += pair
        return [*given, *(BUILDCTL if arguments is None else arguments)]

    def test_a_build_of_its_own_work_directory_runs(self):
        self.plan('build-cache.buildKit', *self.build(), host=False)

    def test_a_mount_from_anywhere_else_is_refused(self):
        for read_only, writable in (
            ([('/src', self.path('homes', 'alice', 'project'))], None),
            ([('/src', '/')], None),
            ([('/src', self.work('plan'))], None),
            ([('/src', self.work('src', 'ffffffffffff'))], None),
            ([('/src', self.work('src')), ('/etc', self.work('src'))], None),
            (None, [('/out', self.work('out-0a1b2c3d')), ('/src', self.work('src'))]),
            (None, [('/out', self.work('src'))]),
            (None, []),
            ([('/layouts/../x', self.work('layout-0a1b2c3d'))], None),
        ):
            with self.subTest(read_only=read_only, writable=writable):
                self.refused('build-cache.buildKit', *self.build(read_only, writable), host=False)

    def test_a_symlinked_source_is_refused(self):
        os.makedirs(os.path.dirname(self.work('src')))
        os.symlink('/', self.work('src'))
        self.refused('build-cache.buildKit', *self.build(), host=False, why='symlink')

    def test_no_buildkit_option_but_the_engines_own(self):
        tail = BUILDCTL[-2:]
        head = BUILDCTL[:8]
        for arguments in (
            [],
            ['build', '--progress=plain', '--allow', 'security.insecure', *BUILDCTL[2:]],
            [*head, '--output', 'type=local,dest=/out', *tail],
            [*head, '--opt', 'filename=Dockerfile', '--opt', 'source=evil/frontend', *tail],
            [*head, '--oci-layout', 'base0=/etc', *tail],
            [*BUILDCTL[:5], 'context=/etc', *BUILDCTL[6:]],
            [*BUILDCTL[:-1], 'type=registry,ref=evil'],
        ):
            with self.subTest(arguments=arguments):
                self.refused('build-cache.buildKit', *self.build(arguments=arguments), host=False)

    def test_a_layout_context_names_a_mounted_store(self):
        layout = [('/src', self.work('src')), ('/layouts/base0', self.work('layout-0a1b2c3d'))]
        context = '--opt', 'context:acme/base:1=oci-layout://base0:1@sha256:' + '0a' * 32
        arguments = [*BUILDCTL[:8], '--oci-layout', 'base0=/layouts/base0', *context, *BUILDCTL[-2:]]
        self.plan('build-cache.buildKit', *self.build(layout, arguments=arguments), host=False)
        self.refused('build-cache.buildKit', *self.build(arguments=arguments), host=False)

    def test_the_host_network_and_no_memory_limit_are_refused(self):
        self.refused('build-cache.buildKit', *self.build(network='host'), host=False)
        self.refused('build-cache.buildKit', *self.build(limit='0'), host=False)
        self.refused('build-cache.railpackPrepare', 'host', '4g', '4096', 'acme/a:1', self.work('src'), self.work('plan'), host=False)

    def test_the_layout_copy_reaches_only_the_writer(self):
        layout = self.work('layout-0a1b2c3d')
        self.plan('build-cache.copyImageToLayout', '127.0.0.1:5000', '127.0.0.1:5000/acme/a:1', layout, '1', host=False)
        self.refused('build-cache.copyImageToLayout', 'ghcr.io', 'ghcr.io/acme/a:1', layout, '1', host=False)
        self.refused('build-cache.copyImageToLayout', '127.0.0.1:5000', 'ghcr.io/acme/a:1', layout, '1', host=False)
        self.refused('build-cache.copyImageToLayout', '127.0.0.1:5000', '127.0.0.1:5000/acme/a:1', self.work('src'), '1', host=False)


DETACHED = {
    **registry(),
    'test.detached': Command(
        lambda given: (given.one(str), given.one(str)),
        lambda context, release, done: [
            'sh', '-c', 'sh -c \'while [ ! -e "$1" ]; do sleep 0.02; done; echo done > "$2"\' job "$0" "$1" & echo started',
            release, done,
        ],
        detach=True,
    ),
    'test.detachedWithoutStdout': Command(
        lambda given: (given.one(str), given.one(str)),
        lambda context, release, done: [
            'sh', '-c', 'exec >&-; sh -c \'while [ ! -e "$1" ]; do sleep 0.02; done; echo late >&2; echo done > "$2"\' '
            'job "$0" "$1" & echo started >&2; sleep 0.5; exit 3',
            release, done,
        ],
        detach=True,
    ),
}


class DetachedTest(support.Serving):
    actions = DETACHED

    def test_the_caller_is_answered_when_the_command_exits_and_the_job_it_left_keeps_running(self):
        release, done = self.path('exchange', 'release'), self.path('exchange', 'done')
        result = self.pa_root('test.detached', release, done)
        self.assertEqual((result.returncode, result.stdout), (0, b'started\n'), result.stderr)
        self.assertFalse(os.path.exists(done))
        running = [json.loads(line.removeprefix('pa-root-audit ')) for line in self.audit_lines()]
        self.assertEqual([(entry['action'], entry['exit'], entry.get('detached')) for entry in running],
                         [('test.detached', 0, 'running')])
        open(release, 'w').close()
        self.wait_for(lambda: os.path.exists(done) and len(self.audit_lines()) == 2)
        ended = json.loads(self.audit_lines()[-1].removeprefix('pa-root-audit '))
        self.assertEqual((ended['exit'], ended['detached']), (0, 'ended'))

    def test_a_command_that_closed_its_stdout_is_answered_with_its_exit_code_while_its_job_holds_stderr(self):
        release, done = self.path('exchange', 'release'), self.path('exchange', 'done')
        result = self.pa_root('test.detachedWithoutStdout', release, done)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (3, b'', b'started\n'))
        self.wait_for(lambda: len(self.audit_lines()) == 1)
        running = json.loads(self.audit_lines()[0].removeprefix('pa-root-audit '))
        self.assertEqual((running['exit'], running['detached']), (3, 'running'))
        open(release, 'w').close()
        self.wait_for(lambda: os.path.exists(done) and len(self.audit_lines()) == 2)
        ended = json.loads(self.audit_lines()[-1].removeprefix('pa-root-audit '))
        self.assertEqual((ended['exit'], ended['detached']), (3, 'ended'))

    def wait_for(self, condition):
        for _ in range(500):
            if condition():
                return
            time.sleep(0.02)
        self.fail('the detached job did not end')


class NamespacesTest(unittest.TestCase):
    def test_only_the_named_namespaces_are_entered_and_the_root_only_with_mnt(self):
        target = tempfile.mkdtemp(prefix='broker-ns-')
        cwd = os.getcwd()
        try:
            os.makedirs(os.path.join(target, 'ns'))
            os.makedirs(os.path.join(target, 'root'))
            os.symlink('/proc/self/ns/net', os.path.join(target, 'ns', 'net'))
            hostns.enter(('net',), target)
            if os.geteuid() != 0:
                with self.assertRaises(PermissionError):
                    hostns.enter(None, target)
        finally:
            os.chdir(cwd)
            subprocess.run(['rm', '-rf', '--', target])

    def test_the_broker_enters_what_the_action_names(self):
        entered = []
        actions = {'test.net': Command(lambda given: (), lambda context: ['true'], host_only=True, namespaces=('net',)),
                   'test.all': Command(lambda given: (), lambda context: ['true'], host_only=True)}
        ours_pid = os.getpid()
        broker = Broker({os.getuid()}, actions=actions, audit=Audit(os.open(os.devnull, os.O_WRONLY)),
                        enter_host=entered.append, core=peer.Core(locate=lambda: ours_pid))
        broker.prepare()
        for name in actions:
            ours, theirs = socket.socketpair()
            theirs.sendall(protocol.request(name, [], True) + protocol.frame(protocol.STDIN_END))
            self.assertEqual(broker.handle(ours, os.getpid(), os.getuid(), {}), 0)
            ours.close()
            theirs.close()
        self.assertEqual(entered, [('net',), None])

    def test_a_host_the_broker_cannot_enter_is_named_in_the_answer(self):
        missing = os.path.join(tempfile.gettempdir(), 'broker-ns-missing', str(os.getpid()))
        actions = {'test.all': Command(lambda given: (), lambda context: ['true'], host_only=True)}
        ours_pid = os.getpid()
        broker = Broker({os.getuid()}, actions=actions, audit=Audit(os.open(os.devnull, os.O_WRONLY)),
                        enter_host=lambda names: hostns.enter(names, missing), core=peer.Core(locate=lambda: ours_pid))
        broker.prepare()
        ours, theirs = socket.socketpair()
        with ours, theirs:
            theirs.sendall(protocol.request('test.all', [], True) + protocol.frame(protocol.STDIN_END))
            broker.serve(ours)
            frames = support.read_reply(theirs)
        err = b''.join(payload for kind, payload in frames if kind == protocol.STDERR).decode()
        code = next(protocol.parse_exit(payload) for kind, payload in frames if kind == protocol.EXIT)
        self.assertEqual((code, err), (1, "pa-root: cannot enter the host's namespaces: No such file or directory\n"))


if __name__ == '__main__':
    unittest.main()
