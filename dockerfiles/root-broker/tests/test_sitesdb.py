import os
import shutil
import subprocess
import tempfile
import unittest

import dockerwork
import support
from rootbroker.actions import compose, engineservices, registry
from rootbroker.refused import Refused

ENGINE = '/opt/panelalpha/shared-hosting'
ACCOUNT = 'bob_app'


class LeadingDashTest(unittest.TestCase):
    def test_a_database_named_like_an_option_comes_after_the_end_of_options(self):
        for name, given, build in (
            ('compose.sitesDbDump', ['-patest', ''], engineservices.sites_db_dump),
            ('compose.sitesDbRestore', ['-patest'], engineservices.sites_db_restore),
            ('compose.sitesDbDropTable', ['-patest', 't'], engineservices.sites_db_drop_table),
            ('compose.sitesDbCopy', ['-patest', '-pbtest', '', 'bob_app'], engineservices.sites_db_copy),
            ('compose.sitesDbRestoreCopy', ['alice_wp', '-patest', 'bob_app'], engineservices.sites_db_restore_copy),
        ):
            with self.subTest(action=name):
                script = build(*registry()[name].check([ENGINE, *given])[1:])
                self.assertNotIn(" '-patest' -", script)
                if name == 'compose.sitesDbRestoreCopy':
                    self.assertIn("\nD='-patest'\n", script)
                    self.assertTrue(script.endswith(' -- "$D"'))
                else:
                    self.assertIn("-- '-patest'", script)


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
                self.refused('compose.sitesDbCopy', name, 'bob_wp', '', 'bob_app')
                self.refused('compose.sitesDbCopy', 'alice_wp', name, '', 'bob_app')
                self.refused('compose.sitesDbRestoreCopy', name, 'bob_wp', 'bob_app')
                self.refused('compose.sitesDbRestoreCopy', 'alice_wp', name, 'bob_app')

    def test_a_table_that_could_leave_its_quotes_or_backticks_is_refused(self):
        for name in ("t'", 't`', 't;x', 't\n', 't x', '-t'):
            with self.subTest(name=name):
                self.refused('compose.sitesDbDump', 'alice_wp', name)
                self.refused('compose.sitesDbDropTable', 'alice_wp', name)
                self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', name, 'bob_app')
        self.refused('compose.sitesDbDropTable', 'alice_wp', '')

    def test_the_import_account_is_only_a_plain_account_and_never_root(self):
        for account in ("a'b", 'b`b', 'b@%', 'root', 'ROOT', 'a b', 'pa.import.x'):
            with self.subTest(account=account):
                self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', '', account)
                self.refused('compose.sitesDbRestoreCopy', 'alice_wp', 'bob_wp', account)
        self.refused('compose.sitesDbCopy', 'alice_wp', 'bob_wp', '', 'bob_app', 'bob_shop')
        self.refused('compose.sitesDbRestoreCopy', 'alice_wp', 'bob_wp', 'bob_app', 'bob_shop')

    def test_an_empty_import_account_asks_for_a_temporary_one(self):
        found = registry()['compose.sitesDbCopy']
        self.assertEqual(list(found.check([self.engine, 'alice_wp', 'bob_wp', '', ''])[1:]), ['alice_wp', 'bob_wp', '', ''])

    def test_the_import_signs_in_as_the_account_and_only_the_dump_and_its_sign_in_are_root_s(self):
        copy = engineservices.sites_db_copy('alice_wp', 'bob_wp', '', 'bob_app')
        restore_copy = engineservices.sites_db_restore_copy('alice_wp', 'bob_wp', 'bob_app')
        for script in (copy, restore_copy):
            self.assertTrue(script.endswith(' | MYSQL_PWD="$T" mariadb --sandbox --user="$A" -- "$D"'))
            self.assertIn("A='bob_app'\nD='bob_wp'\n", script)
            self.assertNotIn('export', script)
        self.assertIn('MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb-dump ', copy)
        self.assertNotIn('mariadb-dump', restore_copy)

    def test_only_the_engines_project_directory_is_accepted(self):
        found = registry()['compose.sitesDbRestore']
        for directory in (self.scratch.inside(ENGINE + '/users/alice'), '/', self.scratch.inside('/home/alice/project')):
            with self.subTest(directory=directory), self.assertRaises(Refused):
                found.plan(found.check([directory, 'bob_wp']), self.scratch.policy, False)


@unittest.skipUnless(shutil.which('sed'), 'needs sed')
class RewriteTest(unittest.TestCase):
    def rewrite(self, dump, source='alice_wp', target='bob_wp', account=ACCOUNT):
        script = engineservices.schema_rewrite(source, target)
        result = subprocess.run(['sh', '-c', script], input=dump.encode(), capture_output=True, check=True,
                                env={**os.environ, 'A': account})
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
        self.assertIn('/*!50017 DEFINER=`bob_app`@`%`*/', out)
        self.assertIn('TRIGGER bob_wp.trg', out)
        self.assertIn('ON bob_wp.t', out)

    def test_every_definer_goes_to_the_import_account_and_never_root(self):
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
        for index, routine in ((0, 'PROCEDURE `p_own`()'), (1, 'PROCEDURE `p_root`()'), (2, 'PROCEDURE `p_gone`()'),
                               (3, 'PROCEDURE `p_other_host`()'), (4, 'FUNCTION `f_odd`() RETURNS int(11)'),
                               (5, 'PROCEDURE `p_role`()')):
            self.assertEqual(lines[index], 'CREATE DEFINER=`bob_app`@`%` ' + routine)
        self.assertEqual(lines[6], '/*!50013 DEFINER=`bob_app`@`%` SQL SECURITY DEFINER */')
        self.assertTrue(lines[7].startswith('/*!50003 CREATE*/ /*!50017 DEFINER=`bob_app`@`%`*/'))
        self.assertIn("SET @x = 'DEFINER=`bob_app`@`%`'", lines[7])
        self.assertTrue(lines[8].startswith('/*!50106 CREATE*/ /*!50117 DEFINER=`bob_app`@`%`*/'))
        self.assertEqual(lines[10], "(1,'DEFINER=`root`@`localhost`');")
        for line in lines[:9]:
            self.assertNotIn('`root`', line)

    def test_a_definer_written_inside_a_string_never_moves_where_the_string_ends(self):
        def quoting(text):
            return [c for c in text if c in '\'"\\']

        own = 'CREATE DEFINER=`alice_wp`@`%` PROCEDURE `p`() '
        for body in ("SELECT 'DEFINER=`', `c`, ' ; GRANT ALL ON *.* TO evil; SELECT ';",
                     'SELECT "DEFINER=`", `c`, " ; DROP DATABASE bob_wp; SELECT ";',
                     "SELECT 'DEFINER=`\\\\', `c`, ' ; GRANT ALL ON *.* TO evil; -- ';",
                     "SELECT 'DEFINER=`x`@`'', `c`, ' ; GRANT ALL ON *.* TO evil; -- ';"):
            with self.subTest(body=body):
                out = self.rewrite(own + body)
                self.assertTrue(out.startswith('CREATE DEFINER=`bob_app`@`%` PROCEDURE `p`() '))
                self.assertEqual(quoting(out), quoting(own + body))
                self.assertTrue(out.endswith(body.split('`c`', 1)[1]))

    def test_a_database_name_is_escaped_for_the_pattern(self):
        script = engineservices.schema_rewrite('alice-wp', 'bob-wp')
        self.assertIn('alice\\-wp', script)
        self.assertNotIn('s/\\balice-wp\\.', script)
        self.assertEqual(self.rewrite('CREATE TABLE `alice-wp`.`t` (x int);', 'alice-wp', 'bob-wp'),
                         'CREATE TABLE `bob-wp`.`t` (x int);')


STUB_MARIADB = r'''#!/bin/bash
d="$STUB"
case " $* " in
*" --sandbox "*)
    printf 'import pwd=%s %s\n' "$MYSQL_PWD" "$*" >> "$d/log"
    if flock -n "$d/lock" true; then echo 'lock free during import' >> "$d/log"; else echo 'lock held during import' >> "$d/log"; fi
    printf 'grant during import: %s\n' "$(cat "$d/grant" 2>/dev/null)" >> "$d/log"
    cat > "$d/imported"
    [ -e "$d/import-changes-grant" ] && cp "$d/import-changes-grant" "$d/grant"
    [ -e "$d/import-kill" ] && kill -KILL $$
    exit "$(cat "$d/import-exit" 2>/dev/null || echo 0)" ;;
*" -e "*)
    sql="${@: -1}"
    printf 'query pwd=%s %s\n' "$MYSQL_PWD" "$sql" >> "$d/log"
    case $sql in
    SELECT*)
        n=$(( $(cat "$d/reads" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$d/reads"
        method=$(sed -n "${n}p" "$d/methods"); [ -n "$method" ] || method=$(tail -n 1 "$d/methods"); echo "$method" ;;
    "SHOW GRANTS FOR "*)
        echo 'GRANT USAGE ON *.* TO `bob_app`@`%`'; [ -s "$d/grant" ] && cat "$d/grant" ;;
    "GRANT ALL PRIVILEGES ON "*" TO '"*)
        printf '%s\n' "$sql" | sed "s/ TO '\([^']*\)'@'%'/ TO \`\1\`@\`%\`/" > "$d/grant" ;;
    GRANT*)
        printf '%s\n' "$sql" > "$d/grant" ;;
    REVOKE*)
        [ -e "$d/restore-fails" ] && exit 1
        : > "$d/grant" ;;
    ALTER*)
        [ -e "$d/restore-fails" ] && exit 1 ;;
    esac
    exit 0 ;;
*)
    printf 'stdin pwd=%s %s\n' "$MYSQL_PWD" "$(cat)" >> "$d/log" ;;
esac
'''
HASH = '*' + '0123456789ABCDEF' * 2 + '01234567'
NEWER = '*' + 'FEDCBA9876543210' * 2 + '76543210'
OWN_GRANT = 'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP ON `bob_wp`.* TO `bob_app`@`%`'
ALL_GRANT = 'GRANT ALL PRIVILEGES ON `bob_wp`.* TO `bob_app`@`%`'
DUMP = '#!/bin/sh\necho "CREATE DEFINER=\\`root\\`@\\`localhost\\` PROCEDURE p() SELECT 1;"\n'


@unittest.skipUnless(shutil.which('flock') and shutil.which('bash'), 'needs flock and bash')
class ImportScriptTest(unittest.TestCase):
    """The import script against a stand-in mariadb: what root does, what the account does, and what the trap undoes."""

    def setUp(self):
        self.stub = tempfile.mkdtemp(prefix='broker-import-')
        os.mkdir(os.path.join(self.stub, 'bin'))
        for name, body in (('mariadb', STUB_MARIADB), ('mariadb-dump', DUMP)):
            path = os.path.join(self.stub, 'bin', name)
            with open(path, 'w') as f:
                f.write(body)
            os.chmod(path, 0o755)
        self.methods(f'mysql_native_password {HASH}')
        self.flag('grant', OWN_GRANT + '\n')

    def tearDown(self):
        shutil.rmtree(self.stub)

    def methods(self, *lines):
        with open(os.path.join(self.stub, 'methods'), 'w') as f:
            f.write('\n'.join(lines) + '\n')

    def flag(self, name, content=''):
        with open(os.path.join(self.stub, name), 'w') as f:
            f.write(content)

    def read(self, name):
        with open(os.path.join(self.stub, name)) as f:
            return f.read()

    def fresh(self):
        for name in ('log', 'reads', 'imported'):
            if os.path.exists(os.path.join(self.stub, name)):
                os.remove(os.path.join(self.stub, name))

    def run_script(self, script):
        for lock in ('bob_app', 'database-bob_wp'):
            script = script.replace(f'{engineservices.IMPORT_LOCK_DIR}/panelalpha-sites-db-import-{lock}',
                                    os.path.join(self.stub, 'lock'))
        env = {'PATH': os.path.join(self.stub, 'bin') + ':' + os.environ['PATH'], 'STUB': self.stub,
               'MYSQL_ROOT_PASSWORD': 'r00t'}
        result = subprocess.run(['bash', '-c', script], input=b'CREATE DEFINER=`alice_wp`@`%` VIEW v AS SELECT 1;\n',
                                env=env, capture_output=True, timeout=30)
        return result, self.read('log').splitlines()

    def copy(self, account='bob_app'):
        return self.run_script(engineservices.sites_db_copy('alice_wp', 'bob_wp', '', account))

    def restored(self, lines, hash_=HASH):
        return f"query pwd=r00t ALTER USER 'bob_app'@'%' IDENTIFIED VIA mysql_native_password USING '{hash_}'" in lines

    def test_root_adds_a_second_sign_in_the_account_imports_with_and_the_trap_takes_it_back(self):
        for script in (engineservices.sites_db_copy('alice_wp', 'bob_wp', '', 'bob_app'),
                       engineservices.sites_db_restore_copy('alice_wp', 'bob_wp', 'bob_app')):
            with self.subTest(script=script[-60:]):
                self.fresh()
                result, lines = self.run_script(script)
                self.assertEqual(result.returncode, 0, result.stderr)
                added = next(line for line in lines if line.startswith('stdin pwd=r00t ALTER USER'))
                temporary = added.rsplit("PASSWORD('", 1)[1].rstrip("')")
                self.assertRegex(temporary, r'\A[A-Za-z0-9]{32}\Z')
                self.assertIn(f"IDENTIFIED VIA mysql_native_password USING '{HASH}' OR mysql_native_password USING", added)
                imported = f'import pwd={temporary} --sandbox --user=bob_app -- bob_wp'
                self.assertLess(lines.index(added), lines.index(imported))
                self.assertIn('lock held during import', lines)
                self.assertEqual(lines[-1], f"query pwd=r00t ALTER USER 'bob_app'@'%' IDENTIFIED VIA mysql_native_password USING '{HASH}'")
                self.assertEqual([line for line in lines if temporary in line], [added, imported])
                self.assertIn('DEFINER=`bob_app`@`%`', self.read('imported'))

    def test_the_account_has_every_privilege_on_the_target_while_importing_and_its_own_grant_back_after(self):
        result, lines = self.copy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f'grant during import: {ALL_GRANT}', lines)
        self.assertEqual(self.read('grant'), OWN_GRANT + '\n')
        self.assertIn("query pwd=r00t GRANT ALL PRIVILEGES ON `bob_wp`.* TO 'bob_app'@'%'", lines)
        self.assertNotIn('ON *.*', ' '.join(line for line in lines if 'GRANT ALL' in line))

    def test_an_account_with_no_grant_on_the_target_has_none_again_after(self):
        self.flag('grant')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f'grant during import: {ALL_GRANT}', lines)
        self.assertEqual(self.read('grant'), '')

    def test_an_account_that_already_had_every_privilege_keeps_it_untouched(self):
        self.flag('grant', ALL_GRANT + '\n')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse([line for line in lines if 'REVOKE' in line])
        self.assertEqual(self.read('grant'), ALL_GRANT + '\n')

    def test_a_grant_changed_during_the_import_is_kept(self):
        changed = 'GRANT SELECT ON `bob_wp`.* TO `bob_app`@`%`'
        self.flag('import-changes-grant', changed + '\n')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse([line for line in lines if 'REVOKE' in line])
        self.assertEqual(self.read('grant'), changed + '\n')

    def test_a_failed_import_still_takes_the_sign_in_and_the_grant_back_and_fails_with_its_code(self):
        self.flag('import-exit', '3')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 3)
        self.assertTrue(self.restored(lines))
        self.assertEqual(self.read('grant'), OWN_GRANT + '\n')

    def test_a_killed_import_still_takes_the_sign_in_and_the_grant_back(self):
        self.flag('import-kill')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 137)
        self.assertTrue(self.restored(lines))
        self.assertEqual(self.read('grant'), OWN_GRANT + '\n')

    def test_the_sign_in_is_put_back_as_it_is_by_then_so_a_password_changed_meanwhile_is_kept(self):
        self.methods(f'mysql_native_password {HASH}', f'mysql_native_password {NEWER}')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(self.restored(lines, NEWER))
        self.assertFalse(self.restored(lines, HASH))

    def test_an_account_that_is_missing_or_signs_in_otherwise_is_refused_and_nothing_is_touched(self):
        for method in ('NULL', 'ed25519 abc', f'mysql_native_password {HASH}x', "mysql_native_password ' OR 1"):
            with self.subTest(method=method):
                self.methods(method)
                self.fresh()
                result, lines = self.copy()
                self.assertEqual(result.returncode, 1)
                self.assertIn(b'refused', result.stderr)
                self.assertEqual([line for line in lines if not line.startswith('query pwd=r00t SELECT')], [])
                self.assertEqual(self.read('grant'), OWN_GRANT + '\n')

    def test_a_sign_in_that_cannot_be_taken_back_fails_the_import_and_says_so(self):
        self.flag('restore-fails')
        result, lines = self.copy()
        self.assertEqual(result.returncode, 1)
        self.assertIn(b'was not taken back', result.stderr)

    def test_with_no_account_a_temporary_one_imports_owns_every_definer_and_is_dropped(self):
        for exit_code in ('0', '3'):
            with self.subTest(exit_code=exit_code):
                self.fresh()
                self.flag('import-exit', exit_code)
                result, lines = self.copy(account='')
                self.assertEqual(result.returncode, int(exit_code), result.stderr)
                created = next(line for line in lines if line.startswith("stdin pwd=r00t CREATE USER 'pa.import."))
                name = created.split("'")[1]
                password = created.rsplit("IDENTIFIED BY '", 1)[1].rstrip("'")
                self.assertRegex(name, r'\Apa\.import\.[0-9a-f]{16}\Z')
                self.assertRegex(password, r'\A[A-Za-z0-9]{32}\Z')
                self.assertIn(f"query pwd=r00t GRANT ALL PRIVILEGES ON `bob_wp`.* TO '{name}'@'%'", lines)
                self.assertIn(f'import pwd={password} --sandbox --user={name} -- bob_wp', lines)
                self.assertIn('lock held during import', lines)
                self.assertIn(f'DEFINER=`{name}`@`%`', self.read('imported'))
                self.assertNotIn('`root`', self.read('imported'))
                self.assertEqual(lines[-1], f"query pwd=r00t DROP USER IF EXISTS '{name}'@'%'")
                self.assertFalse([line for line in lines if 'SHOW GRANTS' in line or 'ALTER USER' in line])
                self.assertEqual(self.read('grant'), ALL_GRANT.replace('bob_app', name) + '\n')

    def test_each_import_has_its_own_temporary_account(self):
        names = set()
        for _ in range(2):
            self.fresh()
            result, lines = self.copy(account='')
            names.add(next(line for line in lines if 'CREATE USER' in line).split("'")[1])
        self.assertEqual(len(names), 2)


if __name__ == '__main__':
    unittest.main()
