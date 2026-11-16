import os
import re
import unittest

import casework
import support  # noqa: F401
from rootbroker.actions import registry

ROOT_FILES = os.path.join(support.HERE, '..', '..', '..', 'core', 'app', 'System', 'Privileged', 'RootFiles.php')


class ActionsTest(unittest.TestCase):
    def test_each_case_runs_the_sudo_command_word_for_word_on_the_same_paths(self):
        for case in casework.load():
            with self.subTest(method=case['method'], host=case['host']):
                scratch = casework.Scratch()
                try:
                    plan = casework.plan_case(scratch, case)
                    expected = casework.unprivileged(case['sudo'])
                    if plan.native is not None:
                        self.assertEqual(casework.native_answer(plan), casework.run_inside(scratch, expected))
                        continue
                    self.assertEqual(len(plan.argv), len(expected), plan.argv)
                    for want, got in zip(expected, plan.argv):
                        if want != got:
                            self.assertTrue(casework.same_path(want, casework.resolved(scratch, plan, got)), (want, got))
                    casework.close(plan)
                finally:
                    scratch.close()

    def test_paths_are_placed_rather_than_passed_as_given(self):
        for case in casework.load():
            if case['method'] in ('existingRealPaths',):
                continue
            with self.subTest(method=case['method']):
                scratch = casework.Scratch()
                try:
                    plan = casework.plan_case(scratch, case)
                    self.assertFalse([word for word in plan.argv if word.startswith(scratch.base)], plan.argv)
                    casework.close(plan)
                finally:
                    scratch.close()

    @unittest.skipUnless(os.path.exists(ROOT_FILES), 'needs the engine checkout around the broker')
    def test_every_root_files_method_has_an_action_and_a_case(self):
        with open(ROOT_FILES) as f:
            methods = set(re.findall(r'public function (\w+)\(', f.read())) - {'__construct'}
        actions = {name.removeprefix('files.') for name in registry() if name.startswith('files.')}
        self.assertEqual(methods, actions)
        self.assertEqual(methods, {case['method'] for case in casework.load()})


if __name__ == '__main__':
    unittest.main()
