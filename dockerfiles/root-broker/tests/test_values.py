"""arguments-cases.json is shared with the PHP side's ArgumentsBrokerCasesTest."""

import json
import os
import re
import unittest

import support
from rootbroker import values
from rootbroker.actions import registry
from rootbroker.refused import Refused

ARGUMENTS = os.path.join(support.HERE, 'arguments-cases.json')


class ValuesTest(unittest.TestCase):
    def test_what_the_gateway_accepts_is_accepted(self):
        self.assertEqual(values.path('/home/u/my dir/'), '/home/u/my dir/')
        self.assertEqual(values.path('/home/u/project/./dist'), '/home/u/project/./dist')
        self.assertEqual(values.path_to_resolve('/home/u/project/../../v/.env'), '/home/u/project/../../v/.env')
        for mode in ('700', '0644', 'a+r', '+x', '-w', 'ugo-rwx,u+rwX,go+rX'):
            self.assertEqual(values.mode(mode), mode)
        for owner in ('www-data:www-data', '1000:1000', 'nobody', 'nobody:u_1'):
            self.assertEqual(values.owner(owner), owner)
        self.assertEqual(values.name_pattern('.project-next*'), '.project-next*')
        self.assertEqual(values.integer('-5'), '-5')
        self.assertEqual((values.count('0'), values.positive('1')), ('0', '1'))
        for cpus in ('1', '1.5', '0.25', '64', '0.000001'):
            self.assertEqual(values.cpus(cpus), cpus)

    def test_what_a_command_could_misread_is_refused(self):
        refusals = [
            (values.path, 'etc/a.conf'), (values.path, '/home/u/../v'), (values.path, '/home/u/..'),
            (values.path, '/d\n/etc'), (values.path, '/a\0'), (values.path_to_resolve, 'relative/..'),
            (values.mode, '700;id'), (values.mode, '--reference=/etc/shadow'), (values.mode, '0700 /etc'),
            (values.owner, '--from=root'), (values.owner, 'root root'),
            (values.operand, '--to-command=id'), (values.operand, '-e sh'), (values.operand, 'a\nb'),
            (values.name_pattern, '('), (values.name_pattern, '!'), (values.name_pattern, '-exec'),
            (values.integer, '1e3'), (values.integer, '05'), (values.integer, ''), (values.count, '-1'),
            (values.positive, '0'), (values.positive, '-1'), (values.positive, '-0'), (values.positive, '+1'),
            (values.cpus, '0'), (values.cpus, '0.0'), (values.cpus, '-1'), (values.cpus, '1e3'), (values.cpus, '.5'),
            (values.cpus, '1.'), (values.cpus, '01'), (values.cpus, '1.1234567'), (values.cpus, 'inf'), (values.cpus, '--cpus=8'),
        ]
        for check, value in refusals:
            with self.subTest(check=check.__name__, value=value), self.assertRaises(Refused):
                check(value)


    def test_a_count_reaches_no_command_as_a_negative_number(self):
        engine, users = '/opt/panelalpha/shared-hosting', '/opt/panelalpha/shared-hosting/users/u'
        file = engine + '/docker-compose.yml'
        given = [
            ('files.lastLines', '-5', '/opt/panelalpha/log/x'), ('files.removeEntriesOlderThan', '/home/u/x', '-5'),
            ('files.deleteFilesTwoLevelsDownOlderThan', engine + '/data/webserver/acme-challenges', '-5'),
            ('files.staleDirectoriesStartingWith', '/home/u', '.p', '-5'),
            ('files.staleFilesStartingWith', '/home/u', '-1', '5', '.p'), ('files.staleFilesStartingWith', '/home/u', '', '-5', '.p'),
            ('files.nitroServerEntries', '/home/u/project', '-1'),
            ('account.nitroServerEntries', '1004', '1004', '/home/u/project', '-1'),
            ('account.lastLinesOfLog', '1004', '1004', users + '/log/php/error.log', '-5'),
            ('docker.limitMemory', 'c', '-5'), ('docker.limitMemory', 'c', '0'), ('docker.pruneBuildCacheUnusedFor', '-5'),
            ('docker.limitCpu', 'c', '-1'), ('docker.limitCpu', 'c', '0'),
            ('docker.pruneBuildCacheAbove', '-1'), ('docker.pruneBuildCacheUntilFree', '-1'),
            ('host.processExists', '-1'), ('host.projectStats', '-1', 'u'), ('firewall.fail2banLog', '-5'),
            ('firewall.ufwLog', '-5'), ('firewall.kernelBlockLog', '-5'), ('build-cache.buildKit', '', '512m', '-1', '0', '0'),
            ('compose.ftpSetQuota', file, 'u', '-1'), ('compose.ftpAddUser', file, 'u', '-1', '1001', '/home/ftpuser/u', ''),
        ]
        for name, *taken in given:
            with self.subTest(action=name, given=taken), self.assertRaises(Refused):
                registry()[name].check(taken)

    def test_an_archive_takes_nothing_from_outside_the_directory_it_archives(self):
        home, archive = '/home/u', '/var/tmp/panelalpha-backup/u/1/home.tar.gz'
        action = registry()['files.createArchive']
        for given in (['/proc/1/root/root/secret'], ['..'], ['../v'], ['link/secret'], ['.', '/etc'], ['.', './a/../../b']):
            with self.subTest(given=given), self.assertRaises(Refused):
                action.check([home, archive, *given])
        for given in (['.', './.incoming', './.pre-push', './.pre-push-db'], ['project']):
            with self.subTest(given=given):
                self.assertEqual(action.check([home, archive, *given])[2:], [[given[0]], given[1:]])

    def test_the_gateways_cases_get_the_gateways_verdict(self):
        with open(ARGUMENTS) as f:
            cases = json.load(f)
        for case in cases:
            check = getattr(values, re.sub(r'([A-Z])', lambda m: '_' + m.group(1).lower(), case['kind']))
            with self.subTest(kind=case['kind'], value=case['value']):
                if case['accepted']:
                    self.assertEqual(check(case['value']), case['value'])
                else:
                    self.assertRaises(Refused, check, case['value'])


if __name__ == '__main__':
    unittest.main()
