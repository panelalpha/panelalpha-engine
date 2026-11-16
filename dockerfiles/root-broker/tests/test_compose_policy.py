"""An account's compose file starts containers only in the shape of the engine's templates; the engine's only as root's."""

import copy
import json
import os
import shutil
import tempfile
import unittest

import support
from rootbroker import composefiles, paths
from rootbroker.actions import ROOTS, Policy, registry
from rootbroker.refused import Refused

CASES = os.path.join(support.HERE, 'compose-cases.json')
ENGINE = ROOTS['engine']
HOMES = ROOTS['homes']
SANDBOX = support.sandbox_render()
STARTS = (('compose.up', ''), ('compose.build',), ('compose.restart', 'php'), ('lines.composePull',),
          ('lines.composeBuild',))
NO_START = (('compose.down',), ('compose.runningServices',), ('compose.serviceContainers', 'php'))


def load():
    with open(CASES) as f:
        return json.load(f)


def case(name):
    return next(found for found in load() if found['name'] == name)


class Scratch(unittest.TestCase):
    """The engine directory and the homes below one scratch directory, with account `alice`'s compose file."""

    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='broker-compose-'))
        self.addCleanup(shutil.rmtree, self.base, True)
        os.chmod(self.base, 0o755)
        self.roots = {name: self.base + path for name, path in ROOTS.items()}
        self.directory = self.roots['engine'] + '/users/alice'
        os.makedirs(self.directory)
        os.makedirs(self.roots['homes'] + '/alice')
        for path in (self.roots['engine'], self.roots['engine'] + '/users', self.directory):
            os.chmod(path, 0o755)
        self.write('users/alice/docker-compose.yml', case('dind-sysbox')['compose'])
        self.write('docker-compose.yml', 'services: {}\n')
        self.model = self.moved(case('dind-sysbox')['rendered'])
        self.asked = []
        self.policy = self.policy_with()

    def policy_with(self, engine_owners=None, render=None):
        return Policy(self.roots, owners={os.getuid()}, host_root='',
                      engine_owners={os.getuid()} if engine_owners is None else engine_owners,
                      render=render or self.rendered)

    def rendered(self, content, options):
        self.asked.append((content, options))
        return copy.deepcopy(self.model)

    def moved(self, value):
        """A rendered model with the host's engine and home paths moved into the scratch tree."""
        if isinstance(value, dict):
            return {key: self.moved(item) for key, item in value.items()}
        if isinstance(value, list):
            return [self.moved(item) for item in value]
        if isinstance(value, str) and value.startswith((ENGINE + '/', HOMES + '/')):
            return self.base + value
        return value

    def in_scratch(self, compose):
        return compose.replace(HOMES + '/', self.roots['homes'] + '/')

    def write(self, relative, content, mode=0o644):
        path = os.path.join(self.roots['engine'], relative)
        with open(path, 'w') as f:
            f.write(content)
        os.chmod(path, mode)
        return path

    def account_file(self):
        return self.directory + '/docker-compose.yml'

    def engine_file(self):
        return self.roots['engine'] + '/docker-compose.yml'

    def plan(self, action, *given, policy=None, host=False):
        found = registry()[action]
        plan = found.plan(found.check(list(given)), policy or self.policy, host)
        self.addCleanup(close, plan)
        return plan

    def up(self, policy=None):
        return self.plan('compose.up', self.account_file(), '', policy=policy)

    def refused(self, action, *given, why, policy=None, host=False):
        with self.assertRaises(Refused) as caught:
            self.plan(action, *given, policy=policy, host=host)
        self.assertIn(why, str(caught.exception))

    def service(self):
        return self.model['services']['dind']


def close(plan):
    for fd in [plan.cwd, *plan.fds]:
        if fd is not None:
            try:
                os.close(fd)
            except OSError:
                pass


class TemplatesTest(Scratch):
    def test_every_template_variant_is_accepted(self):
        self.write('.env-core', 'APP_KEY=x\nDIND_RUNTIME=privileged\n', 0o640)
        for found in load():
            with self.subTest(case=found['name']):
                self.model = self.moved(found['rendered'])
                self.up()

    @unittest.skipUnless(SANDBOX, 'needs the compose plugin and a user namespace')
    def test_every_template_variant_renders_to_a_file_the_broker_accepts(self):
        self.write('.env-core', 'DIND_RUNTIME=privileged\n', 0o640)
        policy = self.policy_with(render=SANDBOX)
        for found in load():
            with self.subTest(case=found['name']):
                self.write('users/alice/docker-compose.yml', self.in_scratch(found['compose']))
                self.up(policy)


class RefusalsTest(Scratch):
    def refuses(self, why, change):
        self.model = self.moved(case('dind-sysbox')['rendered'])
        change(self.model)
        self.refused('compose.up', self.account_file(), '', why=why)

    def test_each_key_the_templates_do_not_set_is_refused(self):
        forbidden = {
            'pid': 'host', 'ipc': 'host', 'userns_mode': 'host', 'network_mode': 'host', 'uts': 'host', 'cgroup': 'host',
            'devices': [{'source': '/dev/sda', 'target': '/dev/sda', 'permissions': 'rwm'}], 'cap_add': ['SYS_ADMIN'],
            'security_opt': ['apparmor=unconfined'], 'cgroup_parent': '/', 'volumes_from': ['core'],
            'device_cgroup_rules': ['a *:* rwm'], 'sysctls': {'kernel.x': '1'}, 'group_add': ['0'], 'user': '0',
            'environment': {'A': '1'}, 'labels': {'a': 'b'}, 'ports': [{'target': 80, 'published': '80'}],
            'oom_score_adj': -1000, 'pids_limit': -1, 'ulimits': {'nofile': 1}, 'stdin_open': 'yes',
        }
        for key, value in forbidden.items():
            with self.subTest(key=key):
                self.refuses(f'services.dind.{key}', lambda model: model['services']['dind'].update({key: value}))

    def test_privileged_is_refused_without_the_runtime_asking_for_it(self):
        self.refuses('services.dind.privileged', lambda model: model['services']['dind'].update(privileged=True))

    def test_an_image_but_an_engine_account_image_is_refused(self):
        for image in ('busybox', 'ghcr.io/panelalpha/engine-core:v2', 'ghcr.io/panelalpha/engine-root-broker:v2',
                      'ghcr.io/panelalpha/engine-user-dind/../core:1', 'evil.example/panelalpha/engine-user-dind:1',
                      'ghcr.io/panelalpha/engine-user-dind:1@sha256:abc'):
            with self.subTest(image=image):
                self.refuses('services.dind.image', lambda model: model['services']['dind'].update(image=image))

    def test_a_runtime_name_or_host_entry_but_the_templates_is_refused(self):
        for key, value in (('runtime', 'runc'), ('container_name', 'panelalpha-core-1'), ('hostname', 'bob'),
                           ('extra_hosts', ['host.docker.internal=host-gateway', 'ghcr.io=203.0.113.9'])):
            with self.subTest(key=key):
                self.refuses(f'services.dind.{key}', lambda model: model['services']['dind'].update({key: value}))

    def test_a_build_but_the_accounts_dockerfile_in_its_directory_is_refused(self):
        changes = {
            'context': '/', 'dockerfile': '/etc/Dockerfile', 'additional_contexts': {'root': '/'}, 'privileged': True,
            'network': 'host', 'tags': ['ghcr.io/panelalpha/engine-core:v2'], 'secrets': ['s'], 'ssh': ['default'],
            'entitlements': ['security.insecure'], 'cache_to': ['type=local,dest=/etc'], 'extra_hosts': ['a=1.2.3.4'],
        }
        for key, value in changes.items():
            with self.subTest(key=key):
                self.refuses('services.dind.build', lambda model: model['services']['dind']['build'].update({key: value}))

    def test_a_bind_from_anywhere_but_the_accounts_home_directory_and_lxcfs_is_refused(self):
        sources = ['/', '/etc', '/var/run/docker.sock', '/proc', self.roots['homes'] + '/bob',
                   self.roots['homes'] + '/alice/.ssh', self.roots['homes'], self.roots['engine'],
                   self.roots['engine'] + '/docker-compose.yml', self.roots['engine'] + '/users/bob/log',
                   self.directory, self.directory + '/../bob', '/var/lib/lxcfs', '/var/lib/lxcfs/proc/../../../../etc',
                   '/var/lib/lxcfs/proc/self', 'relative']
        for source in sources:
            with self.subTest(source=source):
                self.refuses('services.dind.volumes[0]', lambda model: model['services']['dind']['volumes'][0].update(
                    source=source))

    def test_lxcfs_is_bound_read_only(self):
        def writable(model):
            lxcfs = [volume for volume in model['services']['dind']['volumes'] if volume['source'].startswith('/var/lib')]
            lxcfs[0]['read_only'] = False
        self.refuses('writable', writable)

    def test_a_mount_but_a_plain_bind_is_refused(self):
        for change in ({'type': 'volume', 'source': 'data'}, {'type': 'tmpfs'}, {'type': 'npipe'},
                       {'bind': {'propagation': 'rshared'}}, {'bind': {'selinux': 'z'}}, {'consistency': 'cached'},
                       {'volume': {'nocopy': True}}):
            with self.subTest(change=change):
                self.refuses('services.dind.volumes[1]',
                             lambda model: model['services']['dind']['volumes'][1].update(change))

    def test_a_bind_through_a_symlink_in_the_accounts_directory_is_refused(self):
        os.symlink('/', self.directory + '/services')
        self.refuses('symlink', lambda model: None)
        os.unlink(self.directory + '/services')
        os.makedirs(self.directory + '/php')
        os.symlink('/etc', self.directory + '/php/8.3')
        self.refuses('symlink', lambda model: model['services']['dind']['volumes'][1].update(
            source=self.directory + '/php/8.3/fpm/pool.d'))

    def test_host_entries_in_either_form_compose_renders_are_read_alike(self):
        self.service()['extra_hosts'] = {'host.docker.internal': ['host-gateway']}
        self.up()
        self.refuses('services.dind.extra_hosts', lambda model: model['services']['dind'].update(
            extra_hosts={'host.docker.internal': 'host-gateway', 'ghcr.io': ['203.0.113.9']}))

    def test_a_home_that_is_a_symlink_is_not_bound(self):
        home = self.roots['homes'] + '/alice'
        os.rename(home, home + '.real')
        os.symlink(home + '.real', home)
        self.refuses('symlink', lambda model: None)

    def test_a_value_of_the_wrong_kind_is_refused_rather_than_misread(self):
        changes = {
            'services': lambda model: model.update(services=['dind']),
            'services.dind.build': lambda model: model['services']['dind'].update(build='/'),
            'services.dind.volumes[0]': lambda model: model['services']['dind']['volumes'].insert(0, '/:/host'),
            'services.dind.blkio_config.device_read_bps': lambda model: model['services']['dind']['blkio_config'].update(
                device_read_bps=['/dev/sda 1']),
            'services.dind.networks': lambda model: model['services']['dind'].update(networks=['default']),
        }
        for why, change in changes.items():
            with self.subTest(why=why):
                self.refuses(why, change)

    def test_a_project_network_or_volume_but_the_templates_is_refused(self):
        changes = {
            'name': lambda model: model.update(name='bob'),
            'volumes': lambda model: model.update(volumes={'v': {'driver_opts': {'o': 'bind', 'device': '/'}}}),
            'secrets': lambda model: model.update(secrets={'s': {'file': '/etc/shadow'}}),
            'configs': lambda model: model.update(configs={'c': {'file': '/etc/shadow'}}),
            'networks.default': lambda model: model['networks']['default'].update(name='bridge'),
            'networks.default.driver_opts': lambda model: model['networks']['default'].update(driver_opts={'a': 'b'}),
            'networks.host': lambda model: model['networks'].update(host={'name': 'host', 'external': True}),
            'services.dind.networks.engine': lambda model: model['services']['dind']['networks'].update(engine=None),
            'services.core': lambda model: model['services'].update(core={'image': 'busybox'}),
            'services.dind.blkio_config': lambda model: model['services']['dind']['blkio_config'].update(
                device_read_bps=[{'Path': '/etc/shadow', 'Rate': '1'}]),
        }
        for why, change in changes.items():
            with self.subTest(why=why):
                self.refuses(why, change)


class PrivilegedRuntimeTest(Scratch):
    def setUp(self):
        super().setUp()
        self.model = self.moved(case('dind-privileged')['rendered'])

    def test_a_privileged_account_starts_where_root_set_the_runtime(self):
        self.write('.env-core', 'APP_KEY=x\nDIND_RUNTIME="privileged"\n', 0o640)
        self.up()

    def test_privileged_is_refused_where_root_did_not_set_the_runtime(self):
        for content, mode, owners in (('DIND_RUNTIME=sysbox-runc\n', 0o640, None), ('', 0o640, None),
                                      ('DIND_RUNTIME=privileged\n', 0o666, None),
                                      ('DIND_RUNTIME=privileged\n', 0o640, {os.getuid() + 1}),
                                      ('DIND_RUNTIME=privileged\n', 0o660, {os.getuid(), os.getgid()})):
            with self.subTest(content=content, mode=oct(mode), owners=owners):
                self.write('.env-core', content, mode)
                self.refused('compose.up', self.account_file(), '', why='privileged',
                             policy=self.policy_with(engine_owners=owners))

    def test_a_linked_runtime_file_is_not_read(self):
        target = self.write('runtime', 'DIND_RUNTIME=privileged\n', 0o640)
        os.symlink(target, self.roots['engine'] + '/.env-core')
        self.refused('compose.up', self.account_file(), '', why='privileged')

    def test_only_the_dind_service_may_be_privileged(self):
        self.write('.env-core', 'DIND_RUNTIME=privileged\n', 0o640)
        self.model = self.moved(case('php-fpm')['rendered'])
        self.model['services']['php']['privileged'] = True
        self.refused('compose.up', self.account_file(), '', why='services.php.privileged')


class ActionsTest(Scratch):
    def setUp(self):
        super().setUp()
        self.model['services']['dind']['pid'] = 'host'

    def test_every_action_that_starts_containers_checks_the_file(self):
        for action, *rest in STARTS:
            with self.subTest(action=action):
                self.refused(action, self.account_file(), *rest, why='services.dind.pid')

    def test_actions_on_running_containers_do_not_render_it(self):
        for action, *rest in NO_START:
            with self.subTest(action=action):
                self.plan(action, self.account_file(), *rest)
        self.assertEqual(self.asked, [])

    def test_the_file_is_rendered_as_the_accounts_project_with_every_profile(self):
        del self.model['services']['dind']['pid']
        self.up()
        with open(self.account_file(), 'rb') as f:
            options = ['-p', 'alice', '--profile', '*', '--project-directory', self.directory]
            self.assertEqual(self.asked, [(f.read(), options)])

    def test_every_account_action_runs_the_bytes_read_in_the_accounts_project_without_its_env_file(self):
        del self.model['services']['dind']['pid']
        with open(self.account_file(), 'rb') as f:
            read = f.read()
        for action, *rest in STARTS + NO_START:
            with self.subTest(action=action):
                plan = self.plan(action, self.account_file(), *rest)
                if plan.native is not None:
                    self.assertIsNone(plan.argv, 'an action the broker answers itself runs no compose')
                    continue
                at = plan.argv.index('--env-file')
                pinned = ['--env-file', '/dev/null', '-p', 'alice', '--project-directory', '.', '-f']
                self.assertEqual(plan.argv[at:at + 7], pinned)
                self.write('users/alice/docker-compose.yml', 'services:\n  dind:\n    privileged: true\n')
                self.assertEqual(os.pread(int(plan.argv[at + 7].removeprefix('/dev/fd/')), 1 << 20, 0), read)
                self.write('users/alice/docker-compose.yml', read.decode())

    def test_an_oversized_file_is_refused(self):
        self.write('users/alice/docker-compose.yml', '#' * ((1 << 20) + 1))
        self.refused('compose.down', self.account_file(), why='larger')


class EngineStartTest(Scratch):
    STARTS = (('compose.restart', 'sites-http'),)

    def engine_starts(self, policy):
        for action, *rest in self.STARTS:
            self.plan(action, self.engine_file(), *rest, policy=policy)
        self.plan('compose.engineUpWithoutDependencies', self.roots['engine'], 'registry-proxy', policy=policy)

    def refuses_engine_starts(self, why, policy=None):
        for action, *rest in self.STARTS:
            with self.subTest(action=action):
                self.refused(action, self.engine_file(), *rest, why=why, policy=policy)
        with self.subTest(action='compose.engineUpWithoutDependencies'):
            self.refused('compose.engineUpWithoutDependencies', self.roots['engine'], 'registry-proxy', why=why,
                         policy=policy)

    def test_root_owned_engine_files_start_containers(self):
        self.write('docker-compose.override.yml', 'services: {}\n')
        os.symlink('docker-compose.yml-nginx', self.roots['engine'] + '/docker-compose.yml-webserver')
        self.write('docker-compose.yml-nginx', 'services: {}\n')
        self.write('.env', 'A=1\n', 0o600)
        self.engine_starts(self.policy)

    def test_an_engine_file_core_owns_starts_nothing(self):
        self.refuses_engine_starts('someone other than root', self.policy_with(engine_owners={os.getuid() + 1}))

    def test_an_engine_file_core_owns_still_serves_what_starts_nothing(self):
        policy = self.policy_with(engine_owners={os.getuid() + 1})
        self.plan('compose.serviceContainers', self.engine_file(), 'sites-http', policy=policy)
        self.plan('compose.nginxConfigTest', self.engine_file(), policy=policy, host=True)
        self.plan('compose.sitesDbCacheSizes', self.roots['engine'], policy=policy)

    def test_a_file_compose_reads_from_the_engine_directory_by_itself_must_be_roots_too(self):
        for name in ('compose.yaml', 'docker-compose.override.yml', 'compose.override.yaml', '.env',
                     'docker-compose.yml-nginx'):
            with self.subTest(name=name):
                path = self.write(name, 'services: {}\n', 0o666)
                self.refuses_engine_starts('someone other than root')
                os.unlink(path)

    def test_an_engine_file_its_own_group_could_write_starts_nothing(self):
        owners = {os.getuid(), os.getgid()}
        path = self.write('docker-compose.yml-nginx', 'services: {}\n')
        self.engine_starts(self.policy_with(engine_owners=owners))
        os.chmod(path, 0o664)
        self.refuses_engine_starts('someone other than root', self.policy_with(engine_owners=owners))

    def test_a_link_to_a_file_someone_else_could_write_is_refused(self):
        outside = os.path.join(self.base, 'webserver.yml')
        with open(outside, 'w') as f:
            f.write('services: {}\n')
        os.chmod(outside, 0o666)
        os.symlink(outside, self.roots['engine'] + '/docker-compose.yml-webserver')
        self.refuses_engine_starts('someone other than root')

    def test_the_check_leaves_no_descriptor_open_whether_it_passes_or_refuses(self):
        directory = os.open(self.roots['engine'], paths.DIRECTORY)
        self.addCleanup(os.close, directory)
        before = open_descriptors()
        composefiles.check_engine_start(directory, self.roots['engine'], self.policy)
        self.assertEqual(open_descriptors(), before)
        with self.assertRaises(Refused):
            composefiles.check_engine_start(directory, self.roots['engine'], self.policy_with(engine_owners={os.getuid() + 1}))
        self.assertEqual(open_descriptors(), before)


def open_descriptors():
    return sorted(os.listdir('/proc/self/fd'))


@unittest.skipUnless(SANDBOX, 'needs the compose plugin and a user namespace')
class SandboxTest(Scratch):
    def setUp(self):
        super().setUp()
        self.policy = self.policy_with(render=SANDBOX)
        self.template = self.in_scratch(case('php-fpm')['compose'])

    def with_service_lines(self, lines):
        return self.template.replace('    tty: true\n', '    tty: true\n' + lines)

    def test_a_file_that_reads_another_file_does_not_render(self):
        for name, content in (('inc.yml', 'services: {}\n'), ('base.yml', 'services:\n  b:\n    image: busybox\n'),
                              ('e.env', 'A=1\n')):
            self.write('users/alice/' + name, content)
        for reference in ('include:\n  - inc.yml\n', f'include:\n  - {self.directory}/inc.yml\n'):
            with self.subTest(reference=reference):
                self.write('users/alice/docker-compose.yml', reference + self.template)
                self.refused('compose.up', self.account_file(), '', why='does not render')
        for addition in ('    extends:\n      file: base.yml\n      service: b\n', '    env_file: e.env\n'):
            with self.subTest(addition=addition):
                self.write('users/alice/docker-compose.yml', self.with_service_lines(addition))
                self.refused('compose.up', self.account_file(), '', why='does not render')

    def test_a_service_behind_a_profile_is_checked_too(self):
        hidden = '  hidden:\n    image: busybox\n    privileged: true\n    profiles: [later]\n'
        self.write('users/alice/docker-compose.yml', self.template.replace('services:\n', 'services:\n' + hidden, 1))
        self.refused('compose.up', self.account_file(), '', why='services.hidden')

    def test_the_accounts_env_file_and_project_name_do_not_reach_the_render(self):
        self.write('users/alice/.env', 'COMPOSE_PROJECT_NAME=bob\nTAG=evil\nCOMPOSE_PROFILES=later\n')
        content = 'name: bob\n' + self.template.replace(':v2.2.0-20261028', ':${TAG:-v2.2.0-20261028}')
        self.write('users/alice/docker-compose.yml', content)
        self.up()

    def test_a_privileged_file_does_not_start(self):
        self.write('users/alice/docker-compose.yml', self.with_service_lines('    privileged: true\n'))
        self.refused('compose.up', self.account_file(), '', why='services.php.privileged')
