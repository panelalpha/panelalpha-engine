import os
import re
import shutil
import subprocess
import sys
import unittest

import scriptwork
import support
from rootbroker.actions import registry
from rootbroker.refused import Refused

PRIVILEGED = os.path.join(support.HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged')
GATEWAYS = {
    'scripts': ('RootScripts.php',),
    'lines': ('RootDockerLines.php', 'RootComposeLines.php', 'RootFileLines.php'),
}
PIPELINES = {'buildImageOnHost', 'buildImageOnHostInBackground', 'rebuildImageOnHost', 'loadImageFromHost'}
NOT_ACTIONS = PIPELINES
RENAMED = {('RootComposeLines.php', name): 'compose' + name.capitalize() for name in ('pull', 'build', 'exec')}
ENGINE = '/opt/panelalpha/shared-hosting'
ACCOUNT_FILE = ENGINE + '/users/alice/docker-compose.yml'
ENGINE_FILE = ENGINE + '/docker-compose.yml'
LAYOUT = ENGINE + '/data/build-cache/0a1b2c3d4e5f/out-1a2b3c4d'


class CasesTest(unittest.TestCase):
    def test_each_case_runs_the_sudo_command_word_for_word(self):
        for case in scriptwork.load():
            with self.subTest(case=case['name']):
                scratch = scriptwork.Scratch()
                try:
                    plan = scriptwork.plan_case(scratch, case)
                    if 'sudo' not in case:
                        self.assertIsNone(plan.argv, 'an action the broker answers itself runs no command')
                        self.assertIsNotNone(plan.native)
                        scriptwork.close(plan)
                        continue
                    self.assertEqual(scriptwork.effective(scratch, plan), scriptwork.expected(case))
                    scriptwork.close(plan)
                finally:
                    scratch.close()

    def test_no_path_reaches_a_command_as_the_caller_named_it_but_the_ones_named_on_purpose(self):
        by_name = ('composeImageLines', 'publishBuildCache')
        for case in scriptwork.load():
            if case['name'].startswith(by_name) or 'sudo' not in case:
                continue
            with self.subTest(case=case['name']):
                scratch = scriptwork.Scratch()
                try:
                    plan = scriptwork.plan_case(scratch, case)
                    self.assertFalse([word for word in plan.argv if word.startswith(scratch.base)], plan.argv)
                    scriptwork.close(plan)
                finally:
                    scratch.close()

    @unittest.skipUnless(os.path.isdir(PRIVILEGED), 'needs the engine checkout around the broker')
    def test_every_gateway_method_has_an_action_and_a_case(self):
        asked = {scriptwork.request(case)[2] for case in scriptwork.load()}
        for area, files in GATEWAYS.items():
            methods = set()
            for name in files:
                with open(os.path.join(PRIVILEGED, name)) as f:
                    found = set(re.findall(r'public (?:static )?function (\w+)\(', f.read())) - {'__construct'} - NOT_ACTIONS
                methods |= {RENAMED.get((name, method), method) for method in found}
            actions = {name.removeprefix(area + '.') for name in registry() if name.startswith(area + '.')}
            with self.subTest(area=area):
                self.assertEqual(methods, actions)
                self.assertEqual(actions, {name.removeprefix(area + '.') for name in asked if name.startswith(area + '.')})

    def test_an_image_pipeline_is_no_action(self):
        self.assertFalse({f'scripts.{name}' for name in PIPELINES} & set(registry()))


class Scratch(unittest.TestCase):
    def setUp(self):
        self.scratch = scriptwork.Scratch()
        self.inside = self.scratch.inside

    def tearDown(self):
        self.scratch.close()

    def plan(self, action, *given, host=False):
        found = registry()[action]
        plan = found.plan(found.check([self.inside(value) for value in given]), self.scratch.policy, host)
        self.addCleanup(scriptwork.close, plan)
        return plan

    def refused(self, action, *given, host=False, why=None):
        with self.assertRaises(Refused) as caught:
            self.plan(action, *given, host=host)
        if why:
            self.assertIn(why, str(caught.exception))

    def run_plan(self, plan):
        cwd = plan.cwd
        return subprocess.run(plan.argv, pass_fds=plan.fds, capture_output=True,
                              preexec_fn=(lambda: os.fchdir(cwd)) if cwd is not None else None)


class ScriptRefusalsTest(Scratch):
    def test_a_home_entry_is_made_only_directly_in_the_home_of_the_account_it_is_for(self):
        self.plan('scripts.makeHomeEntry', '/home/alice/public_html', '0755', '1004:1004')
        os.makedirs(self.inside('/home/bob'))
        self.refused('scripts.makeHomeEntry', '/home/bob/public_html', '0755', '1004:1004', why='directly in the home')
        self.refused('scripts.makeHomeEntry', '/home/alice/a/b', '0755', '1004:1004', why='directly in the home')
        self.refused('scripts.makeHomeEntry', '/home/alice', '0755', '1004:1004', why='directly in the home')
        self.refused('scripts.makeHomeEntry', '/home/alice/x', '0755', '0:0', why='root')
        self.refused('scripts.makeHomeEntry', '/home/alice/x', '0755', '1009:1009', why='no account')
        self.refused('scripts.makeHomeEntry', '/home/alice/x', '0755 /etc', '1004:1004')
        self.refused('scripts.makeHomeEntry', '/home/alice/x', 'u+s', '1004:1004')
        self.refused('scripts.makeHomeEntry', '/home/alice/docker', '0755', '1004:1004', why='Docker storage')
        self.refused('scripts.makeHomeEntry', '/home/alice/docker/', '0700', '1004:1004', why='Docker storage')

    def test_a_home_entry_is_never_made_through_a_linked_home(self):
        elsewhere = os.path.join(self.scratch.base, 'elsewhere')
        os.makedirs(elsewhere)
        os.rmdir(self.scratch.home)
        os.symlink(elsewhere, self.scratch.home)
        self.refused('scripts.makeHomeEntry', '/home/alice/public_html', '0755', '1004:1004', why='symlink')

    def test_only_an_engine_file_is_removed_by_its_prefix(self):
        self.refused('scripts.removeStartingWith', '/home/alice/a.conf', why='not below')
        self.refused('scripts.removeStartingWith', '/etc/nginx/a.conf', why='not below')

    def test_the_compose_image_lines_read_the_homes_and_nothing_else(self):
        self.refused('scripts.composeImageLines', '/home/alice', 'bob', 'compose.yaml')
        self.refused('scripts.composeImageLines', '/home', '../root', 'compose.yaml')
        self.refused('scripts.composeImageLines', '/home', 'bob', '../x.yml')
        self.refused('scripts.composeImageLines', '/home', 'bob', 'x; id')

    def test_a_build_cache_is_published_from_the_engines_layout_to_the_writer_only(self):
        self.plan('scripts.publishBuildCache', LAYOUT, '127.0.0.1:5000/buildcache/a/b:main')
        self.refused('scripts.publishBuildCache', LAYOUT, 'ghcr.io/acme/app:main', why='cache registry')
        self.refused('scripts.publishBuildCache', LAYOUT, "127.0.0.1:5000/a'b")
        self.refused('scripts.publishBuildCache', '/home/alice/out-1a2b3c4d', '127.0.0.1:5000/a:b', why='build cache layout')
        self.refused('scripts.publishBuildCache', ENGINE + '/data/build-cache/x/out-1', '127.0.0.1:5000/a:b')
        self.refused('scripts.publishBuildCache', LAYOUT + ':/etc', '127.0.0.1:5000/a:b')

    def test_a_linked_build_cache_layout_is_refused(self):
        layout = self.inside(LAYOUT)
        os.rmdir(layout)
        os.symlink(self.inside('/home/alice'), layout)
        self.refused('scripts.publishBuildCache', LAYOUT, '127.0.0.1:5000/a:b', why='symlink')


class LineRefusalsTest(Scratch):
    def test_a_push_goes_to_the_cache_registry_writer_only(self):
        self.plan('lines.pushQuietly', '127.0.0.1:5000/node:22')
        self.refused('lines.pushQuietly', 'ghcr.io/acme/app:1', why='image to push')
        self.refused('lines.pushQuietly', '127.0.0.1:5001/node:22')

    def test_a_compose_pull_or_build_is_an_accounts_own(self):
        for action in ('lines.composePull', 'lines.composeBuild'):
            with self.subTest(action=action):
                self.plan(action, ACCOUNT_FILE)
                self.refused(action, ENGINE_FILE, why="engine's compose file")
                self.refused(action, '/home/alice/project/docker-compose.yml')

    def test_a_glob_runs_only_in_an_engine_directory_that_is_no_link(self):
        link = self.inside(ENGINE + '/users/alice/entrypoint.d')
        os.rmdir(link)
        os.symlink(self.inside('/home/alice'), link)
        self.refused('lines.removeScriptsIfAny', ENGINE + '/users/alice/entrypoint.d', why='symlink')
        self.refused('lines.emptyDirectory', '/home/alice', why='not below')
        self.refused('lines.emptyDirectory', ENGINE + '/users/alice/../../etc')

    def test_a_build_context_is_an_engine_directory_that_is_no_link(self):
        context = self.inside(ENGINE + '/templates/user/dind/project')
        os.rmdir(context)
        os.symlink(self.inside('/home/alice'), context)
        self.refused('lines.buildQuietly', 'a:1', ENGINE + '/templates/user/dind/project', why='symlink')
        self.refused('lines.buildQuietly', 'a:1', '/home/alice/project', why='not below')

    def test_image_values_the_line_could_misread_are_refused(self):
        self.refused('lines.inspectImage', '--size')
        self.refused('lines.imageLabel', 'nginx:1', 'x" }}{{ .Os')
        self.refused('lines.tag', 'a:1', '-f')
        self.refused('lines.buildFromStdin', 'a:1', '--pull')
        self.refused('lines.buildFromStdin', 'x$(id)', '')
        self.refused('lines.isRunning', 'reg; id')


class RunsTest(Scratch):
    def test_a_file_line_removes_what_its_glob_matches_in_that_directory_only(self):
        directory = self.inside(ENGINE + '/users/alice/entrypoint-init.d')
        for name in ('a.sh', 'b.sh', 'keep.txt'):
            open(os.path.join(directory, name), 'w').close()
        result = self.run_plan(self.plan('lines.removeScripts', ENGINE + '/users/alice/entrypoint-init.d'))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(os.listdir(directory), ['keep.txt'])
        self.assertNotEqual(self.run_plan(self.plan('lines.removeScripts', ENGINE + '/users/alice/entrypoint-init.d')).returncode, 0)
        self.assertEqual(self.run_plan(self.plan('lines.removeScriptsIfAny', ENGINE + '/users/alice/entrypoint-init.d')).returncode, 0)

    def test_emptying_a_directory_takes_dot_entries_and_keeps_the_directory(self):
        directory = self.inside(ENGINE + '/users/alice/apache-sites')
        os.makedirs(os.path.join(directory, 'sub'))
        for name in ('a.conf', '.hidden', 'sub/x'):
            open(os.path.join(directory, name), 'w').close()
        os.symlink(self.inside('/home/alice'), os.path.join(directory, 'link'))
        self.run_plan(self.plan('lines.emptyDirectory', ENGINE + '/users/alice/apache-sites'))
        self.assertEqual(os.listdir(directory), [])
        self.assertTrue(os.path.isdir(self.scratch.home))

    def test_a_vhost_and_its_siblings_by_prefix_and_nothing_else(self):
        directory = self.inside(ENGINE + '/webserver-config/apache/vhosts')
        for name in ('a.com.conf', 'a.com.conf.bak', 'b.com.conf'):
            open(os.path.join(directory, name), 'w').close()
        self.run_plan(self.plan('scripts.removeStartingWith', ENGINE + '/webserver-config/apache/vhosts/a.com.conf'))
        self.assertEqual(os.listdir(directory), ['b.com.conf'])

    def test_the_compose_image_lines_skip_the_excluded_account(self):
        for user, image in (('alice', 'mine:1'), ('bob', 'theirs:1')):
            path = self.inside(f'/home/{user}/project/compose.yaml')
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, 'w') as f:
                f.write(f'services:\n  app:\n    image: {image}\n')
        result = self.run_plan(self.plan('scripts.composeImageLines', '/home', 'alice', 'compose.yaml', 'docker-compose.yml'))
        self.assertEqual((result.returncode, result.stdout), (0, b'    image: theirs:1\n'))


@unittest.skipUnless(1000 <= os.getuid() < 65534, 'needs an unprivileged account-like uid to chown to itself')
class HomeEntryRunTest(unittest.TestCase):
    def setUp(self):
        self.scratch = scriptwork.Scratch(alice=(os.getuid(), os.getgid()))
        self.owner = f'{os.getuid()}:{os.getgid()}'

    def tearDown(self):
        self.scratch.close()

    def run_entry(self, entry, env=None):
        found = registry()['scripts.makeHomeEntry']
        plan = found.plan(found.check([self.scratch.inside(entry), '0750', self.owner]), self.scratch.policy)
        try:
            return subprocess.run(plan.argv, pass_fds=plan.fds, capture_output=True, env=env,
                                  preexec_fn=lambda: os.fchdir(plan.cwd))
        finally:
            scriptwork.close(plan)

    def test_an_entry_that_is_there_is_handed_to_the_account_again_and_never_through_a_link(self):
        os.makedirs(os.path.join(self.scratch.home, 'cache'))
        shims = os.path.join(self.scratch.base, 'shims')
        os.makedirs(shims)
        log = os.path.join(self.scratch.base, 'chown.log')
        with open(os.path.join(shims, 'chown'), 'w') as f:
            f.write(f'#!/bin/sh\nprintf "%s " "$@" >> {log}\nexec {shutil.which("chown")} "$@"\n')
        os.chmod(os.path.join(shims, 'chown'), 0o755)
        env = {**os.environ, 'PATH': shims + ':' + os.environ['PATH']}
        result = self.run_entry('/home/alice/cache', env)
        self.assertEqual(result.returncode, 0, result.stderr)
        with open(log) as f:
            self.assertEqual(f.read(), f'-h {self.owner} -- ./cache ')

    def test_the_entry_is_made_with_its_mode_and_a_link_there_is_refused(self):
        self.assertEqual(self.run_entry('/home/alice/made').returncode, 0)
        self.assertEqual(oct(os.stat(os.path.join(self.scratch.home, 'made')).st_mode & 0o777), oct(0o750))
        os.symlink('/etc', os.path.join(self.scratch.home, 'link'))
        result = self.run_entry('/home/alice/link')
        self.assertEqual(result.returncode, 3)
        self.assertIn(b'is a symlink', result.stderr)


class LineThroughTheSocketTest(support.Serving):
    def test_a_line_in_a_chain_runs_and_returns_its_status(self):
        directory = self.directory('engine', 'users/u/entrypoint.d')
        open(os.path.join(directory, 'a.sh'), 'w').close()
        line = ' '.join([sys.executable, '-I', support.CLIENT, 'lines.removeScriptsIfAny', '--', directory])
        missing = ' '.join([sys.executable, '-I', support.CLIENT, 'lines.removeScripts', '--', directory])
        script = f'{line} && echo removed; {missing} 2>/dev/null || echo none-left'
        result = subprocess.run(['sh', '-c', script], capture_output=True, env={**os.environ, 'PA_ROOT_SOCKET': self.socket},
                                timeout=support.SECONDS)
        self.assertEqual(result.stdout, b'removed\nnone-left\n', result.stderr)
        self.assertEqual(os.listdir(directory), [])


if __name__ == '__main__':
    unittest.main()
