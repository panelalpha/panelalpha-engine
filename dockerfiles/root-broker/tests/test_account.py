import json
import os
import re
import shutil
import stat
import subprocess
import sys
import unittest
import zipfile

import accountwork
import casework
import support
from rootbroker.actions import ACCOUNT, Action, Param, account, registry, scripts
from rootbroker.refused import Refused

PRIVILEGED = os.path.join(support.HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged')
GATEWAY = ('AccountFiles', 'AccountCommands', 'AccountFileManager')
NOT_ACTIONS = {'__construct', 'writeFrom'}
U, G = str(support.UID), str(support.GID)
MS_DOS, UNIX = 0, 3


class CasesTest(unittest.TestCase):
    def test_each_case_runs_the_sudo_command_word_for_word_on_the_same_paths(self):
        for case in accountwork.load():
            if case['method'] in accountwork.NATIVE:
                continue
            with self.subTest(method=case['method'], args=case['args']):
                scratch = accountwork.Scratch()
                try:
                    plan = accountwork.plan_case(scratch, case)
                    expected = accountwork.unprivileged(case)
                    self.assertEqual(len(plan.argv), len(expected), plan.argv)
                    for want, got in zip(expected, plan.argv):
                        self.assertTrue(accountwork.same_word(scratch, plan, want, got), (want, got))
                    casework.close(plan)
                finally:
                    scratch.close()

    def test_every_script_is_the_gateways_own_text(self):
        known = {value for name, value in vars(scripts).items() if name.isupper()}
        seen = set()
        for case in accountwork.load():
            sudo = case['sudo']
            if '-c' in sudo and sudo[sudo.index('-c') - 1] in ('sh', 'bash'):
                self.assertIn(sudo[sudo.index('-c') + 1], known, case['method'])
                seen.add(sudo[sudo.index('-c') + 1])
        self.assertEqual(seen, known - {scripts.ENTRY_FIELDS})

    @unittest.skipUnless(os.path.isdir(PRIVILEGED), 'needs the engine checkout around the broker')
    def test_every_gateway_method_has_an_action_and_a_case(self):
        methods = set()
        for name in GATEWAY:
            with open(os.path.join(PRIVILEGED, name + '.php')) as f:
                methods |= set(re.findall(r'public function (\w+)\(', f.read()))
        methods -= NOT_ACTIONS
        actions = {name.removeprefix('account.') for name in registry() if name.startswith('account.')}
        self.assertEqual(methods, actions)
        self.assertEqual(methods, {case['method'] for case in accountwork.load()})


class AccountCheckTest(support.Serving):
    def setUp(self):
        super().setUp()
        self.home = self.directory('homes', 'u')
        self.other = self.directory('homes', 'v')
        self.mine = self.make('homes', 'u/my file', b'mine')
        self.theirs = self.make('homes', 'v/their file', b'theirs')

    def refused(self, action, *request_values, host=False):
        result = self.pa_root(action, *request_values, host=host)
        self.assertEqual(result.returncode, 77, result.stderr)
        return result.stderr

    def test_the_account_reads_its_own_file(self):
        result = self.pa_root('account.read', U, G, self.mine)
        self.assertEqual((result.returncode, result.stdout), (0, b'mine'), result.stderr)

    def test_a_path_in_another_accounts_home_is_refused(self):
        self.assertIn(b'is not below', self.refused('account.read', U, G, self.theirs))
        self.assertIn(b'is not below', self.refused('account.removeIfExists', U, G, self.mine, self.theirs))
        self.assertTrue(os.path.exists(self.mine))
        self.assertIn(b'is not below', self.refused('account.exists', U, G, self.theirs))

    def test_the_other_accounts_uid_does_not_reach_this_home_either(self):
        self.assertIn(b'is not below', self.refused('account.read', str(support.OTHER_UID), str(support.OTHER_UID), self.mine))

    def test_root_is_never_an_account(self):
        self.assertIn(b'root', self.refused('account.read', '0', '0', self.mine))
        self.assertIn(b'root', self.refused('account.read', U, '0', self.mine))

    def test_a_uid_that_is_no_account_is_refused(self):
        self.assertIn(b'no account', self.refused('account.read', '4242', '4242', self.mine))

    def test_a_gid_that_is_not_the_accounts_primary_group_is_refused(self):
        self.assertIn(b'primary group', self.refused('account.read', U, U, self.mine))
        self.assertIn(b'primary group', self.refused('account.read', U, '33', self.mine))

    def test_system_users_and_nobody_are_not_hosting_accounts(self):
        for uid in ('1', '33', '999', '65534'):
            with self.subTest(uid=uid):
                self.assertIn(b'not a hosting account', self.refused('account.read', uid, uid, self.mine))

    def test_an_account_whose_home_is_not_directly_below_the_homes_is_refused(self):
        for uid in ('1010', '1011'):
            with self.subTest(uid=uid):
                self.assertIn(b'not directly below', self.refused('account.read', uid, uid, self.mine))

    def test_a_value_that_is_not_an_id_is_refused(self):
        for ids in (('-1', G), ('1004 ', G), ('', G), ('u', G)):
            with self.subTest(ids=ids):
                self.refused('account.read', *ids, self.mine)

    def test_the_accounts_own_engine_directory_is_its_and_no_one_elses(self):
        log = self.make('engine', 'users/u/log/php/php_error.log', b'a\nb\n')
        self.make('engine', 'users/v/log/php/php_error.log', b'x\n')
        result = self.pa_root('account.lastLinesOfLog', U, G, log, '1')
        self.assertEqual((result.returncode, result.stdout), (0, b'b\n'), result.stderr)
        self.refused('account.lastLinesOfLog', U, G, self.path('engine', 'users', 'v', 'log', 'php', 'php_error.log'), '1')
        self.refused('account.read', U, G, log)

    def test_a_log_handed_to_another_owner_is_refused(self):
        log = self.make('engine', 'users/u/log/php/php_error.log')
        self.assertIn(b'is not uid:gid', self.refused('account.ensureLogFile', U, G, log, f'{support.OTHER_UID}:{support.OTHER_UID}'))

    def test_a_script_that_walks_from_the_home_takes_only_the_accounts_home_itself(self):
        self.assertIn(b'is not', self.refused('account.removeFileBelow', U, G, self.other, 'x', 'y'))
        self.assertIn(b'is not', self.refused('account.ensureDirectoryBelow', U, G, self.path('homes', 'u', 'sub'), 'x', '0700'))
        self.refused('account.removeFileBelow', U, G, self.home, '../v', 'their file')
        self.assertTrue(os.path.exists(self.theirs))

    def test_the_file_manager_names_only_paths_in_the_home(self):
        self.refused('account.confine', U, G, self.home, 'F:' + self.theirs)
        self.refused('account.confine', U, G, self.other, 'F:' + self.theirs)
        self.refused('account.removeEntry', U, G, self.theirs, '1')
        self.refused('account.writeCopy', U, G, self.mine, self.path('homes', 'u', 'copy'))
        self.refused('account.download', U, G, 'file:///etc/passwd', self.path('homes', 'u', 'x'), '10', '')
        self.refused('account.zipFrom', U, G, self.home, self.path('homes', 'u', 'a.zip'), '-T', '', '')
        self.assertTrue(os.path.exists(self.theirs))

    def test_a_path_that_climbs_into_another_home_is_refused_even_where_it_may_be_resolved(self):
        self.refused('account.resolvedFile', U, G, self.path('homes', 'u', '..', 'v', 'their file'))
        inside = self.path('homes', 'u', 'sub', '..', 'my file')
        self.directory('homes', 'u/sub')
        result = self.pa_root('account.resolvedFile', U, G, inside)
        self.assertEqual((result.returncode, result.stdout.decode().strip()), (0, self.mine), result.stderr)

    def test_git_on_the_host_must_ask_for_the_host(self):
        self.assertIn(b'host', self.refused('account.gitOnHost', 'u', 'env', 'git', 'status'))

    def test_the_audit_line_names_the_account_values(self):
        self.pa_root('account.read', U, G, self.mine)
        entry = json.loads(self.audit_lines()[-1].removeprefix('pa-root-audit '))
        self.assertEqual((entry['action'], entry['values'][:2], entry['exit']), ('account.read', [U, G], 0))


class GitOnHostTest(support.Tree):
    def plan(self, *command):
        action = registry()['account.gitOnHost']
        return action.plan(action.check(['u', *command]), self.policy, host=True)

    def test_the_engines_git_commands_run_as_given(self):
        askpass = self.path('homes', 'u', '.panelalpha-git-askpass-ab12')
        key = self.path('homes', 'u', '.panelalpha-git-ssh-key-ab12')
        known = self.path('homes', 'u', '.panelalpha-git-ssh-known-hosts-ab12')
        ssh = (f'ssh -i {key} -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes -o StrictHostKeyChecking=yes'
               f' -o UserKnownHostsFile={known} -o GlobalKnownHostsFile=/dev/null')
        commands = [
            ['env', 'GIT_TERMINAL_PROMPT=0', 'GIT_ASKPASS=/bin/false', 'SSH_ASKPASS=/bin/false', 'git', '-C',
             self.path('homes', 'u', 'public html'), 'status', '--porcelain'],
            ['env', 'GIT_ALLOW_PROTOCOL=https', 'GIT_TERMINAL_PROMPT=0', 'SSH_ASKPASS=/bin/false', 'GIT_ASKPASS=' + askpass,
             'git', 'ls-remote', '--heads', 'https://example.com/r.git'],
            ['env', 'GIT_SSH_COMMAND=' + ssh, 'GIT_TERMINAL_PROMPT=0', 'git', 'commit', '-m', 'two\nlines'],
        ]
        for command in commands:
            with self.subTest(command=command):
                plan = self.plan(*command)
                self.assertEqual(plan.argv, command)
                self.assertEqual(plan.environment['HOME'], self.path('homes', 'u'))
                self.assertEqual(plan.environment['USER'], 'u')

    def test_anything_but_env_then_git_is_refused(self):
        refused = [
            ['sh', '-c', 'id'], ['git', 'status'], ['env', 'bash'], ['env', 'LD_PRELOAD=/tmp/x.so', 'git', 'log'],
            ['env', 'GIT_ASKPASS=/tmp/evil', 'git', 'fetch'], ['env', 'GIT_ASKPASS=' + self.path('homes', 'v', '.panelalpha-git-askpass-1'), 'git', 'fetch'],
            ['env', 'GIT_SSH_COMMAND=sh -c id', 'git', 'fetch'],
            ['env', 'GIT_TERMINAL_PROMPT=0', 'git', '-C', self.path('homes', 'v'), 'status'],
            ['env', 'GIT_TERMINAL_PROMPT=0', 'git', '-C'], ['env', 'git', 'status', '-C'],
        ]
        for command in refused:
            with self.subTest(command=command), self.assertRaises(Refused):
                self.plan(*command)

    def test_an_account_named_that_is_none_is_refused(self):
        action = registry()['account.gitOnHost']
        for name in ('root', 'nobody', 'nosuch', 'low'):
            with self.subTest(name=name), self.assertRaises(Refused):
                action.plan(action.check([name, 'env', 'git', 'status']), self.policy, host=True)


class FileManagerTest(support.Serving):
    def setUp(self):
        super().setUp()
        self.home = self.directory('homes', 'u')
        self.make('homes', 'u/my dir/a.txt', b'12345')
        self.directory('homes', 'u/my dir/sub/deeper')
        os.symlink('my dir', os.path.join(self.home, 'inside'))
        os.symlink(self.roots['engine'], os.path.join(self.home, 'outside'))

    def same(self, action, values, argv):
        expected = subprocess.run(argv, capture_output=True)
        result = self.pa_root(action, U, G, *values)
        self.assertEqual((result.returncode, result.stdout), (expected.returncode, expected.stdout), result.stderr)

    def test_links_inside_the_home_are_followed_as_before(self):
        h = self.home
        cases = [
            ('account.exists', [h + '/inside/a.txt'], ['test', '-e', h + '/inside/a.txt']),
            ('account.isFile', [h + '/inside/a.txt'], ['test', '-f', h + '/inside/a.txt']),
            ('account.entryType', [h + '/inside/'], ['sh', '-c', scripts.ENTRY_TYPE_SCRIPT, 'sh', h + '/inside/', h + '/inside']),
            ('account.confine', [h, 'F:' + h + '/inside/a.txt', 'E:' + h + '/outside'],
             ['sh', '-c', scripts.CONFINE_SCRIPT, 'sh', h, 'F:' + h + '/inside/a.txt', 'E:' + h + '/outside']),
            ('account.confine', [h, 'F:' + h + '/outside/x'], ['sh', '-c', scripts.CONFINE_SCRIPT, 'sh', h, 'F:' + h + '/outside/x']),
            ('account.listPage', [h + '/inside/', '10', '0'],
             ['sh', '-c', scripts.LIST_PAGE_SCRIPT, 'sh', h + '/inside/', '10', '!', '-name', '.*']),
            ('account.subdirectoryParents', [h + '/'], ['find', h + '/', '-mindepth', '2', '-maxdepth', '2', '-type', 'd', '-printf', '%h\\0']),
            ('account.diskUsageExcluding', [h + '/', h + '/docker'], ['du', '-shm', '--exclude=' + h + '/docker', h + '/']),
        ]
        for action, values, argv in cases:
            with self.subTest(action=action, values=values):
                self.same(action, values, argv)


class WalkAsTheAccountTest(support.Serving):
    def setUp(self):
        super().setUp()
        self.home = self.directory('homes', 'u')

    def test_a_symlinked_directory_on_the_way_is_refused(self):
        victim = self.make('homes', 'v/keep', b'keep')
        os.symlink(os.path.dirname(victim), os.path.join(self.home, 'project'))
        result = self.pa_root('account.removeIfExists', U, G, os.path.join(self.home, 'project', 'keep'))
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'is a symlink', result.stderr)
        self.assertTrue(os.path.exists(victim))

    def test_a_directory_the_account_may_not_search_fails_as_the_command_would(self):
        if os.geteuid() == 0:
            self.skipTest('root searches any directory')
        closed = self.make('homes', 'u/closed/file', b'x')
        os.chmod(os.path.dirname(closed), 0)
        try:
            expected = subprocess.run(['cat', '--', closed], capture_output=True)
            result = self.pa_root('account.read', U, G, closed)
        finally:
            os.chmod(os.path.dirname(closed), 0o755)
        self.assertEqual(result.returncode, expected.returncode)
        self.assertIn(b'Permission denied', result.stderr)

    def test_disk_usage_leaves_out_the_same_directory_as_before(self):
        self.make('homes', 'u/docker/big', b'x' * (3 << 20))
        self.make('homes', 'u/site/index.php', b'<?php')
        for path in (self.home + '/', self.home):
            with self.subTest(path=path):
                expected = subprocess.run(['du', '-shm', '--exclude=' + self.home + '/docker', '--', path], capture_output=True)
                result = self.pa_root('account.diskUsageInMegabytes', U, G, path, self.home + '/docker')
                self.assertEqual((result.returncode, result.stdout), (0, expected.stdout), result.stderr)

    def test_the_account_writes_its_file_through_a_link_replaced_not_followed(self):
        victim = self.make('homes', 'v/.user.ini', b'theirs')
        target = os.path.join(self.directory('homes', 'u/site'), '.user.ini')
        os.symlink(victim, target)
        result = self.pa_root('account.write', U, G, target, '0644', stdin=b'memory_limit=1G\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(os.path.islink(target))
        with open(target, 'rb') as f:
            self.assertEqual(f.read(), b'memory_limit=1G\n')
        with open(victim, 'rb') as f:
            self.assertEqual(f.read(), b'theirs')

    def test_archives_unpack_from_the_stage_into_the_home(self):
        source = self.directory('exchange', 'src')
        self.make('exchange', 'src/top/a.txt', b'a')
        staged = self.path('archive-stage', 'ab', 'archive.tar.gz')
        os.makedirs(os.path.dirname(staged))
        subprocess.run(['tar', '-czf', staged, '-C', source, 'top'], check=True)
        into = self.directory('homes', 'u/.panelalpha/archive-extract 1')
        result = self.pa_root('account.extractTar', U, G, staged, into)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(os.path.isfile(os.path.join(into, 'top', 'a.txt')))
        self.assertEqual(self.pa_root('account.normaliseModes', U, G, into).returncode, 0)
        self.assertEqual(stat.S_IMODE(os.stat(os.path.join(into, 'top', 'a.txt')).st_mode), 0o644)
        self.assertEqual(self.pa_root('account.extractTar', U, G, self.path('backup', 'x.tar.gz'), into).returncode, 77)

    @unittest.skipUnless(shutil.which('unzip'), 'needs unzip')
    def test_a_zip_made_on_windows_unpacks_into_its_folders_and_its_warning_is_no_failure(self):
        staged = self.staged_zip({'index.php': b'<?php require "inc/hello.php";', 'inc\\hello.php': b'<?php'}, MS_DOS)
        into = self.directory('homes', 'u/.panelalpha/archive-extract 1')
        result = self.pa_root('account.extractZip', U, G, staged, into)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(os.path.isfile(os.path.join(into, 'inc', 'hello.php')))
        self.assertIn(b'appears to use backslashes as path separators', result.stderr)
        self.assertIn(b'extracting: inc/hello.php', result.stdout)

    @unittest.skipUnless(shutil.which('unzip'), 'needs unzip')
    def test_a_backslash_in_a_zip_made_on_unix_stays_in_the_name(self):
        staged = self.staged_zip({'inc\\hello.php': b'<?php echo 1;'}, UNIX)
        into = self.directory('homes', 'u/.panelalpha/archive-extract 1')
        result = self.pa_root('account.extractZip', U, G, staged, into)
        self.assertEqual((result.returncode, os.listdir(into)), (0, ['inc\\hello.php']), result.stderr)

    @unittest.skipUnless(shutil.which('unzip'), 'needs unzip')
    def test_any_other_unzip_failure_keeps_its_status(self):
        staged = self.make('archive-stage', 'ab/archive.zip', b'not a zip')
        result = self.pa_root('account.extractZip', U, G, staged, self.directory('homes', 'u/x'))
        self.assertEqual(result.returncode, 9, result.stderr)

    @unittest.skipUnless(shutil.which('unzip'), 'needs unzip')
    def test_the_file_manager_unpacks_a_zip_made_on_windows_into_its_folders_and_its_warning_is_no_failure(self):
        staged = self.staged_zip({'index.php': b'<?php', 'inc\\hello.php': b'<?php'}, MS_DOS, 'unzip-stage')
        into = self.directory('unzip-stage', 'ab/unpacked')
        result = self.pa_root('account.unzipInto', U, G, staged, into)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(os.path.isfile(os.path.join(into, 'inc', 'hello.php')))
        self.assertIn(b'appears to use backslashes as path separators', result.stderr)

    @unittest.skipUnless(shutil.which('unzip'), 'needs unzip')
    def test_any_other_failure_of_the_file_managers_unzip_keeps_its_status(self):
        staged = self.make('unzip-stage', 'ab/archive.zip', b'not a zip')
        result = self.pa_root('account.unzipInto', U, G, staged, self.directory('unzip-stage', 'ab/unpacked'))
        self.assertEqual(result.returncode, 9, result.stderr)

    def staged_zip(self, members, system, root='archive-stage'):
        staged = self.path(root, 'ab', 'archive.zip')
        os.makedirs(os.path.dirname(staged))
        with zipfile.ZipFile(staged, 'w') as archive:
            for name, data in members.items():
                member = zipfile.ZipInfo(name)
                member.create_system = system
                archive.writestr(member, data)
        return staged


class HomeFileTest(support.Serving):
    def setUp(self):
        super().setUp()
        self.homes = self.roots['homes']
        self.home = self.directory('homes', 'u')
        self.make('homes', 'u/site/.env', b'APP_KEY=x\n')
        self.make('homes', 'v/secret', b'secret')
        os.symlink(self.path('homes', 'v'), os.path.join(self.home, 'link-dir'))
        os.symlink(self.path('homes', 'v', 'secret'), os.path.join(self.home, 'site', 'link-file'))
        os.mkfifo(os.path.join(self.home, 'site', 'fifo'))

    def test_reads_end_as_the_helper_ended(self):
        base = self.base.encode()
        cases = [
            (self.home, self.home + '/site/.env', 0, b'APP_KEY=x\n', b''),
            (self.home, self.home + '/link-dir/secret', 3, b'', b"'%s/homes/u/link-dir' is a symlink or not a directory\n" % base),
            (self.home, self.home + '/site/link-file', 3, b'', b"'%s/homes/u/site/link-file' is a symlink\n" % base),
            (self.home, self.home + '/site/fifo', 4, b'', b"'%s/homes/u/site/fifo' is not a regular file\n" % base),
            (self.home, self.home + '/site/missing', 1, b'', b'No such file or directory\n'),
            (self.home, self.home + '/site/./.env', 1, b'',
             b"'%s/homes/u/site/./.env' is not an absolute path without . or ..\n" % base),
            (self.homes, self.home + '/site/.env', 0, b'APP_KEY=x\n', b''),
            (self.home, self.path('homes', 'v', 'secret'), 1, b'', b"'%s/homes/v' is not below %s/homes/u\n" % (base, base)),
        ]
        for root, path, code, stdout, stderr in cases:
            with self.subTest(root=root, path=path):
                result = self.pa_root('account.readInHome', root, path)
                self.assertEqual((result.returncode, result.stdout, result.stderr), (code, stdout, stderr))

    def test_renames_end_as_the_helper_ended(self):
        stage = self.directory('homes', '.panelalpha-write-stage')
        base = self.base.encode()
        cases = [
            (self.home + '/site/new', 0, b''),
            (self.home + '/link-dir/new', 3, b"'%s/homes/u/link-dir' is a symlink or not a directory\n" % base),
            (self.home + '/missing/new', 1, b'No such file or directory\n'),
        ]
        for target, code, stderr in cases:
            with self.subTest(target=target):
                with open(os.path.join(stage, 'broker'), 'w') as f:
                    f.write('broker')
                result = self.pa_root('account.renameInHome', self.homes, stage + '/broker', target)
                self.assertEqual((result.returncode, result.stderr), (code, stderr))
        self.assertFalse(os.path.exists(self.path('homes', 'v', 'new')))

    def test_the_root_must_be_the_homes_or_a_home_directly_below_them(self):
        for root in (self.home + '/site', self.roots['engine'], '/'):
            with self.subTest(root=root):
                self.assertEqual(self.pa_root('account.readInHome', root, root + '/.env').returncode, 77)


class IdentityTest(support.Tree):
    def test_the_account_is_become_before_the_walk_and_not_at_all_when_refused(self):
        events = []
        self.policy.become = lambda acct, own_groups=False: events.append(('become', acct.name, own_groups))
        path = self.make('homes', 'u/f')
        probe = Action((Param(lambda v: v), Param(lambda v: v), Param(lambda v: v, 'entry', ('home',))),
                       lambda p: events.append(('build', p)) or ['true'], identity=ACCOUNT)
        probe.plan(probe.check([U, G, path]), self.policy)
        self.assertEqual(events, [('become', 'u', False), ('build', './f')])
        events.clear()
        with self.assertRaises(Refused):
            probe.plan(probe.check([U, '33', path]), self.policy)
        self.assertEqual(events, [])

    def test_every_account_action_takes_its_ids_first(self):
        for name, action in account.ACTIONS.items():
            with self.subTest(action=name):
                if action.identity == ACCOUNT:
                    self.assertEqual(action.params[0].check('1004'), '1004')
                    with self.assertRaises(Refused):
                        action.params[1].check('-1')


if __name__ == '__main__':
    unittest.main()
