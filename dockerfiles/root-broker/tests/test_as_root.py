"""Runs only as root: `docker compose exec root-broker sh -c 'cd /tmp && python3 -m unittest discover -s <checkout>/tests'`."""

import os
import shutil
import subprocess
import unittest

import support
from rootbroker import accounts, values
from rootbroker.actions import ACCOUNT, Action, Param, Policy, registry

OTHER = 54321


def filesystems(df):
    """What tells two mount namespaces apart, without used and available space, which move on a busy host."""
    rows = [line.split() for line in df.decode().splitlines()[1:]]
    return [(row[0], row[1], row[-1]) for row in rows]


@support.needs_root
class AsRootTest(support.Serving):
    def test_owners_are_handed_to_another_account(self):
        target = self.make('homes', 'u/.restore-1/file')
        result = self.pa_root('files.changeOwner', f'{OTHER}:{OTHER}', target)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((os.stat(target).st_uid, os.stat(target).st_gid), (OTHER, OTHER))

    def test_a_recursive_owner_change_takes_the_link_and_not_what_it_points_at(self):
        victim = self.make('engine', 'victim')
        link = self.path('homes', 'u', 'project')
        os.makedirs(os.path.dirname(link))
        os.symlink(victim, link)
        self.assertEqual(self.pa_root('files.changeOwnerRecursively', f'{OTHER}:{OTHER}', link).returncode, 0)
        self.assertEqual(os.lstat(link).st_uid, OTHER)
        self.assertEqual(os.stat(victim).st_uid, 0)

    def test_install_writes_the_copy_with_its_mode_as_root(self):
        source = self.make('exchange', 'pa-git-ssh-1', b'key')
        os.chown(source, OTHER, OTHER)
        target = self.path('homes', 'u', '.panelalpha-git-ssh-key-1')
        os.makedirs(os.path.dirname(target))
        self.assertEqual(self.pa_root('files.install', '0600', source, target).returncode, 0)
        self.assertEqual((os.stat(target).st_uid, oct(os.stat(target).st_mode & 0o777)), (0, oct(0o600)))


@support.needs_root
@unittest.skipUnless(shutil.which('nsenter'), 'needs nsenter to compare with')
class HostNamespacesTest(support.Serving):
    def broker_policy(self):
        return Policy({'homes': '/home'})

    def test_a_host_request_sees_what_nsenter_into_pid_1_sees(self):
        expected = subprocess.run(['nsenter', '--target', '1', '--all', 'df', '-P', '--', '/home'], capture_output=True)
        result = self.pa_root('files.diskFree', '/home', host=True)
        self.assertEqual((result.returncode, filesystems(result.stdout)), (expected.returncode, filesystems(expected.stdout)),
                         result.stderr)


@support.needs_root
class AsTheAccountTest(support.Serving):
    become = staticmethod(accounts.become)
    actions = {
        **registry(),
        'test.whoami': Action(
            (Param(values.account_id), Param(values.account_id)), lambda: ['sh', '-c', 'id -u; id -g; id -G'],
            identity=ACCOUNT,
        ),
    }

    def setUp(self):
        super().setUp()
        for directory in (self.base, *self.roots.values()):
            os.chmod(directory, 0o755)
        self.home = self.directory('homes', 'u')
        os.chown(self.home, support.UID, support.GID)
        self.ids = (str(support.UID), str(support.GID))

    def test_the_command_runs_as_the_account_with_no_other_group(self):
        result = self.pa_root('test.whoami', *self.ids)
        self.assertEqual((result.returncode, result.stdout), (0, f'{support.UID}\n{support.GID}\n{support.GID}\n'.encode()),
                         result.stderr)

    def test_what_the_account_makes_is_its_own(self):
        made = os.path.join(self.home, 'made')
        self.assertEqual(self.pa_root('account.touch', *self.ids, made).returncode, 0)
        self.assertEqual((os.stat(made).st_uid, os.stat(made).st_gid), (support.UID, support.GID))

    def test_the_account_cannot_write_where_root_could(self):
        kept = self.directory('homes', 'u/root-only')
        result = self.pa_root('account.touch', *self.ids, os.path.join(kept, 'x'))
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(os.path.exists(os.path.join(kept, 'x')))

    def test_the_walk_itself_sees_only_what_the_account_may(self):
        closed = self.make('homes', 'u/closed/secret', b'secret')
        os.chmod(os.path.dirname(closed), 0o700)
        result = self.pa_root('account.read', *self.ids, closed)
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'Permission denied', result.stderr)
        self.assertEqual(result.stdout, b'')
