"""Below the engine's directory a write reaches only the parts the engine writes; reads reach all of it."""

import os
import unittest

import support
from rootbroker.actions import ENGINE_WRITES, Policy, registry
from rootbroker.refused import Refused

PROTECTED = (
    '', 'scripts', 'scripts/firewall.sh', 'scripts/tenant-network-firewall.sh', 'scripts/letsencrypt-request-cert.sh',
    'webserver.sh', 'updater.sh', 'installer.sh', 'docker-compose.yml', 'docker-compose.override.yml', '.env',
    'dockerfiles/entrypoint-root-broker.sh', 'config', 'config/core/services/scheduler/run',
    'config/root-broker/services/tenant-network/run', 'config/root-broker/services/.lib/host-script.sh',
    'core/app/System.php', 'templates/user/default/project/x',
    'paemd-pages/x', 'data', 'data/logrotate/status', 'config/logrotate/core-nginx.conf', 'config/tenant-api-access.sh',
    'users-x/a', 'crt-x', 'docker-compose.yml-webserver', 'docker-compose.yml-nginx-proxy', 'docker-compose.prod.yml',
    'compose.yaml', 'compose.override.yml', '.env-core',
)
WRITTEN = (
    'users/u/apache-sites/my site.conf', 'users/u', 'webserver-config/nginx/vhosts/a.conf', 'webserver-logs/nginx/a',
    'data/webserver/acme-challenges/a.test/token', 'data/lscache', 'data/build-cache/0a1b', 'config/exim/rewrite.conf',
    'config/modsecurity/main.conf', 'config/sftp/logins.conf', 'config/tenant-api-access',
    'config/tenant-api-access.tmp-0123456789abcdef', 'crt/acme-account.key',
)
ON_THE_WAY = ('data', 'data/webserver', 'config')


class EngineWritesTest(support.Tree):
    def setUp(self):
        super().setUp()
        self.source = self.make('exchange', 'tmp_1', b'x')
        self.elsewhere = self.directory('engine', 'users/u/elsewhere')

    def engine(self, relative):
        return self.path('engine', relative) if relative else self.roots['engine']

    def writes(self, target):
        other = self.elsewhere
        return [
            ('files.remove', [target]), ('files.removeIfExists', [target]), ('files.removeTree', [target]),
            ('files.move', [target, other]), ('files.move', [other, target]),
            ('files.rename', [target, other]), ('files.rename', [other, target]),
            ('files.copy', [self.source, target + '/copied' if os.path.isdir(target) else target]),
            ('files.changeMode', ['0777', target]), ('files.changeModeRecursively', ['0777', target]),
            ('files.changeOwner', ['www-data:www-data', target]), ('files.changeOwnerRecursively', ['33:33', target]),
            ('files.touch', [target]), ('files.mirror', [other, target]),
            ('files.deleteFilesTwoLevelsDownOlderThan', [target, '5']),
            ('scripts.removeStartingWith', [target]), ('lines.removeScripts', [target]),
            ('lines.removeScriptsIfAny', [target]), ('lines.removeProxyRuleConfigsIfAny', [target]),
            ('lines.emptyDirectory', [target]),
            ('account.selfSignedCertificate', [target, other + '/c.crt', '/CN=a.test', 'a.test']),
            ('account.selfSignedCertificate', [other + '/c.key', target, '/CN=a.test', 'a.test']),
            ('backup-store.removeTree', [target]), ('backup-store.removeEmptyDirectories', [target]),
            ('backup-store.removeEmptyParents', [target]), ('backup-store.makeDirectories', [target]),
            ('backup-store.giveToWebUser', [target]), ('backup-store.openToWebUser', [target]),
        ]

    def makes(self, target):
        return [('files.makeDirectory', [target]), ('files.makeDirectories', [target])]

    def plan(self, name, given):
        action = registry()[name]
        plan = action.plan(action.check(given), self.policy, False)
        for fd in [plan.cwd, *plan.fds]:
            if fd is not None:
                os.close(fd)

    def test_no_write_reaches_the_engines_scripts_compose_files_or_sources(self):
        for relative in PROTECTED:
            for name, given in self.writes(self.engine(relative)) + self.makes(self.engine(relative) + '/x'):
                with self.subTest(path=relative, action=name), self.assertRaises(Refused):
                    self.plan(name, given)

    def test_a_dot_segment_does_not_slip_past_the_engine_write_rule(self):
        parent = self.directory('engine', 'opt')
        engine = os.path.join(parent, 'shared-hosting')
        os.makedirs(os.path.join(engine, 'scripts'))
        policy = Policy({**self.roots, 'panelalpha': parent, 'engine': engine}, accounts_db=self.accounts)
        with self.assertRaises(Refused):
            policy.check_writable(parent + '/./shared-hosting/scripts')
        policy.check_writable(engine + '/./users/u/x')

    def test_a_directory_is_made_only_on_the_way_to_a_part_the_engine_writes(self):
        for relative in ('', 'scripts', 'dockerfiles', 'config/core', 'config/root-broker', 'core', 'templates', 'paemd-pages'):
            for name, given in self.makes(self.engine(relative)):
                with self.subTest(path=relative, action=name), self.assertRaises(Refused):
                    self.plan(name, given)
        for relative in ON_THE_WAY:
            for name, given in self.makes(self.engine(relative)):
                with self.subTest(path=relative, action=name):
                    self.plan(name, given)

    def test_every_part_the_engine_writes_takes_every_write(self):
        for relative in WRITTEN:
            for name, given in self.writes(self.engine(relative)) + self.makes(self.engine(relative)):
                with self.subTest(path=relative, action=name):
                    self.plan(name, given)

    def test_a_root_of_its_own_inside_the_engine_directory_is_not_the_engines(self):
        policy = Policy({**self.roots, 'homes': self.engine('homes')}, accounts_db=self.accounts)
        policy.check_writable(self.engine('homes/u/project/x'))
        with self.assertRaises(Refused):
            policy.check_writable(self.engine('homes-x'))

    def test_no_link_is_moved_or_mirrored_below_the_engine_directory(self):
        target = self.directory('engine', 'users/u/conf')
        linked = self.path('homes', 'u', 'linked')
        os.makedirs(os.path.dirname(linked), exist_ok=True)
        os.symlink('/etc', linked)
        holding = self.directory('homes', 'u/holding/deeper')
        os.symlink('/etc/passwd', os.path.join(holding, 'passwd'))
        holding_directory_link = self.directory('homes', 'u/dirlink')
        os.symlink('/etc', os.path.join(holding_directory_link, 'etc'))
        for source in (linked, os.path.dirname(holding), holding_directory_link):
            for name, given in (('files.move', [source, target]), ('files.rename', [source, target + '/x']),
                                ('files.mirror', [source, target])):
                with self.subTest(source=source, action=name), self.assertRaises(Refused):
                    self.plan(name, given)
        plain = self.directory('homes', 'u/plain/sub')
        self.make('homes', 'u/plain/sub/a.conf')
        for name, given in (('files.move', [os.path.dirname(plain), target]), ('files.mirror', [os.path.dirname(plain), target])):
            with self.subTest(action=name):
                self.plan(name, given)
        self.plan('files.rename', [linked, self.path('homes', 'u', 'linked-again')])

    def test_reads_reach_the_whole_engine_directory(self):
        script = self.make('engine', 'scripts/firewall.sh', b'#!/bin/sh\n')
        compose = self.make('engine', 'docker-compose.yml', b'services: {}\n')
        for name, given in (('files.read', [compose]), ('files.isFile', [script]), ('files.isSymlink', [script]),
                            ('files.copy', [script, self.elsewhere + '/copy']), ('files.listAllNames', [self.roots['engine']]),
                            ('files.relativeFilePaths', [self.engine('templates')])):
            with self.subTest(action=name):
                self.plan(name, given)

    def test_every_action_that_writes_below_the_engine_is_checked_here(self):
        checked = {name for name, _ in self.writes('/x') + self.makes('/x')}
        for name, action in registry().items():
            params = getattr(action, 'params', ())
            if any((param.writes or param.creates) and {'engine', 'panelalpha'} & set(param.roots) for param in params):
                with self.subTest(action=name):
                    self.assertIn(name, checked)

    def test_the_parts_the_engine_writes_hold_none_of_what_runs_as_root(self):
        for area in ENGINE_WRITES:
            with self.subTest(area=area):
                self.assertFalse({'scripts', 'dockerfiles', 'core', 'templates'} & {area.split('/')[0]})
                self.assertNotIn(area.split('/')[:2], (['config', 'core'], ['config', 'root-broker']))


if __name__ == '__main__':
    unittest.main()
