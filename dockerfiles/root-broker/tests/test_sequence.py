import unittest

import support  # noqa: F401
from rootbroker import values
from rootbroker.actions import registry
from rootbroker.actions.sequence import Command, constant, flag, group, nothing, reads, rest, step
from rootbroker.refused import Refused


def checked(read, *given):
    return Command(read, None).check(list(given))


class ReadsTest(unittest.TestCase):
    def test_each_step_gives_one_value_and_reads_joins_readers_and_plain_checks(self):
        read = reads(values.name, nothing, reads(flag('all'), group(values.name, values.operand)), rest(values.name))
        self.assertEqual(checked(read, 'a', 'all', '1', 'k', 'v', 'b', 'c'), ('a', True, [('k', 'v')], ['b', 'c']))
        self.assertEqual(checked(read, 'a', '', '0'), ('a', False, [], []))

    def test_a_constant_takes_no_value(self):
        read = reads(constant(True), values.name, constant(()))
        self.assertEqual(checked(read, 'a'), (True, 'a', ()))
        with self.assertRaises(Refused):
            checked(read, 'a', 'b')

    def test_a_step_takes_its_value_its_own_way(self):
        read = reads(values.name, step(lambda given: ''.join(given.rest(values.name))))
        self.assertEqual(checked(read, 'a', 'b', 'c'), ('a', 'bc'))


class DeclaredReadersTest(unittest.TestCase):
    def check(self, name, *given):
        return registry()[name].check(list(given))

    def test_an_account_action_reads_the_account_before_its_own_values(self):
        self.assertEqual(
            self.check('account-docker.listContainers', 'alice', 'all', '2', 'label', 'p=x', 'status', 'running'),
            ('alice', True, {'label': ['p=x'], 'status': ['running']}),
        )
        self.assertEqual(self.check('account-docker.listContainers', 'alice', '', '0'), ('alice', False, {}))
        self.assertEqual(self.check('account-docker.ping', 'alice'), ('alice',))

    def test_an_exec_or_a_run_needs_a_command(self):
        self.assertEqual(self.check('account-docker.exec', 'alice', 'app', '', '', '0', 'true'),
                         ('alice', 'app', '', '', [], ['true']))
        self.assertEqual(self.check('account-container.exec', 'alice', '', '/w', '1', 'HOME', '/h', 'id'),
                         ('alice', '', '/w', [('HOME', '/h')], ['id']))
        for name, given in (('account-docker.exec', ('alice', 'app', '', '', '0')),
                            ('account-container.exec', ('alice', '', '', '0')),
                            ('account-docker.run', ('alice', 'busybox:1', '0'))):
            with self.subTest(name=name):
                with self.assertRaises(Refused) as caught:
                    self.check(name, *given)
                self.assertEqual(str(caught.exception), 'nothing to run')

    def test_a_build_from_stdin_reads_what_its_declaration_does_not_fix(self):
        self.assertEqual(self.check('docker.buildFromStdin', 'gt/a:1', 'no-cache', '1', 'A', 'b'),
                         ('gt/a:1', True, True, [('A', 'b')]))
        self.assertEqual(self.check('docker.buildFromStdin', 'gt/a:1', '', '0'), ('gt/a:1', True, False, []))
        self.assertEqual(self.check('lines.buildFromStdin', 'gt/a:1', 'pull'), ('gt/a:1', True, False, ()))
        self.assertEqual(self.check('lines.buildFromStdin', 'gt/a:1', ''), ('gt/a:1', False, False, ()))


if __name__ == '__main__':
    unittest.main()
