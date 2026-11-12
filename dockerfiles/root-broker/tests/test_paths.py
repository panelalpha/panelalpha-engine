import os
import stat
import subprocess
import tarfile
import unittest

import support
from rootbroker import paths
from rootbroker.actions import registry
from rootbroker.refused import Refused


class PathPolicyTest(support.Serving):
    def test_a_path_under_an_allowed_root_is_acted_on(self):
        tree = self.directory('homes', 'u/.project-prev/sub')
        result = self.pa_root('files.removeTree', os.path.dirname(tree))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(os.path.exists(os.path.dirname(tree)))

    def test_a_path_under_a_root_the_action_does_not_allow_is_refused(self):
        kept = self.directory('engine', 'users/u/in')
        result = self.pa_root('files.removeEmptyDirectory', kept)
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'is not below', result.stderr)
        self.assertTrue(os.path.isdir(kept))

    def test_a_path_outside_every_root_is_refused(self):
        self.assertEqual(self.pa_root('files.read', '/etc/hostname').returncode, 77)
        self.assertEqual(self.pa_root('files.removeTree', self.base + '-elsewhere').returncode, 77)

    def test_a_path_that_climbs_out_is_refused(self):
        victim = self.make('engine', 'victim', b'keep')
        result = self.pa_root('files.removeTree', self.path('homes', 'u', '..', '..', 'engine', 'victim'))
        self.assertEqual(result.returncode, 77)
        self.assertTrue(os.path.exists(victim))

    def test_a_parent_swapped_for_a_symlink_is_refused_where_sudo_would_have_followed_it(self):
        victim = self.make('engine', 'secret/victim', b'keep')
        os.makedirs(self.path('homes', 'u'))
        os.symlink(os.path.dirname(victim), self.path('homes', 'u', 'project'))
        planted = self.path('homes', 'u', 'project', 'victim')
        result = self.pa_root('files.removeTree', planted)
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'is a symlink', result.stderr)
        self.assertTrue(os.path.exists(victim))
        subprocess.run(['rm', '-rf', '--', planted], check=True)
        self.assertFalse(os.path.exists(victim), 'the same command without the walk follows the link')

    def test_a_symlinked_final_component_is_refused_where_the_tool_would_follow_it(self):
        victim = self.make('engine', 'victim', b'keep')
        os.chmod(victim, 0o640)
        link = self.path('homes', 'u', 'link')
        os.makedirs(os.path.dirname(link))
        os.symlink(victim, link)
        source = self.make('exchange', 'tmp_x', b'replaced')
        refused = [
            ('files.changeMode', '600', link), ('files.changeOwner', f'{os.getuid()}:{os.getgid()}', link),
            ('files.read', link), ('files.copy', source, link), ('files.listAllNames', link),
            ('files.mirror', self.directory('homes', 'u/src'), link),
        ]
        for request in refused:
            with self.subTest(action=request[0]):
                result = self.pa_root(*request)
                self.assertEqual(result.returncode, 77, result.stderr)
        self.assertEqual(stat.S_IMODE(os.stat(victim).st_mode), 0o640)
        with open(victim, 'rb') as f:
            self.assertEqual(f.read(), b'keep')

    def test_touch_creates_a_missing_file_but_never_through_a_dangling_symlink(self):
        target = self.path('engine', 'created-through-the-link')
        link = self.path('coverage', 't1', 'recording')
        os.makedirs(os.path.dirname(link))
        os.symlink(target, link)
        self.assertEqual(self.pa_root('files.touch', link).returncode, 77)
        self.assertFalse(os.path.exists(target))
        fresh = self.path('coverage', 't1', 'other')
        self.assertEqual(self.pa_root('files.touch', fresh).returncode, 0)
        self.assertTrue(stat.S_ISREG(os.lstat(fresh).st_mode))

    def test_a_tool_that_does_not_follow_the_final_link_acts_on_the_link_itself(self):
        victim = self.directory('engine', 'kept')
        self.make('engine', 'kept/file')
        link = self.path('homes', 'u', '.project-prev')
        os.makedirs(os.path.dirname(link))
        os.symlink(victim, link)
        self.assertEqual(self.pa_root('files.removeTree', link).returncode, 0)
        self.assertFalse(os.path.lexists(link))
        self.assertTrue(os.path.exists(os.path.join(victim, 'file')))

    def test_a_trailing_slash_on_a_symlink_is_refused_because_it_makes_the_tool_follow_it(self):
        victim = self.directory('engine', 'kept')
        self.make('engine', 'kept/file')
        link = self.path('homes', 'u', 'aside')
        os.makedirs(os.path.dirname(link))
        os.symlink(victim, link)
        self.assertEqual(self.pa_root('files.removeTree', link + '/').returncode, 77)
        self.assertTrue(os.path.exists(os.path.join(victim, 'file')))

    def test_a_missing_or_file_parent_ends_as_the_tool_ends_on_the_real_path(self):
        self.make('homes', 'u/file')
        cases = [
            (['rm', '-f', '--'], 'files.removeIfExists', 'homes', 'u/missing/x'),
            (['rm', '--'], 'files.remove', 'engine', 'users/u/missing/x'),
            (['rm', '-f', '--'], 'files.removeIfExists', 'homes', 'u/file/x'),
            (['test', '-e'], 'files.exists', 'homes', 'u/missing/x'),
            (['mkdir', '--'], 'files.makeDirectory', 'homes', 'u/missing/x'),
            (['rmdir', '--'], 'files.removeEmptyDirectory', 'homes', 'u/file/x'),
        ]
        for command, action, root, relative in cases:
            with self.subTest(action=action, path=relative):
                full = self.path(root, relative)
                expected = subprocess.run([*command, full], capture_output=True).returncode
                self.assertEqual(self.pa_root(action, full).returncode, expected)
        self.assertFalse(os.path.exists(self.path('homes', 'u', 'missing')))

    def test_a_file_missing_at_the_check_ends_as_the_tool_ends_on_the_real_path(self):
        into = self.directory('homes', 'u/into')
        self.directory('engine', 'users/u')
        self.directory('backup', 'u')
        archive = self.path('backup', 'u', 'gone.tar.gz')
        cases = [
            (['cat', '--'], 'files.read', [], self.path('engine', 'users', 'u', 'gone')),
            (['tail', '-n', '1', '--'], 'files.lastLines', ['1'], self.path('engine-log', 'gone')),
            (['ls', '-1A', '--'], 'files.listAllNames', [], self.path('homes', 'u', 'gone')),
            (['chmod', '--', '600'], 'files.changeMode', ['600'], self.path('homes', 'u', 'gone')),
        ]
        for command, action, before, missing in cases:
            with self.subTest(action=action):
                expected = subprocess.run([*command, missing], capture_output=True).returncode
                self.assertEqual(self.pa_root(action, *before, missing).returncode, expected)
        expected = subprocess.run(['tar', '-xzf', archive, '-C', into], capture_output=True).returncode
        self.assertEqual(self.pa_root('files.extractArchive', archive, into, '0').returncode, expected)
        self.assertEqual(os.listdir(into), [])

    def test_a_target_its_tool_creates_is_still_created_when_missing(self):
        source = self.directory('homes', 'u/src')
        self.make('homes', 'u/src/a.txt', b'a')
        self.directory('backup', 'u/1')
        for action, target in (('files.copyTree', 'copied'), ('files.mirror', 'mirrored')):
            with self.subTest(action=action):
                result = self.pa_root(action, source, self.path('homes', 'u', target))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(contents(self.path('homes', 'u', target, 'a.txt')), b'a')
        archive = self.path('backup', 'u', '1', 'new.tar.gz')
        result = self.pa_root('files.createArchive', source, archive, '.')
        self.assertEqual(result.returncode, 0, result.stderr)
        with tarfile.open(archive) as members:
            self.assertIn('./a.txt', members.getnames())

    def test_a_move_ends_as_mv_ends_on_the_real_paths(self):
        for action, options in (('files.move', []), ('files.moveOverwriting', ['-f'])):
            for source in ('file', 'directory'):
                for target in ('missing', 'file', 'directory', 'directory/'):
                    with self.subTest(action=action, source=source, target=target):
                        name = f'{action}-{source}-{target.rstrip("/")}{"-slash" if target.endswith("/") else ""}'
                        by_mv = self.move_layout('mv-' + name, source, target)
                        by_broker = self.move_layout('broker-' + name, source, target)
                        expected = subprocess.run(['mv', *options, '--', *by_mv], capture_output=True).returncode
                        self.assertEqual(self.pa_root(action, *by_broker).returncode, expected)
                        self.assertEqual(entries(os.path.dirname(by_broker[0])), entries(os.path.dirname(by_mv[0])))

    def move_layout(self, name, source, target):
        base = self.directory('homes', 'u/' + name)
        moved, onto = os.path.join(base, 'src'), os.path.join(base, 'dst')
        if source == 'file':
            self.make('homes', f'u/{name}/src', b'moved')
        else:
            self.make('homes', f'u/{name}/src/inner', b'moved')
        if target == 'file':
            self.make('homes', f'u/{name}/dst', b'old')
        elif target != 'missing':
            os.makedirs(onto)
        return moved, onto + ('/' if target.endswith('/') else '')

    def test_make_directories_creates_every_missing_one_and_never_through_a_symlink(self):
        nested = self.path('homes', 'u', 'my dir', 'a', 'b')
        self.assertEqual(self.pa_root('files.makeDirectories', nested).returncode, 0)
        self.assertTrue(os.path.isdir(nested))
        os.symlink(self.roots['engine'], self.path('homes', 'u', 'into-engine'))
        result = self.pa_root('files.makeDirectories', self.path('homes', 'u', 'into-engine', 'x', 'y'))
        self.assertEqual(result.returncode, 77)
        self.assertFalse(os.path.exists(self.path('engine', 'x')))

    def test_a_directory_on_the_way_is_never_created_as_a_symlink_target_by_mode(self):
        result = self.pa_root('files.makeDirectoriesWithMode', '0700', self.path('homes', '.panelalpha-write-stage'))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(stat.S_IMODE(os.stat(self.path('homes', '.panelalpha-write-stage')).st_mode), 0o700)

    def test_a_trusted_root_follows_its_own_links_as_sudo_did(self):
        run = self.make('engine-log', 'change-webserver/run-1/stdout', b'line one\nline two\n')
        os.symlink('run-1', self.path('engine-log', 'change-webserver', 'latest'))
        result = self.pa_root('files.lastLines', '1', self.path('engine-log', 'change-webserver', 'latest', 'stdout'))
        self.assertEqual((result.returncode, result.stdout), (0, b'line two\n'), result.stderr)
        self.assertTrue(os.path.exists(run))

    def test_the_root_itself_may_be_named(self):
        self.directory('homes', 'u')
        result = self.pa_root('files.listAllNames', self.roots['homes'])
        self.assertEqual((result.returncode, result.stdout), (0, b'u\n'), result.stderr)
        self.assertEqual(self.pa_root('files.diskFree', self.roots['homes']).returncode, 0)

    def test_real_paths_may_climb_but_must_stay_below_a_root(self):
        self.make('homes', 'v/.env')
        self.directory('homes', 'u/project')
        inside = self.path('homes', 'u', 'project', '..', '..', 'v', '.env')
        result = self.pa_root('files.existingRealPaths', self.path('homes', 'u'), inside)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.decode().split(), [self.path('homes', 'u'), self.path('homes', 'v', '.env')])
        outside = self.path('homes', 'u', '..', '..', 'engine')
        self.assertEqual(self.pa_root('files.existingRealPaths', outside).returncode, 77)

    def test_a_real_path_never_follows_a_link_out_of_its_root(self):
        self.make('engine', 'secret')
        self.directory('homes', 'u')
        os.symlink(self.path('engine', 'secret'), self.path('homes', 'u', 'l'))
        os.symlink('../../engine', self.path('homes', 'u', 'up'))
        for given in (self.path('homes', 'u', 'l', '..', '..', 'homes', 'u'), self.path('homes', 'u', 'l'),
                      self.path('homes', 'u', 'up', 'secret')):
            with self.subTest(given=given):
                result = self.pa_root('files.existingRealPaths', self.path('homes', 'u'), given)
                self.assertEqual((result.returncode, result.stdout), (77, b''), result.stderr)

    def test_a_real_path_follows_a_link_that_stays_below_its_root(self):
        self.make('homes', 'u/public_html/index.php')
        os.symlink('public_html', self.path('homes', 'u', 'www'))
        os.symlink(self.path('homes', 'u', 'public_html'), self.path('homes', 'u', 'absolute'))
        for name in ('www', 'absolute'):
            with self.subTest(link=name):
                result = self.pa_root('files.existingRealPaths', self.path('homes', 'u'), self.path('homes', 'u', name, 'index.php'))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.decode().split(), [self.path('homes', 'u'), self.path('homes', 'u', 'public_html', 'index.php')])
        missing = self.pa_root('files.existingRealPaths', self.path('homes', 'u', 'www', 'gone.php'))
        self.assertEqual((missing.returncode, missing.stdout), (1, b''))
        self.assertIn(b'No such file or directory', missing.stderr)


class FallbackWalkTest(PathPolicyTest):
    """The O_NOFOLLOW walk, for a kernel without openat2."""

    def setUp(self):
        self.saved = paths.openat2_available
        paths.openat2_available = False
        super().setUp()

    def tearDown(self):
        super().tearDown()
        paths.openat2_available = self.saved


def contents(path):
    with open(path, 'rb') as f:
        return f.read()


SUBJECT = '/C=US/ST=California/O=PanelAlpha/OU=Org/CN=a.test'


def permissions(path):
    return stat.S_IMODE(os.stat(path).st_mode)


def public_key(kind, path):
    printed = ['-pubout'] if kind == 'pkey' else ['-noout', '-pubkey']
    return subprocess.run(['openssl', kind, '-in', path, *printed], check=True, capture_output=True).stdout


def entries(directory):
    found = []
    for walked, directories, files in os.walk(directory):
        for name in directories + files:
            full = os.path.join(walked, name)
            found.append((os.path.relpath(full, directory), os.path.isdir(full), None if os.path.isdir(full) else contents(full)))
    return sorted(found)


class SwappedAfterTheCheckTest(support.Tree):
    """A name swapped for a link between the broker's check and its command, as www-data or a tenant could race it."""

    def run_planned(self, name, *given, swap=lambda: None):
        action = registry()[name]
        plan = action.plan(action.check(list(given)), self.policy)
        try:
            swap()
            cwd = os.readlink(f'/proc/self/fd/{plan.cwd}') if plan.cwd is not None else None
            return plan, subprocess.run(plan.argv, cwd=cwd, pass_fds=plan.fds, capture_output=True)
        finally:
            for fd in [plan.cwd, *plan.fds]:
                if fd is not None:
                    os.close(fd)

    def relink(self, name, to):
        if os.path.lexists(name):
            os.unlink(name)
        os.symlink(to, name)

    def test_a_copy_reads_the_source_it_checked_though_its_name_now_links_elsewhere(self):
        secret = self.make('engine', 'secret', b'root only')
        for action, given in (('files.copy', ()), ('files.install', ('0600',))):
            with self.subTest(action=action):
                source = self.make('exchange', 'tmp_1', b'staged')
                target = self.make('homes', 'u/' + action, b'')
                _, result = self.run_planned(action, *given, source, target, swap=lambda: self.relink(source, secret))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(contents(target), b'staged')
                os.unlink(source)

    def test_a_source_missing_at_the_check_is_never_read_through_a_link_planted_there(self):
        secret = self.make('engine', 'secret', b'root only')
        source = self.path('exchange', 'tmp_1')
        target = self.path('homes', 'u', 'copy')
        os.makedirs(os.path.dirname(target))
        _, result = self.run_planned('files.copy', source, target, swap=lambda: self.relink(source, secret))
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(os.path.lexists(target))

    def test_a_copy_never_writes_through_a_link_planted_at_its_target(self):
        source = self.make('exchange', 'tmp_1', b'staged')
        os.makedirs(self.path('homes', 'u'))
        for action, given in (('files.copy', ()), ('files.install', ('0666',))):
            for existing in (False, True):
                with self.subTest(action=action, existing=existing):
                    victim = self.make('engine', f'victim-{action}-{existing}', b'keep')
                    os.chmod(victim, 0o640)
                    target = self.path('homes', 'u', f'{action}-{existing}')
                    if existing:
                        self.make('homes', f'u/{action}-{existing}', b'old')
                    _, result = self.run_planned(action, *given, source, target, swap=lambda: self.relink(target, victim))
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual((contents(victim), stat.S_IMODE(os.stat(victim).st_mode)), (b'keep', 0o640))
                    self.assertTrue(os.path.islink(target))

    def test_a_new_target_is_a_descriptor_with_the_mode_cp_gives_it(self):
        source = self.make('exchange', 'tmp_1', b'staged')
        os.chmod(source, 0o4750)
        os.makedirs(self.path('homes', 'u'))
        by_cp = self.path('homes', 'u', 'by-cp')
        subprocess.run(['cp', '--', source, by_cp], check=True)
        target = self.path('homes', 'u', 'copy')
        plan, result = self.run_planned('files.copy', source, target)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(plan.argv[-1], r'\A/proc/self/fd/[0-9]+\Z')
        self.assertEqual(stat.S_IMODE(os.stat(target).st_mode), stat.S_IMODE(os.stat(by_cp).st_mode))
        self.assertEqual(contents(target), b'staged')

    def test_install_leaves_the_copy_with_the_mode_it_was_given(self):
        source = self.make('exchange', 'pa-git-ssh-1', b'key')
        os.chmod(source, 0o600)
        target = self.path('homes', 'u', '.panelalpha-git-askpass-1')
        os.makedirs(os.path.dirname(target))
        plan, result = self.run_planned('files.install', '0700', source, target)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(plan.argv[-1], r'\A/proc/self/fd/[0-9]+\Z')
        self.assertEqual((contents(target), stat.S_IMODE(os.stat(target).st_mode)), (b'key', 0o700))

    def test_a_copy_into_a_directory_is_refused(self):
        source = self.make('exchange', 'tmp_1', b'staged')
        into = self.directory('homes', 'u/dir')
        with self.assertRaises(Refused):
            self.run_planned('files.copy', source, into)
        self.assertEqual(os.listdir(into), [])

    def test_a_move_never_lands_in_a_directory_linked_at_its_target_after_the_check(self):
        """mv -T renames a file over the planted link and refuses a directory there."""
        outside = self.directory('engine', 'outside')
        for action in ('files.move', 'files.moveOverwriting'):
            for source_kind in ('file', 'directory'):
                for existing in (False, True):
                    with self.subTest(action=action, source=source_kind, existing=existing):
                        name = f'{action}-{source_kind}-{existing}'
                        source = self.make('homes', f'u/{name}-src' + ('/inner' if source_kind == 'directory' else ''), b'moved')
                        source = os.path.dirname(source) if source_kind == 'directory' else source
                        target = self.path('homes', 'u', f'{name}-dst')
                        if existing:
                            self.make('homes', f'u/{name}-dst', b'old')
                        plan, result = self.run_planned(action, source, target, swap=lambda: self.relink(target, outside))
                        self.assertIn('-T', plan.argv)
                        self.assertEqual(os.listdir(outside), [])
                        if source_kind == 'file':
                            self.assertEqual(result.returncode, 0, result.stderr)
                            self.assertEqual((os.path.lexists(source), os.path.islink(target), contents(target)),
                                             (False, False, b'moved'))
                        else:
                            self.assertNotEqual(result.returncode, 0)
                            self.assertTrue(os.path.lexists(source) and os.path.islink(target))

    def self_sign(self, key, cert, swap=lambda: None):
        return self.run_planned('account.selfSignedCertificate', key, cert, SUBJECT, 'a.test', swap=swap)

    def test_a_new_key_and_certificate_are_descriptors_with_the_modes_openssl_gives_them(self):
        directory = self.directory('engine', 'users/u/ssl-certs')
        key, cert = os.path.join(directory, 'a.test.key'), os.path.join(directory, 'a.test.crt')
        plan, result = self.self_sign(key, cert)
        self.assertEqual(result.returncode, 0, result.stderr)
        for option in ('-keyout', '-out'):
            self.assertRegex(plan.argv[plan.argv.index(option) + 1], r'\A/proc/self/fd/[0-9]+\Z')
        self.assertEqual((permissions(key), permissions(cert)), (0o600, 0o644))
        self.assertEqual(public_key('pkey', key), public_key('x509', cert))

    def test_a_key_or_certificate_is_never_written_through_a_link_planted_at_its_name(self):
        directory = self.directory('engine', 'users/u/ssl-certs')
        for existing in (False, True):
            with self.subTest(existing=existing):
                key, cert = (os.path.join(directory, f'{existing}.{extension}') for extension in ('key', 'crt'))
                victims = [self.make('engine', f'victim-{existing}.{extension}', b'keep') for extension in ('key', 'crt')]
                if existing:
                    self.make('engine', f'users/u/ssl-certs/{existing}.key', b'old')
                    self.make('engine', f'users/u/ssl-certs/{existing}.crt', b'old')
                _, result = self.self_sign(key, cert, swap=lambda: [self.relink(*pair) for pair in zip((key, cert), victims)])
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual([contents(victim) for victim in victims], [b'keep', b'keep'])
                self.assertTrue(os.path.islink(key) and os.path.islink(cert))

    def test_a_reissue_writes_the_key_and_certificate_over_the_files_already_there(self):
        key = self.make('engine', 'users/u/ssl-certs/a.test.key', b'old key')
        cert = self.make('engine', 'users/u/ssl-certs/a.test.crt', b'old certificate')
        os.chmod(key, 0o600)
        inodes = (os.stat(key).st_ino, os.stat(cert).st_ino)
        _, result = self.self_sign(key, cert)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((os.stat(key).st_ino, os.stat(cert).st_ino), inodes)
        self.assertEqual((permissions(key), permissions(cert)), (0o600, 0o644))
        self.assertEqual(public_key('pkey', key), public_key('x509', cert))

    def test_an_archive_is_never_written_through_a_link_planted_at_its_name(self):
        home = self.directory('homes', 'u')
        self.make('homes', 'u/f', b'x')
        outside = self.path('engine', 'written-through-the-link')
        for existing in (False, True):
            with self.subTest(existing=existing):
                archive = self.path('backup', 'u', '1', f'home-{existing}.tar.gz')
                if existing:
                    self.make('backup', f'u/1/home-{existing}.tar.gz', b'old')
                else:
                    os.makedirs(os.path.dirname(archive), exist_ok=True)
                plan, result = self.run_planned('files.createArchive', home, archive, '.',
                                                swap=lambda: self.relink(archive, outside))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertRegex(plan.argv[plan.argv.index('-czf') + 1], r'\A/proc/self/fd/[0-9]+\Z')
                self.assertFalse(os.path.lexists(outside))
                self.assertTrue(os.path.islink(archive))

    def test_a_new_archive_has_the_mode_tar_gives_it(self):
        home = self.directory('homes', 'u')
        self.directory('backup', 'u/1')
        by_tar = self.path('backup', 'u', '1', 'by-tar.tar.gz')
        subprocess.run(['tar', '-C', home, '-czf', by_tar, '.'], check=True)
        archive = self.path('backup', 'u', '1', 'home.tar.gz')
        _, result = self.run_planned('files.createArchive', home, archive, '.')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(stat.S_IMODE(os.stat(archive).st_mode), stat.S_IMODE(os.stat(by_tar).st_mode))
        with tarfile.open(archive) as members:
            self.assertIn('.', members.getnames())

    def test_an_extract_never_reaches_an_archive_or_target_planted_after_the_check(self):
        source = self.directory('homes', 'u/src')
        self.make('homes', 'u/src/a.txt', b'a')
        real = self.path('backup', 'u', '1', 'real.tar.gz')
        os.makedirs(os.path.dirname(real))
        subprocess.run(['tar', '-C', source, '-czf', real, '.'], check=True)
        target = self.directory('homes', 'u/.restore-1')
        archive = self.path('backup', 'u', '1', 'files.tar.gz')
        _, result = self.run_planned('files.extractArchive', archive, target, '0', swap=lambda: self.relink(archive, real))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(os.listdir(target), [])
        outside = self.directory('engine', 'outside')
        missing = self.path('homes', 'u', '.restore-2')
        _, result = self.run_planned('files.extractArchive', real, missing, '0', swap=lambda: self.relink(missing, outside))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(os.listdir(outside), [])

    def test_a_file_missing_at_the_check_is_never_read_or_changed_through_a_link_planted_there(self):
        secret = self.make('engine', 'secret', b'root only')
        os.chmod(secret, 0o640)
        requests = [
            ('files.read', (), self.path('engine', 'users', 'u', 'gone')),
            ('files.lastLines', ('1',), self.path('engine-log', 'gone')),
            ('files.changeMode', ('600',), self.path('homes', 'u', 'gone')),
            ('files.changeModeRecursively', ('600',), self.path('homes', 'u', 'gone')),
            ('backup-store.openToWebUser', (), self.path('homes', 'u', 'gone')),
        ]
        os.makedirs(self.path('engine', 'users', 'u'))
        os.makedirs(self.path('homes', 'u'))
        for action, before, missing in requests:
            with self.subTest(action=action):
                _, result = self.run_planned(action, *before, missing, swap=lambda: self.relink(missing, secret))
                os.unlink(missing)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn(b'root only', result.stdout)
                self.assertEqual(stat.S_IMODE(os.stat(secret).st_mode), 0o640)

    def test_no_operand_its_tool_never_creates_names_a_link_planted_at_it_after_the_check(self):
        target = self.directory('homes', 'u/target')
        self.directory('engine', 'kept')
        self.directory('engine', 'users/u')
        self.directory('archive-stage', 'ab')
        archive = self.make('backup', 'u/1/real.tar.gz', b'')
        missing = self.path('homes', 'u', 'gone')
        in_engine = self.path('engine', 'users', 'u', 'entrypoint.d')
        zipped = self.path('archive-stage', 'ab', 'gone.zip')
        requests = [
            ('files.changeMode', ('600', missing), missing),
            ('files.changeModeRecursively', ('600', missing), missing),
            ('files.changeOwner', (f'{os.getuid()}:{os.getgid()}', missing), missing),
            ('files.read', (in_engine,), in_engine),
            ('files.lastLines', ('1', self.path('engine-log', 'gone')), self.path('engine-log', 'gone')),
            ('files.listNames', (missing,), missing),
            ('files.listAllNames', (missing,), missing),
            ('files.mirror', (missing, target), missing),
            ('files.mirrorByContent', (missing, target), missing),
            ('files.mirrorExcluding', (missing, target, 'x'), missing),
            ('files.createArchive', (missing, self.path('backup', 'u', '1', 'a.tar.gz'), '.'), missing),
            ('files.extractArchive', (self.path('backup', 'u', '1', 'gone.tar.gz'), target, '0'),
             self.path('backup', 'u', '1', 'gone.tar.gz')),
            ('files.extractArchive', (archive, missing, '0'), missing),
            ('files.moveEntriesInto', (target, missing), missing),
            ('backup-store.giveToWebUser', (missing,), missing),
            ('backup-store.openToWebUser', (missing,), missing),
            ('lines.removeScripts', (in_engine,), in_engine),
            ('lines.removeScriptsIfAny', (in_engine,), in_engine),
            ('lines.removeProxyRuleConfigsIfAny', (in_engine,), in_engine),
            ('lines.emptyDirectory', (in_engine,), in_engine),
            ('account.listZipNames', (zipped,), zipped),
            ('account.listZipEntries', (zipped,), zipped),
            ('account.readZipMember', (zipped, 'm'), zipped),
        ]
        for name, given, planted in requests:
            with self.subTest(action=name, planted=planted):
                action = registry()[name]
                plan = action.plan(action.check(list(given)), self.policy)
                try:
                    self.relink(planted, self.path('engine', 'kept'))
                    self.assertEqual([word for word in plan.argv if reaches_a_link(plan, word)], [])
                finally:
                    close(plan)
                    os.unlink(planted)


def reaches_a_link(plan, word):
    """Whether the tool, holding the plan's descriptors, would open a link for `word`."""
    if word.startswith('./') and plan.cwd is not None:
        word = f'/proc/self/fd/{plan.cwd}/{word[2:]}'
    descriptor, _, rest = word.removeprefix('/proc/self/fd/').partition('/')
    if not word.startswith('/proc/self/fd/') or not rest.rstrip('/'):
        return False
    return os.path.islink(f'/proc/self/fd/{descriptor}/{rest.rstrip("/")}')


def close(plan):
    for fd in [plan.cwd, *plan.fds]:
        if fd is not None:
            os.close(fd)


if __name__ == '__main__':
    unittest.main()
