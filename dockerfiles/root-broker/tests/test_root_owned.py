"""pa-root-owned, which the broker's entrypoint and services ask before they run an engine script on the host."""

import os
import shutil
import subprocess
import tempfile
import unittest

import support

ROOT_OWNED = os.path.join(os.path.dirname(support.HERE), 'pa-root-owned')
ENGINE = os.path.join(support.HERE, '..', '..', '..')
STARTED = (
    ('dockerfiles/entrypoint-root-broker.sh', '. /etc/s6/root-broker/.lib/host-script.sh', 'run_host_script '),
    ('dockerfiles/root-broker/rootbroker/tenantports.py', "HOST_SCRIPT = '/etc/s6/root-broker/.lib/host-script.sh'",
     'run_host_script "$@"'),
)
LIBRARY = 'config/root-broker/services/.lib/host-script.sh'


def owned(*paths):
    return subprocess.run(['sh', ROOT_OWNED, *paths], capture_output=True)


class RootOwnedTest(unittest.TestCase):
    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='root-owned-'))

    def tearDown(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def test_a_path_root_alone_can_change_passes(self):
        self.assertEqual(owned('/etc/passwd', '/etc').returncode, 0)

    def test_a_path_anyone_else_could_change_or_redirect_fails(self):
        mine = os.path.join(self.base, 'script.sh')
        open(mine, 'w').close()
        link = os.path.join(self.base, 'link')
        os.symlink('/etc/passwd', link)
        for paths in ([mine], [link], ['/tmp'], ['/etc/passwd', mine], ['etc/passwd'], [os.path.join(self.base, 'gone')]):
            with self.subTest(paths=paths):
                self.assertEqual(owned(*paths).returncode, 1)

    @unittest.skipUnless(os.path.isdir(os.path.join(ENGINE, 'config')), 'needs the engine checkout around the broker')
    def test_every_engine_script_the_broker_starts_on_the_host_is_checked_first(self):
        with open(os.path.join(ENGINE, LIBRARY)) as f:
            library = f.read()
        self.assertIn('ROOT_OWNED=/usr/local/lib/panelalpha/pa-root-owned\n', library)
        self.assertLess(library.index('"$ROOT_OWNED" $checked'), library.index('nsenter --target 1 --all "$@"'))
        self.assertEqual(library.count('nsenter'), 1)
        self.assertNotIn('sh -c', library)
        services = os.path.join(ENGINE, 'config', 'root-broker', 'services')
        started = [*(relative for relative, _, _ in STARTED),
                   *(os.path.join('config', 'root-broker', 'services', name, 'run') for name in os.listdir(services)
                     if os.path.isfile(os.path.join(services, name, 'run')))]
        for relative in started:
            with open(os.path.join(ENGINE, relative)) as f:
                lines = f.read()
            with self.subTest(file=relative):
                self.assertNotIn('nsenter', lines)
                self.assertNotIn('sh -c', lines)
        for relative, sourced, runs in STARTED:
            with open(os.path.join(ENGINE, relative)) as f:
                lines = f.read()
            with self.subTest(file=relative):
                self.assertIn(sourced, lines)
                self.assertIn(runs, lines)

if __name__ == '__main__':
    unittest.main()
