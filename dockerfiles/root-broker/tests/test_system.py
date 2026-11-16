import os
import re
import shutil
import stat
import subprocess
import tempfile
import unittest

import support
import systemwork
from rootbroker.actions import Policy, firewall, registry, users
from rootbroker.refused import Refused

PRIVILEGED = os.path.join(support.HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged')
GATEWAYS = {
    'users': 'RootUsers.php', 'network': 'RootNetwork.php', 'firewall': 'RootFirewall.php', 'probes': 'RootProbes.php',
    'backup-store': 'RootBackupStore.php', 'webserver': 'RootWebserver.php',
}
NOT_ACTIONS = set()
HOST_ONLY = ('users', 'network', 'firewall', 'probes', 'webserver')
ENGINE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml'


PLAIN_MOUNTS = '22 1 8:1 / / rw,relatime shared:1 - ext4 /dev/sda1 rw\n'


def pinned_mountinfo(test, text):
    """Points users.MOUNTINFO at a file holding `text` for the rest of `test`."""
    scratch = tempfile.mkdtemp()
    test.addCleanup(shutil.rmtree, scratch)
    path = os.path.join(scratch, 'mountinfo')
    with open(path, 'w') as f:
        f.write(text)
    test.addCleanup(setattr, users, 'MOUNTINFO', users.MOUNTINFO)
    users.MOUNTINFO = path


class CasesTest(unittest.TestCase):
    def setUp(self):
        pinned_mountinfo(self, PLAIN_MOUNTS)

    def test_each_case_runs_the_sudo_command_word_for_word(self):
        for case in systemwork.load():
            with self.subTest(case=case['name']):
                scratch = systemwork.Scratch()
                try:
                    plan = systemwork.plan_case(scratch, case)
                    if 'sudo' not in case:
                        self.assertIsNone(plan.argv, 'an action the broker answers itself runs no command')
                        self.assertIsNotNone(plan.native)
                        systemwork.close(plan)
                        continue
                    expected = systemwork.unprivileged(case['sudo'], case['host'])
                    self.assertEqual(systemwork.resolved_argv(scratch, plan), expected)
                    systemwork.close(plan)
                finally:
                    scratch.close()

    def test_a_host_action_is_asked_for_the_hosts_namespaces_and_no_other_is(self):
        for case in systemwork.load():
            with self.subTest(case=case['name']):
                host, name, _ = systemwork.split_broker(case['broker'])
                self.assertEqual(host, name.split('.')[0] in HOST_ONLY)
                self.assertEqual(host, case['host'])

    @unittest.skipUnless(os.path.isdir(PRIVILEGED), 'needs the engine checkout around the broker')
    def test_every_gateway_method_has_an_action_and_a_case(self):
        asked = {systemwork.split_broker(case['broker'])[1] for case in systemwork.load()}
        for area, name in GATEWAYS.items():
            with open(os.path.join(PRIVILEGED, name)) as f:
                methods = set(re.findall(r'public function (\w+)\(', f.read())) - NOT_ACTIONS
            actions = {action.removeprefix(area + '.') for action in registry() if action.startswith(area + '.')}
            with self.subTest(area=area):
                self.assertEqual(methods, actions)
                self.assertEqual(actions, {action.removeprefix(area + '.') for action in asked if action.startswith(area + '.')})


class HostOnlyTest(unittest.TestCase):
    def test_a_host_area_runs_nowhere_but_in_the_hosts_namespaces(self):
        policy = Policy()
        for name, action in registry().items():
            if name.split('.')[0] not in HOST_ONLY:
                continue
            with self.subTest(action=name):
                self.assertTrue(action.host_only)
                with self.assertRaisesRegex(Refused, "only in the host's namespaces"):
                    action.plan(action.read(_Given()), policy, host=False)

    def test_a_backup_store_action_never_runs_in_the_hosts_namespaces(self):
        for name, action in registry().items():
            if name.startswith('backup-store.'):
                with self.subTest(action=name), self.assertRaisesRegex(Refused, "host's namespaces"):
                    action.plan(action.check(['/home/b/x']), Policy(), host=True)


class _Given:
    """Plausible values of each kind, so only the host check is left to refuse."""

    def one(self, check):
        for value in ('4', 'alice', '/home/alice', 'eth0', '203.0.113.0/24', '203.0.113.1', 'https://x.example',
                      '/dev/sda', 'tcp', '/var/tmp', 'debian', 'nginx', firewall.TRUSTED_LISTS[0], firewall.LOCKS[0]):
            try:
                return check(value)
            except Refused:
                continue
        return check('')

    def rest(self, check):
        return [check(word) for word in ('reload',)]


class QuotaMountPointTest(support.Tree):
    ROOT = '22 1 8:1 / / rw,relatime shared:1 - ext4 /dev/sda1 rw\n'
    HOMES_SELF_BIND = '90 22 8:1 /home /home rw,nosuid,nodev,relatime shared:1 - ext4 /dev/sda1 rw\n'

    def passwd_entries(self):
        return [*super().passwd_entries(), 'alice:x:1020:1020::' + self.roots['homes'] + '/alice:/usr/sbin/nologin']

    def resolved(self, mountinfo, path):
        pinned_mountinfo(self, mountinfo)
        return users.quota_mount_point(path)

    def test_the_homes_self_bind_is_answered_with_its_filesystems_root_mount(self):
        self.assertEqual(self.resolved(self.ROOT + self.HOMES_SELF_BIND, '/home'), '/')

    def test_set_quota_is_given_the_root_mount_when_the_homes_are_a_self_bind(self):
        pinned_mountinfo(self, self.ROOT + self.HOMES_SELF_BIND)
        action = registry()['users.setQuota']
        plan = action.plan(action.check(['alice', '1048576', '50000', '/home']), self.policy, True)
        self.assertEqual(plan.argv, ['setquota', '-u', 'alice', '1048576', '1048576', '50000', '50000', '/'])

    def test_a_dedicated_homes_filesystem_keeps_its_own_mount_point(self):
        dedicated = '91 22 8:17 / /home rw,nosuid,nodev,relatime shared:2 - ext4 /dev/sdb1 rw\n'
        self.assertEqual(self.resolved(self.ROOT + dedicated, '/home'), '/home')

    def test_paths_with_no_bind_above_them_are_returned_as_given(self):
        self.assertEqual(self.resolved(self.ROOT, '/home'), '/home')
        self.assertEqual(self.resolved(self.ROOT, '/'), '/')

    def test_a_subvolume_with_no_root_mount_of_its_filesystem_is_returned_as_given(self):
        subvolumes = ('22 1 0:30 /@ / rw shared:1 - btrfs /dev/sda2 rw\n'
                      '91 22 0:30 /@home /home rw shared:2 - btrfs /dev/sda2 rw\n')
        self.assertEqual(self.resolved(subvolumes, '/home'), '/home')

    def test_the_later_of_two_mounts_on_one_point_is_the_one_reached(self):
        over = self.HOMES_SELF_BIND + '92 90 8:17 / /home rw shared:3 - ext4 /dev/sdb1 rw\n'
        self.assertEqual(self.resolved(self.ROOT + over, '/home'), '/home')

    def test_escaped_mount_points_are_read_as_paths(self):
        spaced = '93 22 8:1 /srv/my\\040homes /srv/my\\040homes rw shared:1 - ext4 /dev/sda1 rw\n'
        self.assertEqual(self.resolved(self.ROOT + spaced, '/srv/my homes'), '/')

    def test_an_unreadable_mount_table_changes_nothing(self):
        self.addCleanup(setattr, users, 'MOUNTINFO', users.MOUNTINFO)
        users.MOUNTINFO = '/nonexistent/mountinfo'
        self.assertEqual(users.quota_mount_point('/home'), '/home')


class RefusalsTest(support.Tree):
    def passwd_entries(self):
        return [*super().passwd_entries(), 'alice:x:1020:1020::' + self.roots['homes'] + '/alice:/usr/sbin/nologin']

    def refused(self, name, *given, host=True):
        action = registry()[name]
        with self.assertRaises(Refused, msg=(name, given)):
            action.plan(action.check(list(given)), self.policy, host)

    def test_an_account_is_made_only_with_its_own_home_and_changed_only_when_it_is_a_hosting_account(self):
        self.refused('users.create', 'alice', self.path('homes', 'bob'))
        self.refused('users.create', '-o', self.path('homes', '-o'))
        self.refused('users.delete', 'root')
        self.refused('users.delete', 'www-data')
        self.refused('users.delete', 'nobody-here')
        self.refused('users.setQuota', 'low', '1', '1', '/')
        self.refused('users.setQuota', 'alice', '-1', '0', '/')
        self.refused('users.uid', '--help')

    def test_an_address_route_or_interface_is_only_that(self):
        self.refused('network.interfaceAddresses', '-x')
        self.refused('network.interfaceAddresses', 'eth0;id')
        self.refused('network.route', '4', '10.0.0.0/33')
        self.refused('network.route', '6', '2001:db8::/129')
        self.refused('network.addAddress', 'fe80::1%eth0/64', 'eth0')
        self.refused('network.addRoute', '10.0.0.0/8', 'gateway.example', 'eth0')
        self.refused('network.defaultRoute', '5')
        self.refused('network.listeningSockets', 'raw')
        self.refused('network.publicAddressLookup', '10.0.0.5', 'file:///etc/shadow', '10')
        self.refused('network.publicAddressLookup', '10.0.0.5', 'https://a.example/ -o /etc/x', '10')
        self.refused('network.publicAddressLookup', '::1', 'https://a.example', '10')

    def test_ufw_takes_only_the_engines_own_words(self):
        for words in (['--force', 'reset'], ['--dry-run', 'enable'], ['status'], [],
                      ['allow', 'from', 'any', 'to', 'any', 'port', '22;id'],
                      ['allow', 'from', 'evil.example', 'to', 'any'],
                      ['allow', 'from', 'any', 'to', 'any', 'comment', "a'b"],
                      ['allow', 'from', 'any', 'to', 'any', 'extra'],
                      ['allow', 'in', 'on', 'eth0', 'from', 'any', 'to', 'any'],
                      ['insert', '1', 'allow', 'from', 'any', 'to', 'any']):
            with self.subTest(words=words):
                self.refused('firewall.ufw', *words)

    def test_a_firewall_file_is_one_of_the_gateways_and_a_trusted_list_holds_addresses(self):
        self.refused('firewall.readTrustedList', '/etc/shadow')
        self.refused('firewall.writeTrustedList', '/etc/fail2ban/jail.local', '1.2.3.4\n')
        self.refused('firewall.writeTrustedList', firewall.TRUSTED_LISTS[0], '1.2.3.4\nactionban = id\n')
        self.refused('firewall.writeTrustedList', firewall.TRUSTED_LISTS[0], '1.2.3.4')
        self.refused('firewall.ensureLockFile', '/etc/cron.d/x')
        self.refused('firewall.unban', '1.2.3.4; reboot')
        self.refused('firewall.fail2banLog', '0')
        self.refused('firewall.ufwLog', '-5')

    def test_a_probe_reads_only_the_engines_filesystems_and_an_accounts_home(self):
        os.makedirs(self.path('homes', 'alice'), exist_ok=True)
        os.symlink(self.base, self.path('homes', 'linked'))
        self.refused('probes.blockDeviceParent', '/dev/../etc/passwd')
        self.refused('probes.blockDeviceType', 'sda')
        self.refused('probes.diskFree', '/etc')
        self.refused('probes.diskUsageInKilobytes', '/etc')
        self.refused('probes.diskUsageInKilobytes', self.path('homes', 'linked') + '/')
        self.refused('probes.hostImage', '--help')

    def test_a_backup_store_path_is_below_a_root_and_its_way_has_no_link(self):
        policy = Policy({**self.roots, 'panelalpha': self.path('engine')}, accounts_db=self.accounts)
        os.makedirs(self.path('homes', 'store'), exist_ok=True)
        os.symlink('/etc', self.path('homes', 'store', 'linked'))
        for name, given in (('backup-store.removeTree', ['/home']), ('backup-store.removeTree', ['/opt/panelalpha']),
                            ('backup-store.removeTree', ['/var/www/html/storage/']),
                            ('backup-store.removeEmptyParents', ['/home/s/a', '/opt/panelalpha']),
                            ('backup-store.removeEmptyDirectories', ['/opt/panelalpha']),
                            ('backup-store.removeEmptyDirectories', ['/var/www/html/storage/']),
                            ('backup-store.giveToWebUser', ['/home']), ('backup-store.openToWebUser', ['/']),
                            ('backup-store.removeTree', ['/etc/x']),
                            ('backup-store.removeTree', [self.path('homes', 'store', 'linked', 'x')]),
                            ('backup-store.giveToWebUser', [self.path('homes', 'store', 'linked')])):
            with self.subTest(action=name, given=given):
                action = registry()[name]
                with self.assertRaises(Refused):
                    action.plan(action.check(given), policy, False)

    def test_a_store_chown_or_chmod_refuses_a_root_or_an_account_home(self):
        policy = Policy({**self.roots, 'panelalpha': self.path('engine')}, accounts_db=self.accounts)
        account_home = self.directory('homes', 'u')
        under_home = self.directory('homes', 'u/backups')
        plain = self.directory('homes', 'backups')
        for name, given in (('backup-store.giveToWebUser', ['/opt/panelalpha']),
                            ('backup-store.giveToWebUser', ['/var/www/html/storage']),
                            ('backup-store.openToWebUser', ['/opt/panelalpha']),
                            ('backup-store.giveToWebUser', [account_home]),
                            ('backup-store.openToWebUser', [account_home])):
            with self.subTest(action=name, given=given):
                action = registry()[name]
                with self.assertRaises(Refused):
                    action.plan(action.check(given), policy, False)
        for target in (under_home, plain):
            with self.subTest(target=target):
                action = registry()['backup-store.giveToWebUser']
                action.plan(action.check([target]), policy, False)

    def test_the_webserver_reload_is_one_of_four_around_the_engines_compose_file(self):
        engine_file = os.path.join(self.roots['engine'], 'docker-compose.yml')
        self.refused('webserver.queueReload', '/tmp/docker-compose.yml', 'nginx')
        self.refused('webserver.queueReload', engine_file, 'restart-everything')
        self.refused('webserver.queueReload', ENGINE_FILE + ' ; id', 'nginx')


class ServedTest(support.Serving):
    actions = registry()

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        with open(os.path.join(self.base, 'entered'), 'w'):
            pass

    def test_a_host_action_runs_only_once_the_broker_entered_the_hosts_namespaces(self):
        refused = self.pa_root('firewall.timezoneOffset')
        self.assertEqual(refused.returncode, 77, refused.stderr)
        self.assertFalse(os.path.exists(os.path.join(self.base, 'entered')))
        result = self.pa_root('firewall.timezoneOffset', host=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stdout, rb'^[+-][0-9]{2}:[0-9]{2}\n$')
        self.assertTrue(os.path.exists(os.path.join(self.base, 'entered')))


@support.needs_root
class SystemAsRootTest(support.Serving):
    def test_a_tree_goes_to_the_account_but_the_inner_daemons_store_stays_roots(self):
        home = self.path('homes', 'u')
        for relative in ('site/index.php', 'docker/overlay2/x'):
            self.make('homes', 'u/' + relative)
        os.symlink('/etc/passwd', os.path.join(home, 'site', 'link'))
        result = self.pa_root('files.handTreeToAccount', home, str(support.UID), str(support.GID), '1')
        self.assertEqual(result.returncode, 0, result.stderr)
        owner = lambda relative: (os.lstat(os.path.join(home, relative)).st_uid, os.lstat(os.path.join(home, relative)).st_gid)
        self.assertEqual(owner('site/index.php'), (support.UID, support.GID))
        self.assertEqual(owner('site/link'), (support.UID, support.GID))
        self.assertEqual(owner('docker'), (support.UID, support.GID))
        self.assertEqual(owner('docker/overlay2/x'), (0, 0))
        self.assertEqual(owner('.'), (0, 0))
        self.assertEqual(os.stat('/etc/passwd').st_uid, 0)

    def test_a_store_directory_is_handed_to_the_web_user(self):
        store = self.directory('homes', 'backups/c1')
        self.assertEqual(self.pa_root('backup-store.giveToWebUser', store).returncode, 0)
        self.assertEqual(self.pa_root('backup-store.openToWebUser', store).returncode, 0)
        self.assertEqual((os.stat(store).st_uid, os.stat(store).st_gid, os.stat(store).st_mode & 0o777), (33, 33, 0o775))


@support.needs_root
@unittest.skipUnless(shutil.which('nsenter'), 'needs nsenter to compare with')
class SystemOnTheHostTest(support.Serving):
    def test_a_host_action_sees_what_nsenter_into_pid_1_sees(self):
        for action, argv in (('firewall.timezoneOffset', ['date', '+%:z']), ('network.addresses', ['ip', '-4', 'addr', 'show'])):
            with self.subTest(action=action):
                expected = subprocess.run(['nsenter', '--target', '1', '--all', *argv], capture_output=True)
                result = self.pa_root(action, host=True)
                self.assertEqual((result.returncode, result.stdout), (expected.returncode, expected.stdout), result.stderr)


class FirewallScriptsTest(unittest.TestCase):
    """Each firewall script as its action plans it, run by sh with its fixed paths moved below a scratch directory."""

    def setUp(self):
        self.base = tempfile.mkdtemp(prefix='broker-firewall-')
        self.addCleanup(shutil.rmtree, self.base, ignore_errors=True)
        self.fixed = (*firewall.TRUSTED_LISTS, *firewall.LOCKS, *firewall.SNAPSHOTTED)

    def at(self, path):
        return self.base + path

    def run_action(self, name, *given):
        action = registry()[name]
        argv = action.plan(action.check(list(given)), Policy(), host=True).argv
        return subprocess.run([self.at(word) if word in self.fixed else word for word in argv], capture_output=True)

    def write(self, path, content, mode=0o644):
        os.makedirs(os.path.dirname(self.at(path)), exist_ok=True)
        with open(self.at(path), 'w') as f:
            f.write(content)
        os.chmod(self.at(path), mode)

    def read(self, path):
        with open(self.at(path)) as f:
            return f.read()

    def mode(self, path):
        return stat.S_IMODE(os.stat(self.at(path)).st_mode)

    def test_a_trusted_list_is_written_as_given_and_its_directory_made(self):
        listed = firewall.TRUSTED_LISTS[0]
        result = self.run_action('firewall.writeTrustedList', listed, "203.0.113.7 # office 'a'\n10.0.0.0/8\n")
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(self.read(listed), "203.0.113.7 # office 'a'\n10.0.0.0/8\n")
        self.assertEqual(self.run_action('firewall.writeTrustedList', listed, '').returncode, 0)
        self.assertEqual(self.read(listed), '')

    def test_a_lock_file_is_made_or_kept_and_left_readable_by_all(self):
        lock = firewall.LOCKS[1]
        self.assertEqual(self.run_action('firewall.ensureLockFile', lock).returncode, 0)
        self.assertEqual((self.read(lock), self.mode(lock)), ('', 0o644))
        self.write(lock, 'held', 0o600)
        result = self.run_action('firewall.ensureLockFile', lock)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual((self.read(lock), self.mode(lock)), ('held', 0o644))

    def test_a_snapshot_holds_the_rules_there_are_and_prints_whether_ufw_is_enabled(self):
        self.write(firewall.RULES, 'rules\n', 0o640)
        self.write(firewall.CONF, 'ENABLED=yes\nLOGLEVEL=low\n')
        self.write(firewall.SNAPSHOT + '/stale', 'old')
        result = self.run_action('firewall.snapshotRules')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'yes\n', b''))
        self.assertEqual(sorted(os.listdir(self.at(firewall.SNAPSHOT))), ['ufw.conf', 'user.rules'])
        self.assertEqual((self.read(firewall.SNAPSHOT + '/user.rules'), self.mode(firewall.SNAPSHOT + '/user.rules')),
                         ('rules\n', 0o640))

    def test_a_snapshot_that_cannot_be_made_or_finds_no_ufw_conf_fails(self):
        self.write(firewall.RULES, 'rules\n')
        missing = self.run_action('firewall.snapshotRules')
        self.assertEqual((missing.returncode, missing.stdout), (2, b''))
        self.assertIn(b'ufw.conf', missing.stderr)
        data = os.path.dirname(firewall.SNAPSHOT)
        shutil.rmtree(self.at(data))
        self.write(data, 'a file where a directory goes')
        blocked = self.run_action('firewall.snapshotRules')
        self.assertEqual((blocked.returncode, blocked.stdout), (1, b''))

    def test_a_restore_puts_back_what_the_snapshot_holds_and_leaves_the_rest(self):
        self.write(firewall.SNAPSHOT + '/user.rules', 'saved\n', 0o640)
        self.write(firewall.RULES, 'changed\n', 0o600)
        self.write(firewall.RULES6, 'six\n')
        result = self.run_action('firewall.restoreRules')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual((self.read(firewall.RULES), self.read(firewall.RULES6)), ('saved\n', 'six\n'))
        self.assertEqual(self.mode(firewall.RULES), 0o640)
        self.assertFalse(os.path.exists(self.at(firewall.CONF)))

    def test_a_restore_stops_at_the_first_rule_it_cannot_put_back(self):
        self.write(firewall.SNAPSHOT + '/user.rules', 'saved\n')
        self.write(firewall.SNAPSHOT + '/ufw.conf', 'ENABLED=yes\n')
        result = self.run_action('firewall.restoreRules')
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'user.rules', result.stderr)
        self.assertFalse(os.path.exists(os.path.dirname(self.at(firewall.RULES))))



class HostingAccountsTest(support.Tree):
    """`users.hostingAccounts`: every hosting account, and which of them `create` made."""

    def passwd_entries(self):
        homes = self.roots['homes']
        return [
            *super().passwd_entries(),
            f'marked:x:1020:1021:{users.MARK}:{homes}/marked:/usr/sbin/nologin',
            f'admin:x:1000:1000:Ubuntu:{homes}/admin:/bin/bash',
            f'traced:x:1022:1022::{homes}/traced:/usr/sbin/nologin',
            f'directory:x:1023:1023::{homes}/directory:/usr/sbin/nologin',
            f'regrouped:x:1024:1025::{homes}/regrouped:/usr/sbin/nologin',
            f'bashy:x:1026:1026::{homes}/bashy:/bin/bash',
            f'renamed:x:1027:1027:{users.MARK}:{homes}/other:/usr/sbin/nologin',
            f'linked:x:1028:1028::{homes}/linked:/usr/sbin/nologin',
        ]

    def setUp(self):
        super().setUp()
        for name in ('traced', 'regrouped', 'bashy'):
            self.directory('homes', os.path.join(name, users.TRACE))
        self.directory('engine', os.path.join(users.PROJECTS, 'directory'))
        self.directory('homes', 'linked')
        os.symlink(self.base, self.path('homes', 'linked', users.TRACE))

    def lines(self):
        action = registry()['users.hostingAccounts']
        plan = action.plan(action.check([]), self.policy, True)
        output = Captured()
        self.assertEqual(plan.native(output), 0)
        return {name: fields for name, *fields in (line.split('\t') for line in output.data.decode().splitlines())}

    def test_a_marked_account_is_the_engines_whatever_group_useradd_gave_it(self):
        accounts = self.lines()

        self.assertEqual(
            accounts['marked'], ['1020', '1021', self.path('homes', 'marked'), '/usr/sbin/nologin', '1'],
        )

    def test_an_unmarked_account_is_the_engines_only_in_the_old_shape_with_an_engine_trace(self):
        accounts = self.lines()

        self.assertEqual(accounts['traced'][-1], '1')
        self.assertEqual(accounts['directory'][-1], '1')
        self.assertEqual(accounts['v'][-1], '0')
        self.assertEqual(accounts['u'][-1], '0')
        self.assertEqual(accounts['regrouped'][-1], '0')
        self.assertEqual(accounts['bashy'][-1], '0')
        self.assertEqual(accounts['linked'][-1], '0')

    def test_the_hosts_own_admin_is_never_the_engines(self):
        self.assertEqual(self.lines()['admin'][-1], '0')

    def test_a_mark_on_a_home_create_would_not_name_is_not_the_engines(self):
        self.assertEqual(self.lines()['renamed'][-1], '0')

    def test_no_system_account_a_quota_never_touches_and_no_home_outside_the_homes_root_is_listed(self):
        accounts = self.lines()

        for name in ('root', 'daemon', 'www-data', 'low', 'outside', 'deep', 'nobody'):
            self.assertNotIn(name, accounts)

    def test_an_unreadable_passwd_is_a_refusal(self):
        self.accounts.passwd = self.base + '/missing'
        action = registry()['users.hostingAccounts']
        plan = action.plan(action.check([]), self.policy, True)

        self.assertEqual(plan.native(Captured()), 77)


class Captured:
    """A native action's output, kept."""

    def __init__(self):
        self.data = b''

    def stdout(self, data):
        self.data += data

    def stderr(self, data):
        self.data += data


if __name__ == '__main__':
    unittest.main()
