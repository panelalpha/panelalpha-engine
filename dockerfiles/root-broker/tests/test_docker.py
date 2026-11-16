import json
import os
import re
import unittest

import dockerwork
import support
from rootbroker import values
from rootbroker.actions import Policy, compose, engineservices, registry
from rootbroker.actions.sequence import Command
from rootbroker.refused import Refused

PRIVILEGED = os.path.join(support.HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged')
GATEWAYS = {
    'docker': ('RootDocker.php',),
    'compose': ('RootCompose.php', 'RootEngineCompose.php', 'RootEngineComposeLines.php', 'RootEngineServices.php'),
}
NOT_ACTIONS = {'isRun', 'withRunLabel'}
RENAMED = {
    ('RootEngineCompose.php', 'upWithoutDependencies'): 'engineUpWithoutDependencies',
    ('RootEngineServices.php', 'runningServices'): 'engineRunningServices',
}
ACCOUNT_FILE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml'
ENGINE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml'


class CasesTest(unittest.TestCase):
    def test_each_case_runs_the_sudo_command_word_for_word(self):
        for case in dockerwork.load():
            with self.subTest(case=case['name']):
                scratch = dockerwork.Scratch()
                try:
                    plan, passing = dockerwork.plan_case(scratch, case)
                    if 'sudo' not in case:
                        self.assertIsNone(plan.argv, 'an action the broker answers itself runs no command')
                        self.assertIsNotNone(plan.native)
                        dockerwork.close(plan)
                        continue
                    expected, passed = dockerwork.unprivileged(case['sudo'], case['host'])
                    self.assertEqual(dockerwork.resolved_argv(scratch, plan), expected)
                    self.assertEqual(passing, passed)
                    dockerwork.close(plan)
                finally:
                    scratch.close()

    def test_the_compose_file_is_handed_over_in_the_directory_the_walk_opened(self):
        for case in dockerwork.load():
            if 'compose' not in (case.get('sudo') or []):
                continue
            with self.subTest(case=case['name']):
                scratch = dockerwork.Scratch()
                try:
                    plan, _ = dockerwork.plan_case(scratch, case)
                    self.assertIsNotNone(plan.cwd)
                    self.assertTrue({'./docker-compose.yml', '.'} & set(plan.argv), plan.argv)
                    self.assertFalse([word for word in plan.argv if 'docker-compose.yml' in word and word.startswith('/')])
                    dockerwork.close(plan)
                finally:
                    scratch.close()

    @unittest.skipUnless(os.path.isdir(PRIVILEGED), 'needs the engine checkout around the broker')
    def test_every_gateway_method_has_an_action_and_a_case(self):
        asked = {dockerwork.split_broker(case['broker'])[2] for case in dockerwork.load()}
        asked |= {name for name, action in registry().items() if action.native}
        for area, files in GATEWAYS.items():
            methods = set()
            for name in files:
                with open(os.path.join(PRIVILEGED, name)) as f:
                    found = set(re.findall(r'public function (\w+)\(', f.read())) - {'__construct'} - NOT_ACTIONS
                methods |= {RENAMED.get((name, method), method) for method in found} - {None}
            actions = {name.removeprefix(area + '.') for name in registry() if name.startswith(area + '.')}
            with self.subTest(area=area):
                self.assertEqual(methods, actions)
                self.assertEqual(actions, {name.removeprefix(area + '.') for name in asked if name.startswith(area + '.')})


class Scratch(support.Tree):
    def setUp(self):
        super().setUp()
        self.policy = Policy(self.roots, owners={os.getuid()}, host_root='', engine_owners={os.getuid()},
                             render=support.no_services)
        for relative in ('docker-compose.yml', 'users/alice/docker-compose.yml'):
            self.make('engine', relative, b'services: {}\n')
        os.makedirs(self.path('homes', 'alice', 'project'))

    def plan(self, action, *given, host=False):
        found = registry()[action]
        plan = found.plan(found.check(list(given)), self.policy, host)
        for fd in [plan.cwd, *plan.fds]:
            if fd is not None:
                os.close(fd)
        return plan

    def refused(self, action, *given, host=False, why=None):
        with self.assertRaises(Refused) as caught:
            self.plan(action, *given, host=host)
        if why:
            self.assertIn(why, str(caught.exception))

    def account_file(self):
        return self.path('engine', 'users', 'alice', 'docker-compose.yml')

    def engine_file(self):
        return self.path('engine', 'docker-compose.yml')


def build_values(user='1001:1001', network='panelalpha-build', mounts=None, read_only=None, environment=(), image='node:22'):
    mounts = [('/app', '/x')] if mounts is None else mounts
    flat = ['0', user, '2048m', network, str(len(mounts))]
    for target, source in mounts:
        flat += [target, source]
    read_only = read_only or []
    flat.append(str(len(read_only)))
    for target, source in read_only:
        flat += [target, source]
    flat += ['/app', str(len(environment))]
    for name, value in environment:
        flat += [name, value]
    return [*flat, image, 'npm ci']


class BuildRefusalsTest(Scratch):
    def checkout(self):
        return self.path('homes', 'alice', 'project')

    def test_a_build_of_the_accounts_checkout_and_caches_runs(self):
        cache = self.path('project-caches', 'alice', 'node_modules')
        self.plan('docker.nodeBuild', *build_values(
            mounts=[('/app', self.checkout()), ('/var/cache/pa-js', os.path.dirname(cache)), ('/app/node_modules', cache)],
            read_only=[('/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/certs/ca-certificates.crt')],
        ))

    def test_a_build_as_root_is_refused(self):
        for user in ('0', '0:0', '00:1001', '1001:0', 'root', 'root:root', 'www-data:www-data'):
            with self.subTest(user=user):
                self.refused('docker.nodeBuild', *build_values(user=user, mounts=[('/app', self.checkout())]))

    def test_a_build_on_the_hosts_network_is_refused(self):
        self.refused('docker.phpBuild', *build_values(network='host', mounts=[('/app', self.checkout())]))

    def test_a_bind_mount_from_anywhere_but_the_checkout_and_the_caches_is_refused(self):
        sources = ['/etc', '/', '/var/run/docker.sock', self.path('homes', 'alice'), self.path('homes', 'alice', '.ssh'),
                   self.path('homes', 'alice', 'project', 'sub'), self.path('engine', 'users', 'alice'),
                   self.path('project-caches', 'alice', 'other'), self.path('homes', 'alice', 'docker')]
        for source in sources:
            with self.subTest(source=source):
                self.refused('docker.composerInstall', *build_values(mounts=[('/app', source)]))

    def test_a_read_only_mount_other_than_the_hosts_ca_bundle_is_refused(self):
        for target, source in (('/etc/ssl/certs/ca-certificates.crt', '/etc/shadow'),
                               ('/app/x', '/etc/ssl/certs/ca-certificates.crt'), ('/x', self.checkout())):
            with self.subTest(source=source, target=target):
                self.refused('docker.nodeBuild', *build_values(mounts=[('/app', self.checkout())], read_only=[(target, source)]))

    def test_a_symlinked_checkout_or_cache_is_refused(self):
        os.rmdir(self.checkout())
        os.symlink('/etc', self.checkout())
        self.refused('docker.nodeBuild', *build_values(mounts=[('/app', self.checkout())]), why='is a symlink')
        cache = self.directory('project-caches', 'bob')
        os.symlink(self.path('homes'), os.path.join(cache, 'node_modules'))
        self.refused('docker.nodeBuild', *build_values(mounts=[('/app/node_modules', os.path.join(cache, 'node_modules'))]),
                     why='is a symlink')

    def test_a_symlinked_directory_on_the_way_is_refused(self):
        os.symlink(self.path('homes', 'alice'), self.path('homes', 'mallory'))
        self.refused('docker.nodeBuild', *build_values(mounts=[('/app', self.path('homes', 'mallory', 'project'))]),
                     why='is a symlink')

    def test_a_missing_source_is_left_to_the_daemon_as_under_sudo(self):
        self.plan('docker.phpBuild', *build_values(mounts=[('/app/x', self.path('project-caches', 'carol', 'composer'))]))

    def test_the_litespeed_serial_is_mounted_only_from_where_the_engine_writes_it(self):
        good = self.path('engine', 'webserver-config', 'litespeed', 'serial.no.tmp')
        self.plan('docker.checkLiteSpeedSerial', '0', good)
        for serial in ('/etc/shadow', self.path('engine', '.env'), self.path('homes', 'alice', 'serial.no.tmp')):
            with self.subTest(serial=serial):
                self.refused('docker.checkLiteSpeedSerial', '0', serial)

    def test_registry_maintenance_runs_only_in_the_registries(self):
        self.plan('docker.wipeRegistryStorage', 'panelalpha-registry-proxy')
        for container in ('shared-hosting-core-1', 'alice', 'panelalpha-cache-registry'):
            with self.subTest(container=container):
                self.refused('docker.wipeRegistryStorage', container)

    def test_a_registry_repository_removal_refuses_traversal_and_a_non_registry_container(self):
        repositories = '/var/lib/registry/docker/registry/v2/repositories'
        self.plan('docker.removeRegistryRepository', 'panelalpha-cache-registry-writer', repositories, 'panelalpha/php')
        self.refused('docker.removeRegistryRepository', 'alice', repositories, 'panelalpha/php')
        for name in ('../etc', '/abs', 'UPPER', 'a/../b', '', 'a/'):
            with self.subTest(name=name):
                self.refused('docker.removeRegistryRepository', 'panelalpha-cache-registry-writer', repositories, name)

    def test_labels_and_lists_cannot_be_misread(self):
        self.refused('docker.nodeBuild', '1', '--privileged', 'x', *build_values()[1:])
        self.refused('docker.readFileFromImage', '5', 'nginx:1', '/etc/passwd')
        self.refused('docker.buildFromStdin', 'acme/x:1', 'yes', '0')
        self.refused('docker.buildFromStdin', 'acme/x:1', '', '1', 'A B', '1')


class ComposeFileTest(Scratch):
    def test_the_engines_and_an_accounts_compose_file_are_accepted(self):
        self.plan('compose.up', self.account_file(), '')
        self.plan('compose.serviceContainers', self.engine_file(), 'sites-http')
        self.plan('compose.engineUpWithoutDependencies', self.roots['engine'], 'registry-proxy')

    def test_the_engines_file_restarts_and_lists_sites_http_only_and_an_accounts_file_its_own_services(self):
        for host in (False, True):
            self.plan('compose.restart', self.engine_file(), 'sites-http', host=host)
            self.plan('compose.restart', self.account_file(), 'dind', host=host)
            for service in ('core', 'root-broker', 'metrics', 'sites-db', 'mail', 'ftp', 'sftp', 'cache-registry'):
                with self.subTest(host=host, service=service):
                    self.refused('compose.restart', self.engine_file(), service, host=host, why='is not one this runs on')
            self.refused('compose.restart', self.account_file(), 'sites-http', host=host, why='is not one this runs on')
        self.plan('compose.serviceContainers', self.account_file(), 'php')
        self.refused('compose.serviceContainers', self.engine_file(), 'core')
        self.refused('compose.serviceContainers', self.account_file(), 'sites-http')
        self.plan('compose.cpuPercent', self.account_file(), 'dind')
        self.refused('compose.cpuPercent', self.engine_file(), 'sites-http')
        self.refused('compose.cpuPercent', self.account_file(), 'redis')

    def test_a_compose_file_anywhere_else_is_refused(self):
        os.makedirs(self.path('homes', 'alice', 'project'), exist_ok=True)
        home_file = self.make('homes', 'alice/project/docker-compose.yml', b'services: {}\n')
        other = self.make('engine', 'users/alice/docker-compose.override.yml', b'services: {}\n')
        deep = self.make('engine', 'users/alice/sub/docker-compose.yml', b'services: {}\n')
        for path in (home_file, other, deep, self.path('engine', 'templates', 'docker-compose.yml'), '/etc/docker-compose.yml'):
            with self.subTest(path=path):
                self.refused('compose.down', path)

    def test_a_compose_file_in_a_directory_someone_else_could_write_is_refused(self):
        account = os.path.dirname(self.account_file())
        for directory, mode in ((account, 0o777), (account, 0o757), (os.path.dirname(account), 0o757), (self.roots['engine'], 0o777)):
            with self.subTest(directory=directory, mode=oct(mode)):
                os.chmod(directory, mode)
                try:
                    self.refused('compose.up', self.account_file(), '', why='could have been written')
                finally:
                    os.chmod(directory, 0o755)

    def test_a_group_or_world_writable_compose_file_is_refused(self):
        os.chmod(self.account_file(), 0o666)
        self.refused('compose.down', self.account_file(), why='could have been written')

    def test_a_compose_file_or_directory_its_own_group_could_write_is_refused(self):
        owners = {os.getuid(), os.getgid()}
        self.policy = Policy(self.roots, owners=owners, host_root='', engine_owners=owners, render=support.no_services)
        account = os.path.dirname(self.account_file())
        self.plan('compose.down', self.account_file())
        for path, mode in ((self.account_file(), 0o664), (account, 0o775), (os.path.dirname(account), 0o775),
                           (self.roots['engine'], 0o775)):
            with self.subTest(path=path, mode=oct(mode)):
                kept = os.stat(path).st_mode & 0o777
                os.chmod(path, mode)
                try:
                    self.refused('compose.down', self.account_file(), why='could have been written')
                finally:
                    os.chmod(path, kept)

    def test_a_refusal_names_the_file_and_the_one_command_that_fixes_it(self):
        os.chmod(self.account_file(), 0o664)
        self.refused('compose.down', self.account_file(), why=f"run on the host: chmod go-w {self.account_file()}")
        os.chmod(self.account_file(), 0o644)
        engine = self.roots['engine']
        self.policy = Policy(self.roots, owners={os.getuid() + 1}, host_root='', engine_owners={os.getuid() + 1},
                             render=support.no_services)
        self.refused('compose.down', self.account_file(),
                     why=f"'{engine}' could have been written by someone other than root and the engine; once it is known "
                         f"to be the engine's own, run on the host: chown root:root {engine} && chmod go-w {engine}")

    def test_a_compose_file_owned_by_someone_else_is_refused(self):
        strict = Policy(self.roots, owners={os.getuid() + 1}, host_root='')
        action = registry()['compose.down']
        with self.assertRaises(Refused):
            action.plan(action.check([self.account_file()]), strict)

    def test_a_symlinked_compose_file_or_directory_is_refused(self):
        account = os.path.dirname(self.account_file())
        os.rename(account, account + '.real')
        os.symlink(account + '.real', account)
        self.refused('compose.down', self.account_file(), why='symlink')
        os.unlink(account)
        os.rename(account + '.real', account)
        os.rename(self.account_file(), self.account_file() + '.real')
        os.symlink(self.account_file() + '.real', self.account_file())
        self.refused('compose.down', self.account_file(), why='symlink')

    def test_a_project_directory_other_than_the_engines_is_refused(self):
        for directory in (os.path.dirname(self.account_file()), self.path('homes', 'alice', 'project'), '/'):
            with self.subTest(directory=directory):
                self.refused('compose.engineUpWithoutDependencies', directory, 'sites-db')


class ExecTest(Scratch):
    def test_the_attach_shell_opens_in_the_accounts_own_service(self):
        plan = self.plan('compose.execWithTty', self.account_file(), '1', 'DOCKER_CONFIG', '/home/alice/.d', '', '', 'dind', 'bash')
        self.assertEqual(plan.argv[-2:], ['dind', 'bash'])

    def test_a_service_of_the_account_other_than_its_own_is_refused(self):
        for service in ('core', 'sites-http', 'mail', 'redis'):
            with self.subTest(service=service):
                self.refused('compose.execWithTty', self.account_file(), '0', '', '', service, 'bash')

    def test_no_callers_command_runs_in_the_engines_compose_file(self):
        for action, given in (('compose.execWithTty', ['0', '', '', 'dind', 'bash']), ('compose.up', ['']),
                              ('compose.down', [])):
            with self.subTest(action=action):
                self.refused(action, self.engine_file(), *given)
        for gone in ('compose.engineExec', 'compose.execPassingEnvironment', 'compose.exec', 'compose.execAs'):
            self.assertNotIn(gone, registry())

    def test_a_named_shared_action_runs_only_in_the_engines_compose_file(self):
        self.plan('compose.nginxConfigTest', self.engine_file())
        self.refused('compose.nginxConfigTest', self.account_file())
        self.refused('compose.ftpShowUser', self.account_file(), 'web@example.com')

    def test_the_values_of_a_named_action_are_checked(self):
        refusals = [
            ('compose.litespeedControl', 'stop'), ('compose.litespeedControl', 'restart; id'),
            ('compose.sftpKillSessions', 'alice;id'), ('compose.sftpKillSessions',),
            ('compose.ftpShowUser', '-u'), ('compose.ftpSetQuota', 'web@example.com', '0'),
            ('compose.ftpSetQuota', 'web@example.com', '-1'),
            ('compose.ftpAddUser', 'web@example.com', '1001', '1001', '/etc', ''),
            ('compose.ftpAddUser', 'web@example.com', '1001', '1001', '/home/ftpuser/../root', ''),
            ('compose.eximSendTestEmail', '-be@example.com'), ('compose.eximSendTestEmail', 'a@b.c\nBcc: x@y.z'),
            ('compose.lighthouseAudit', '--help', '', 'MAP a.test 1.2.3.4'),
            ('compose.lighthouseAudit', 'https://a.test/', '', 'MAP a.test 1.2.3.4" --x'), ('compose.lighthouseAudit', 'file:///etc/passwd', '', 'MAP a.test 1.2.3.4'),
            ('compose.modsecurityApacheCheck', '/etc'),
            ('compose.modsecurityNginxCheck', self.path('engine', 'config', 'modsecurity', '.custom-rules-test-0a/../x')),
            ('compose.litespeedResetAdminPassword', 'Ab3dEf6hIj9lMn2p'),
            ('compose.ftpAddUser', 'web@example.com', '0', '1001', '/home/ftpuser/alice', ''),
            ('compose.ftpAddUser', 'web@example.com', '1001', '0', '/home/ftpuser/alice', ''),
        ]
        for action, *given in refusals:
            with self.subTest(action=action, given=given):
                self.refused(action, self.engine_file(), *given)

    def test_only_the_actions_sudo_ran_on_the_host_may_ask_for_its_namespaces(self):
        self.plan('compose.restart', self.engine_file(), 'sites-http', host=True)
        self.plan('compose.nginxConfigTest', self.engine_file(), host=True)
        self.refused('compose.down', self.account_file(), host=True, why="host's namespaces")
        self.refused('docker.info', host=True, why="host's namespaces")


class EximRestartTest(unittest.TestCase):
    def test_the_restart_stops_a_daemon_the_init_script_missed(self):
        script = engineservices.EXIM_RESTART_SCRIPT
        stop, kill, start = (script.index(part) for part in ('service exim4 stop', 'kill $left', 'service exim4 start'))
        self.assertLess(stop, kill)
        self.assertLess(kill, start)
        self.assertIn('-bd', script)
        self.assertIn('kill -9', script)


ENV_ACTIONS = {
    **registry(),
    'test.password': Command(
        lambda given: (given.one(values.operand),), lambda context, label: ['sh', '-c', 'printf "%s %s" "$0" "$PA_FTP_PASSWORD"', label],
        passes=('PA_FTP_PASSWORD',), secret=(0,),
    ),
}


class EnvironmentTest(support.Serving):
    actions = ENV_ACTIONS

    def test_a_variable_the_action_names_reaches_it_from_the_callers_environment_and_never_the_audit(self):
        result = self.pa_root_with_env('test.password', 'label', env={'PA_FTP_PASSWORD': 'hunter2'})
        self.assertEqual((result.returncode, result.stdout), (0, b'label hunter2'), result.stderr)
        line = self.audit_lines()[-1]
        self.assertNotIn('hunter2', line)
        entry = json.loads(line.removeprefix('pa-root-audit '))
        self.assertEqual((entry['values'], entry['env']), (['<secret>'], ['PA_FTP_PASSWORD']))

    def test_a_variable_the_action_does_not_name_is_refused(self):
        result = self.pa_root_with_env('files.read', self.make('engine', 'x'), env={'PA_FTP_PASSWORD': 'hunter2'})
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'PA_FTP_PASSWORD', result.stderr)

    def pa_root_with_env(self, action, *given, env):
        import subprocess
        import sys
        argv = [sys.executable, '-I', support.CLIENT, *[word for name in env for word in ('--env', name)], action, '--', *given]
        return subprocess.run(argv, capture_output=True, env={**os.environ, **env, 'PA_ROOT_SOCKET': self.socket}, timeout=support.SECONDS)


if __name__ == '__main__':
    unittest.main()
