import os
import shutil
import subprocess
import unittest

import dockerwork
import support
from rootbroker.actions import compose, engineservices, registry
from rootbroker.refused import Refused

ENGINE = '/opt/panelalpha/shared-hosting'
DEFINERS = [('alice_wp', 'bob_wp')]


class LeadingDashTest(unittest.TestCase):
    def test_a_database_named_like_an_option_comes_after_the_end_of_options(self):
        for name, given, build in (
            ('compose.sitesDbDump', ['-patest', ''], engineservices.sites_db_dump),
            ('compose.sitesDbRestore', ['-patest'], engineservices.sites_db_restore),
            ('compose.sitesDbDropTable', ['-patest', 't'], engineservices.sites_db_drop_table),
            ('compose.sitesDbCopy', ['-patest', '-pbtest', '', 'no.definer', '0'], engineservices.sites_db_copy),
            ('compose.sitesDbRestoreCopy', ['alice_wp', '-patest', 'no.definer', '0'],
             engineservices.sites_db_restore_copy),
        ):
            with self.subTest(action=name):
                script = build(*registry()[name].check([ENGINE, *given])[1:])
                self.assertIn("-- '-patest'", script)
                self.assertNotIn(" '-patest' -", script)


class RefusalTest(unittest.TestCase):
    def setUp(self):
        self.scratch = dockerwork.Scratch()
        self.engine = self.scratch.inside(ENGINE)

    def tearDown(self):
        self.scratch.close()

    def refused(self, action, *given):
        found = registry()[action]
        with self.assertRaises(Refused):
            plan = found.plan(found.check([self.engine, *given]), self.scratch.policy, False)
            dockerwork.close(plan)

    def test_a_name_that_could_leave_its_quotes_is_refused(self):
        for name in ("a'b", 'a b', 'a;id', 'a`b', '', 'a$b', 'a\nb', 'é'):
            with self.subTest(name=name):
                self.refused('compose.sitesDbDump', name, '')
                self.refused('compose.sitesDbRestore', name)
                self.refused('compose.sitesDbCopy', name, 'bob_wp', '', 'bob_no.definer', '0')
                self.refused('compose.sitesDbCopy', 'alice_wp', name, '', 'bob_no.definer', '0')
                self.refused('compose.sitesDbRestoreCopy', name, 'bob_wp', 'bob_no.definer', '0')
                self.refused('compose.sitesDbRestoreCopy', 'alice_wp', name, 'bob_no.definer', '0')

    def test_a_table_that_could_leave_its_quotes_or_backticks_is_refused(self):
        for name in ("t'", 't`', 't;x', 't\n', 't x', '-t'):
            with self.subTest(name=name):
                self.refused('compose.sitesDbDump', 'alice_wp', name)
                self.refused('compose.sitesDbDropTable', 'alice_wp', name)
                self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', name, 'bob_no.definer', '0')
        self.refused('compose.sitesDbDropTable', 'alice_wp', '')

    def test_a_definer_is_only_a_plain_account_and_the_orphan_only_a_prefixed_no_definer(self):
        for pair in (["a'b", 'bob_wp'], ['alice_wp', 'b`b'], ['alice_wp', 'b@%'], ['alice_wp', '']):
            with self.subTest(pair=pair):
                self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', '', 'bob_no.definer', '1', *pair)
                self.refused('compose.sitesDbRestoreCopy', 'alice_wp', 'bob_wp', 'bob_no.definer', '1', *pair)
        for orphan in ('root', 'bob_no.definerx', "b'no.definer", ''):
            with self.subTest(orphan=orphan):
                self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', '', orphan, '0')
                self.refused('compose.sitesDbRestoreCopy', 'alice_wp', 'bob_wp', orphan, '0')
        self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', '', 'bob_no.definer', '2', 'alice_wp', 'bob_wp')

    def test_only_the_engines_project_directory_is_accepted(self):
        found = registry()['compose.sitesDbRestore']
        for directory in (self.scratch.inside(ENGINE + '/users/alice'), '/', self.scratch.inside('/home/alice/project')):
            with self.subTest(directory=directory), self.assertRaises(Refused):
                found.plan(found.check([directory, 'bob_wp']), self.scratch.policy, False)


@unittest.skipUnless(shutil.which('sed'), 'needs sed')
class RewriteTest(unittest.TestCase):
    def rewrite(self, dump, source='alice_wp', target='bob_wp', definers=DEFINERS, orphan='bob_no.definer'):
        script = engineservices.schema_rewrite(source, target, definers, orphan)
        result = subprocess.run(['sh', '-c', script], input=dump.encode(), capture_output=True, check=True)
        return result.stdout.decode()

    def test_row_data_stays_untouched_and_the_trigger_moves_to_the_target(self):
        out = self.rewrite('\n'.join([
            'LOCK TABLES `t` WRITE;',
            'INSERT INTO `t` VALUES',
            "(1,'alice_wp.example'),",
            "(2,'`alice_wp`.x'),",
            "(3,'plain');",
            'UNLOCK TABLES;',
            '/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER alice_wp.trg BEFORE INSERT ON '
            'alice_wp.t FOR EACH ROW SET NEW.v = UPPER(NEW.v) */;;',
        ]))
        self.assertIn("(1,'alice_wp.example')", out)
        self.assertIn("(2,'`alice_wp`.x')", out)
        self.assertIn('/*!50017 DEFINER=`bob_no.definer`@`%`*/', out)
        self.assertIn('TRIGGER bob_wp.trg', out)
        self.assertIn('ON bob_wp.t', out)

    def test_every_definer_goes_to_an_account_of_the_target_and_never_root(self):
        lines = self.rewrite('\n'.join([
            'CREATE DEFINER=`alice_wp`@`%` PROCEDURE `p_own`()',
            'CREATE DEFINER=`root`@`localhost` PROCEDURE `p_root`()',
            'CREATE DEFINER=`alice_gone`@`%` PROCEDURE `p_gone`()',
            'CREATE DEFINER=`alice_wp`@`localhost` PROCEDURE `p_other_host`()',
            'CREATE DEFINER=`we``ird`@`%` FUNCTION `f_odd`() RETURNS int(11)',
            'CREATE DEFINER=`some_role` PROCEDURE `p_role`()',
            '/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */',
            "/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER t BEFORE INSERT ON t FOR EACH ROW "
            "SET @x = 'DEFINER=`alice_wp`@`%`' */;;",
            '/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`%`*/ /*!50106 EVENT `e` ON SCHEDULE EVERY 1 DAY DO DELETE FROM t */ ;;',
            'INSERT INTO `t` VALUES',
            "(1,'DEFINER=`root`@`localhost`');",
        ])).split('\n')
        self.assertEqual(lines[0], 'CREATE DEFINER=`bob_wp`@`%` PROCEDURE `p_own`()')
        for index, routine in ((1, 'PROCEDURE `p_root`()'), (2, 'PROCEDURE `p_gone`()'), (3, 'PROCEDURE `p_other_host`()'),
                               (4, 'FUNCTION `f_odd`() RETURNS int(11)'), (5, 'PROCEDURE `p_role`()')):
            self.assertEqual(lines[index], 'CREATE DEFINER=`bob_no.definer`@`%` ' + routine)
        self.assertEqual(lines[6], '/*!50013 DEFINER=`bob_no.definer`@`%` SQL SECURITY DEFINER */')
        self.assertTrue(lines[7].startswith('/*!50003 CREATE*/ /*!50017 DEFINER=`bob_no.definer`@`%`*/'))
        self.assertIn("SET @x = 'DEFINER=`bob_wp`@`%`'", lines[7])
        self.assertTrue(lines[8].startswith('/*!50106 CREATE*/ /*!50117 DEFINER=`bob_no.definer`@`%`*/'))
        self.assertEqual(lines[10], "(1,'DEFINER=`root`@`localhost`');")
        for line in lines[:9]:
            self.assertNotIn('`root`', line)

    def test_a_database_name_is_escaped_for_the_pattern(self):
        script = engineservices.schema_rewrite('alice-wp', 'bob-wp', [], 'bob_no.definer')
        self.assertIn('alice\\-wp', script)
        self.assertNotIn('s/\\balice-wp\\.', script)
        self.assertEqual(self.rewrite('CREATE TABLE `alice-wp`.`t` (x int);', 'alice-wp', 'bob-wp', []),
                         'CREATE TABLE `bob-wp`.`t` (x int);')


if __name__ == '__main__':
    unittest.main()
