import os
import subprocess
import tarfile
import time
import unittest

import support

OLD = time.time() - 3 * 3600


class OutputTest(support.Serving):
    def setUp(self):
        super().setUp()
        self.home = self.directory('homes', 'my u')
        for relative in ('project/a.txt', 'project/sub/b.txt', 'project/.output/server/index.mjs',
                         'project/node_modules/x/.output/server/index.mjs', '.panelalpha-git-askpass-1',
                         'sub/.panelalpha-git-ssh-key-2', 'docker/containers/c1/c1-json.log', 'docker/containers/c2/c2-json.log.1'):
            self.make('homes', 'my u/' + relative, b'12345')
        self.directory('homes', 'my u/.project-next-ab12/inner')
        os.symlink('project', os.path.join(self.home, 'link'))
        self.directory('homes', 'other/.project-prev')
        for root, dirs, files in os.walk(self.home):
            for name in dirs + files:
                os.utime(os.path.join(root, name), (OLD, OLD), follow_symlinks=False)

    def same(self, action, values, sudo_argv):
        expected = subprocess.run(sudo_argv, capture_output=True)
        result = self.pa_root(action, *values)
        self.assertEqual((result.returncode, sorted_records(result.stdout)), (expected.returncode, sorted_records(expected.stdout)),
                         result.stderr)

    def test_listings_match_what_sudo_printed(self):
        h = self.home
        cases = [
            ('files.firstEntry', [h + '/project/sub'], ['find', h + '/project/sub', '-mindepth', '1', '-maxdepth', '1', '-print', '-quit']),
            ('files.symlinksIn', [h], ['find', h, '-mindepth', '1', '-maxdepth', '1', '-type', 'l']),
            ('files.symlinksIn', [h + '/'], ['find', h + '/', '-mindepth', '1', '-maxdepth', '1', '-type', 'l']),
            ('files.staleDirectoriesStartingWith', [h, '.project-next', '60'],
             ['find', h, '-mindepth', '1', '-maxdepth', '1', '-type', 'd', '-name', '.project-next*', '-mmin', '+60', '-print']),
            ('files.staleFilesStartingWith', [h + '/', '', '60', '.panelalpha-git-askpass-', '.panelalpha-git-ssh-'],
             ['find', h, '-ignore_readdir_race', '-xdev', '-type', 'f', '(', '-name', '.panelalpha-git-askpass-*', '-o',
              '-name', '.panelalpha-git-ssh-*', ')', '-mmin', '+60', '-print0']),
            ('files.staleFilesStartingWith', [self.roots['homes'], '2', '60', '.panelalpha-git-'],
             ['find', self.roots['homes'], '-ignore_readdir_race', '-maxdepth', '2', '-type', 'f', '(', '-name', '.panelalpha-git-*', ')',
              '-mmin', '+60', '-print0']),
            ('files.parentsOfDirectoriesTwoLevelsDownNamed', [self.roots['homes'], '.project-prev'],
             ['find', self.roots['homes'], '-mindepth', '2', '-maxdepth', '2', '-type', 'd', '-name', '.project-prev', '-printf', '%h\\n']),
            ('files.nitroServerEntries', [h + '/project/', '5'],
             ['find', h + '/project', '-maxdepth', '5', '(', '-name', 'node_modules', '-o', '-name', '.git', ')', '-prune', '-o',
              '-type', 'f', '(', '-path', '*/.output/server/index.mjs', '-o', '-path', '*/.output/server/index.js', ')', '-print']),
            ('files.relativeFilePaths', [h + '/project'], ['find', h + '/project', '-type', 'f', '-printf', '%P\\n']),
            ('files.listEntryTypes', [h], ['find', h, '-mindepth', '1', '-maxdepth', '1', '-printf', '%y %f\\0']),
            ('files.containerLogSizes', [h + '/docker/containers'],
             ['find', h + '/docker/containers', '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '-name', '*-json.log*', '-printf', '%s\\n']),
            ('files.diskUsageInBytes', [h + '/project'], ['du', '-sb', '--', h + '/project']),
            ('files.diskUsageInMegabytes', [h], ['du', '-shm', '--', h]),
            ('files.listAllNames', [h], ['ls', '-1A', '--', h]),
            ('files.listNames', [h + '/project'], ['ls', '-1', '--', h + '/project']),
            ('files.isSymlink', [h + '/link'], ['test', '-L', h + '/link']),
            ('files.isDirectory', [h + '/link'], ['test', '-d', h + '/link']),
            ('files.permissionBits', [h + '/project/a.txt'], ['stat', '-c', '%a', '--', h + '/project/a.txt']),
            ('files.deviceNumbers', [h, h + '/project'], ['stat', '-c', '%d', '--', h, h + '/project']),
        ]
        for action, values, sudo_argv in cases:
            with self.subTest(action=action, values=values):
                self.same(action, values, sudo_argv)

    def test_find_in_a_directory_ignoring_case_prints_the_engines_paths(self):
        certs = self.directory('engine', 'users/u/ssl-certs')
        self.make('engine', 'users/u/ssl-certs/Example.COM.crt')
        self.same('files.findInDirectoryIgnoringCase', [certs, 'example.com.crt'],
                  ['find', certs, '-maxdepth', '1', '-iname', 'example.com.crt'])

    def test_entries_move_into_a_directory_as_find_moved_them(self):
        staging = os.path.join(self.home, '.project-next-ab12')
        target = os.path.join(self.home, 'project')
        result = self.pa_root('files.moveEntriesInto', staging, target)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(os.path.isdir(os.path.join(target, 'inner')))
        self.assertEqual(os.listdir(staging), [])

    def test_find_removals_remove_the_same_entries(self):
        self.assertEqual(self.pa_root('files.removeEntriesNamed', self.home, '.project-next*').returncode, 0)
        self.assertFalse(os.path.exists(os.path.join(self.home, '.project-next-ab12')))
        self.assertEqual(self.pa_root('files.emptyDirectory', os.path.join(self.home, 'project')).returncode, 0)
        self.assertEqual(os.listdir(os.path.join(self.home, 'project')), [])
        self.assertTrue(os.path.islink(os.path.join(self.home, 'link')))

    def test_a_file_written_and_read_back_through_the_engines_roots(self):
        staged = self.make('exchange', 'tmp_abc', b'contents\n')
        target = self.path('engine', 'users', 'u', 'pool.conf')
        self.directory('engine', 'users/u')
        self.assertEqual(self.pa_root('files.copy', staged, target).returncode, 0)
        self.assertEqual(self.pa_root('files.read', target).stdout, b'contents\n')
        self.assertEqual(self.pa_root('files.changeMode', '600', target).returncode, 0)
        self.assertEqual(os.stat(target).st_mode & 0o777, 0o600)
        into = self.directory('engine', 'users/u/dir')
        result = self.pa_root('files.copy', staged, into)
        self.assertEqual(result.returncode, 77)
        self.assertIn(b'is a directory', result.stderr)
        self.assertEqual(os.listdir(into), [])

    def test_install_writes_the_copy_with_the_mode_it_was_given(self):
        source = self.make('exchange', 'pa-git-ssh-1', b'key')
        os.chmod(source, 0o600)
        target = os.path.join(self.home, '.panelalpha-git-askpass-9')
        result = self.pa_root('files.install', '0700', source, target)
        self.assertEqual(result.returncode, 0, result.stderr)
        with open(target, 'rb') as f:
            self.assertEqual((f.read(), os.stat(target).st_mode & 0o777), (b'key', 0o700))

    def test_mirrors_and_moves_land_where_sudo_put_them(self):
        source = self.directory('homes', 'my u/docker/volumes/v/_data')
        self.make('homes', 'my u/docker/volumes/v/_data/db', b'x')
        incoming = self.directory('homes', 'my u/docker/volumes/v/_data.incoming')
        self.assertEqual(self.pa_root('files.mirror', source, incoming).returncode, 0)
        self.assertTrue(os.path.isfile(os.path.join(incoming, 'db')))
        aside = self.path('homes', 'my u', 'docker', 'volumes', 'v', '_data.pre-push')
        self.assertEqual(self.pa_root('files.move', source, aside).returncode, 0)
        self.assertEqual(self.pa_root('files.move', incoming, source).returncode, 0)
        self.assertTrue(os.path.isfile(os.path.join(source, 'db')))
        into = self.directory('homes', 'my u/aside')
        self.assertEqual(self.pa_root('files.move', aside, into + '/').returncode, 0)
        self.assertTrue(os.path.isdir(os.path.join(into, '_data.pre-push')))

    def test_an_archive_made_and_unpacked_through_descriptors(self):
        archive = self.path('backup', 'u', '1', 'files.tar.gz')
        self.directory('backup', 'u/1')
        result = self.pa_root('files.createArchive', self.home, archive, 'project')
        self.assertEqual(result.returncode, 0, result.stderr)
        restore = self.directory('homes', 'my u/.restore-1')
        self.assertEqual(self.pa_root('files.extractArchive', archive, restore, '1').returncode, 0)
        self.assertTrue(os.path.isfile(os.path.join(restore, 'sub', 'b.txt')))

    def test_an_archive_holds_nothing_from_outside_the_directory_it_archives(self):
        secret = self.make('engine', 'secret', b'root only')
        os.symlink(os.path.dirname(secret), os.path.join(self.home, 'out'))
        archive = self.path('backup', 'u', '1', 'home.tar.gz')
        self.directory('backup', 'u/1')
        for entry in (secret, '/proc/1/root' + secret, '../../engine/secret', 'out/secret', 'out/'):
            with self.subTest(entry=entry):
                result = self.pa_root('files.createArchive', self.home, archive, entry)
                self.assertEqual(result.returncode, 77, result.stderr)
                self.assertFalse(os.path.exists(archive))
        result = self.pa_root('files.createArchive', self.home, archive, '.', './project', './.project-next-ab12')
        self.assertEqual(result.returncode, 0, result.stderr)
        with tarfile.open(archive) as members:
            names = {member.name: member for member in members.getmembers()}
        self.assertTrue(names['./out'].issym())
        self.assertNotIn('./out/secret', names)
        self.assertIn('./sub/.panelalpha-git-ssh-key-2', names)
        self.assertFalse([name for name in names if name.startswith(('./project', './.project-next'))])
        result = self.pa_root('files.createArchive', self.home, archive, 'out')
        self.assertEqual(result.returncode, 0, result.stderr)
        with tarfile.open(archive) as members:
            self.assertEqual([(member.name, member.issym()) for member in members.getmembers()], [('out', True)])


def sorted_records(output):
    separator = b'\0' if b'\0' in output else b'\n'
    return sorted(output.split(separator))


if __name__ == '__main__':
    unittest.main()
