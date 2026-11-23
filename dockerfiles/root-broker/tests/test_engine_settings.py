"""compose.engineSetEnv and compose.engineEnvValues: the sites-db cache sizes in the engine's `.env`, nothing else."""

import os
import unittest

import support
from rootbroker.actions import Policy, registry
from rootbroker.refused import Refused

COMPOSE = 'COMPOSE_PROFILES=full\nCORE_MYSQL_PASSWORD=secret\n#SITES_DB_KEY_BUFFER_SIZE=8M\n'
CORE = 'APP_KEY=base64:secret\nAPP_URL=https://e.example.test:2011\n'


class Collected:
    channel = None

    def __init__(self):
        self.out = b''
        self.err = b''

    def stdout(self, data):
        self.out += data

    def stderr(self, data):
        self.err += data


class EngineSettingsTest(support.Tree):
    def setUp(self):
        super().setUp()
        self.policy = Policy(self.roots, accounts_db=self.accounts, become=self.become,
                             engine_owners={os.geteuid()}, owners={os.geteuid()})
        self.env = self.make('engine', '.env', COMPOSE.encode())
        os.chmod(self.env, 0o640)
        self.core = self.make('engine', '.env-core', CORE.encode())

    def run_action(self, name, given):
        action = registry()[name]
        plan = action.plan(action.check([self.roots['engine'], *given]), self.policy)
        output = Collected()
        return plan.native(output), output

    def read(self, name):
        with open(self.path('engine', name)) as f:
            return f.read()

    def test_a_size_replaces_its_placeholder_and_keeps_every_other_line(self):
        code, _ = self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', '16M'])

        self.assertEqual(0, code)
        self.assertEqual(COMPOSE.replace('#SITES_DB_KEY_BUFFER_SIZE=8M', 'SITES_DB_KEY_BUFFER_SIZE=16M'), self.read('.env'))

    def test_a_live_line_is_replaced_and_a_missing_one_appended(self):
        self.make('engine', '.env', b'SITES_DB_KEY_BUFFER_SIZE=8M\nCOMPOSE_PROFILES=full\n')

        self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', '16M', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE', '32M'])

        self.assertEqual(
            'SITES_DB_KEY_BUFFER_SIZE=16M\nCOMPOSE_PROFILES=full\n\nSITES_DB_ARIA_PAGECACHE_BUFFER_SIZE=32M\n',
            self.read('.env'),
        )

    def test_an_empty_size_is_written_for_the_compose_default(self):
        self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', ''])

        self.assertIn('\nSITES_DB_KEY_BUFFER_SIZE=\n', self.read('.env'))

    def test_the_file_keeps_its_inode_and_the_old_contents_go_beside_it_with_its_mode(self):
        inode = os.stat(self.env).st_ino

        self.run_action('compose.engineSetEnv', ['SITES_DB_INNODB_BUFFER_POOL_SIZE', '512M'])

        self.assertEqual(inode, os.stat(self.env).st_ino)
        self.assertEqual(COMPOSE, self.read('.env.pae-backup'))
        self.assertEqual(0o640, os.stat(self.env + '.pae-backup').st_mode & 0o777)

    def test_an_unchanged_value_writes_nothing(self):
        self.make('engine', '.env', b'SITES_DB_KEY_BUFFER_SIZE=16M\n')

        self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', '16M'])

        self.assertFalse(os.path.exists(self.env + '.pae-backup'))

    def test_a_key_set_twice_is_changed_on_the_last_line_compose_reads(self):
        self.make('engine', '.env', b'SITES_DB_KEY_BUFFER_SIZE=8M\nCOMPOSE_PROFILES=full\nSITES_DB_KEY_BUFFER_SIZE=16M\n')

        self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', '8M'])

        self.assertEqual('SITES_DB_KEY_BUFFER_SIZE=8M\nCOMPOSE_PROFILES=full\nSITES_DB_KEY_BUFFER_SIZE=8M\n', self.read('.env'))

    def test_values_of_a_key_set_twice_is_the_last_line(self):
        self.make('engine', '.env', b'SITES_DB_KEY_BUFFER_SIZE=8M\nSITES_DB_KEY_BUFFER_SIZE=16M\n')

        _, output = self.run_action('compose.engineEnvValues', ['SITES_DB_KEY_BUFFER_SIZE'])

        self.assertEqual(b'SITES_DB_KEY_BUFFER_SIZE=16M\n', output.out)

    def test_every_key_but_the_three_sizes_is_refused(self):
        for key in ('APP_URL', 'APP_KEY', 'DIND_RUNTIME', 'COMPOSE_PROFILES', 'CORE_MYSQL_PASSWORD', 'DB_PASSWORD',
                    'QUEUE_WORKERS', 'TELEMETRY_TIER', 'MCP_PERMISSION_MODE'):
            with self.subTest(key=key), self.assertRaises(Refused):
                self.run_action('compose.engineSetEnv', [key, 'privileged'])

        self.assertEqual(COMPOSE, self.read('.env'))

    def test_env_core_is_never_written(self):
        for given in (['.env-core', 'APP_URL', 'https://evil.example'], ['APP_URL', 'https://evil.example']):
            with self.subTest(given=given), self.assertRaises(Refused):
                self.run_action('compose.engineSetEnv', given)

        self.assertEqual(CORE, self.read('.env-core'))
        self.assertFalse(os.path.exists(self.core + '.pae-backup'))

    def test_a_value_that_could_start_a_line_or_need_quoting_is_refused(self):
        for value in ('x\nCOMPOSE_PROFILES=evil', 'a b', 'a#b', '"x"', "$(id)", 'x' * 16385):
            with self.subTest(value=value[:20]), self.assertRaises(Refused):
                self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', value])

        self.assertEqual(COMPOSE, self.read('.env'))

    def test_a_key_without_a_value_is_refused(self):
        with self.assertRaises(Refused):
            self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE'])

    def test_a_directory_other_than_the_engines_is_refused(self):
        action = registry()['compose.engineSetEnv']
        with self.assertRaises(Refused):
            action.plan(action.check([self.roots['homes'], 'SITES_DB_KEY_BUFFER_SIZE', '16M']), self.policy).native(Collected())

    def test_a_symlinked_env_is_not_written_through(self):
        os.unlink(self.env)
        target = self.make('homes', 'target', b'SITES_DB_KEY_BUFFER_SIZE=8M\n')
        os.symlink(target, self.env)

        code, output = self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', '16M'])

        self.assertEqual(2, code)
        self.assertIn(b'symlink', output.err)
        with open(target) as f:
            self.assertEqual('SITES_DB_KEY_BUFFER_SIZE=8M\n', f.read())

    def test_an_env_others_may_write_is_refused(self):
        os.chmod(self.env, 0o666)

        code, _ = self.run_action('compose.engineSetEnv', ['SITES_DB_KEY_BUFFER_SIZE', '16M'])

        self.assertEqual(2, code)
        self.assertEqual(COMPOSE, self.read('.env'))

    def test_values_prints_only_the_named_sizes_a_live_line_sets(self):
        code, output = self.run_action('compose.engineEnvValues', ['SITES_DB_KEY_BUFFER_SIZE', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE'])

        self.assertEqual(0, code)
        self.assertEqual(b'', output.out, 'a commented placeholder sets nothing')

        self.make('engine', '.env', b'SITES_DB_KEY_BUFFER_SIZE=16M\nCORE_MYSQL_PASSWORD=secret\n')
        code, output = self.run_action('compose.engineEnvValues', ['SITES_DB_KEY_BUFFER_SIZE'])

        self.assertEqual(b'SITES_DB_KEY_BUFFER_SIZE=16M\n', output.out)

    def test_values_refuses_every_other_key(self):
        for key in ('CORE_MYSQL_PASSWORD', 'APP_URL', 'APP_KEY'):
            with self.subTest(key=key), self.assertRaises(Refused):
                self.run_action('compose.engineEnvValues', [key])


if __name__ == '__main__':
    unittest.main()
