"""`rootbroker.egress`: the outbound limits' marks named after their owners, a block kept with its account, an address
that changes hands cleared, and the members of the accounts' and build networks.

The nft listings are what `nft -j list table inet pa_egress` prints (nftables 1.1), the daemon a stand-in.
"""

import json
import os
import subprocess
import tempfile
import threading
import unittest

import support  # noqa: F401
from rootbroker import egress, tenantports

ALICE = '10.200.128.5'
ALICE_LATER = '10.200.128.9'
BOB = '10.200.128.6'
BUILD = '172.30.0.2'
NOW = 1_800_000_000


def element(address, expires, timeout=None):
    held = {'val': address, 'expires': expires}
    if timeout is not None:
        held['timeout'] = timeout
    return {'elem': held}


def described(name, timeout, *elements):
    found = {'family': 'inet', 'name': name, 'table': 'pa_egress', 'type': 'ipv4_addr', 'handle': 1,
             'size': 65536, 'flags': ['timeout', 'dynamic'], 'timeout': timeout}
    if elements:
        found['elem'] = list(elements)
    return {'set': found}


class Nft:
    """nft on a table held in memory: `list` answers from it, `add` and `delete element` change it."""

    def __init__(self, sets=None, missing=False):
        self.sets = sets or {}
        self.missing = missing
        self.calls = []

    def __call__(self, *arguments):
        self.calls.append(arguments)
        if ';' in arguments:
            cut = arguments.index(';')
            done = self.one(*arguments[:cut])
            return done if done.returncode else self.one(*arguments[cut + 1:])
        return self.one(*arguments)

    def one(self, *arguments):
        if arguments[:2] == ('-j', 'list'):
            if self.missing:
                return subprocess.CompletedProcess(arguments, 1, b'', b'Error: No such file or directory')
            name = arguments[5]
            timeout, held = self.sets.get(name, (600, {}))
            listed = [{'metainfo': {'version': '1.1.6'}},
                      described(name, timeout, *(element(address, expires, own) for address, (expires, own) in held.items()))]
            return subprocess.CompletedProcess(arguments, 0, json.dumps({'nftables': listed}).encode(), b'')
        name, value = arguments[4], arguments[5].strip('{} ')
        address, _, rest = value.partition(' ')
        timeout, held = self.sets.setdefault(name, (600, {}))
        if arguments[0] == 'delete':
            if held.pop(address, None) is None:
                return subprocess.CompletedProcess(arguments, 1, b'', b'Error: Could not process rule: No such file or directory')
        else:
            own = int(rest.split()[1].rstrip('s')) if rest.startswith('timeout') else None
            held[address] = (own or timeout, own)
        return subprocess.CompletedProcess(arguments, 0, b'', b'')

    def held(self, name):
        return sorted(self.sets.get(name, (0, {}))[1])


class UnblockedWhileListing(Nft):
    """nft whose first listing lets the engine's unblock of `owner` run alongside, once that listing was read."""

    def __init__(self, sets, owner, members, path):
        super().__init__(sets)
        self.racing = threading.Thread(target=egress.unblock, args=(owner, members), kwargs={'run': self, 'path': path})

    def one(self, *arguments):
        done = super().one(*arguments)
        if arguments[:2] == ('-j', 'list') and self.racing.ident is None:
            self.racing.start()
            self.racing.join(0.5)
        return done


class Daemon:
    """The host's Engine API, answering the paths it was given."""

    def __init__(self, answers):
        self.answers = answers

    def json(self, method, path, missing=(404,), **_):
        return self.answers.get(path)


class Reconciled(unittest.TestCase):

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.state = os.path.join(self.dir.name, 'log', 'tenant-egress.json')

    def tearDown(self):
        self.dir.cleanup()

    def reconcile(self, nft, members, now=NOW):
        return egress.reconcile(members, now=now, run=nft, path=self.state)

    def test_a_listing_is_read_as_the_kernel_prints_it(self):
        nft = Nft({'blocked4': (600, {ALICE: (1797, 1800)}), 'smtp4': (600, {BOB: (597, None)})})
        sets = egress.listing(nft)
        self.assertEqual({ALICE: (1800, 1797)}, sets['blocked4'])
        self.assertEqual({BOB: (600, 597)}, sets['smtp4'], 'an element without a timeout of its own has the set\'s')

    def test_a_missing_table_is_reported_inactive(self):
        found = self.reconcile(Nft(missing=True), {ALICE: ('alice', 'tenants')})
        self.assertEqual({'active': False, 'blocks': [], 'marks': []}, found)

    def test_a_scan_block_names_its_account(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)})})
        found = self.reconcile(nft, {ALICE: ('alice', 'tenants'), BOB: ('bob', 'tenants')})
        self.assertTrue(found['active'])
        self.assertEqual([{'owner': 'alice', 'network': 'tenants', 'address': ALICE, 'since': NOW - 10,
                           'until': NOW + 1790, 'seconds_left': 1790}], found['blocks'])

    def test_a_block_stays_with_its_account_on_a_new_address(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        nft.sets['blocked4'][1].pop(ALICE)
        found = self.reconcile(nft, {ALICE_LATER: ('alice', 'tenants')}, now=NOW + 60)
        self.assertEqual([ALICE_LATER], nft.held('blocked4'))
        self.assertEqual(1730, nft.sets['blocked4'][1][ALICE_LATER][1], 'only what is left of the block')
        self.assertEqual(ALICE_LATER, found['blocks'][0]['address'])

    def test_an_address_that_changes_hands_is_cleared(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)}), 'newdst4': (3600, {ALICE: (3590, None)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        found = self.reconcile(nft, {ALICE: ('bob', 'tenants')}, now=NOW + 60)
        self.assertEqual([], nft.held('blocked4'), 'bob does not inherit alice\'s block')
        self.assertEqual([], nft.held('newdst4'), 'nor her count of new destinations')
        self.assertEqual(['alice'], [known['owner'] for known in found['blocks']], 'alice\'s block is still hers')

    def test_the_seen_pairs_and_the_counters_are_never_listed(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        listed = [call[5] for call in nft.calls if call[:2] == ('-j', 'list')]
        self.assertEqual(sorted(f'{mark}{suffix}' for mark in ('blocked', 'flood', 'smtp', 'scan') for suffix in '46'), sorted(listed))

    def test_the_same_account_keeps_its_address_blocked(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')}, now=NOW + 60)
        self.assertEqual([ALICE], nft.held('blocked4'))
        self.assertNotIn('delete', [call[0] for call in nft.calls])

    def test_a_flood_or_smtp_mark_is_an_episode_by_owner(self):
        nft = Nft({'smtp4': (600, {BOB: (590, None)})})
        found = self.reconcile(nft, {BOB: ('bob', 'tenants')})
        self.assertEqual([{'owner': 'bob', 'kind': 'smtp', 'network': 'tenants', 'address': BOB,
                           'since': NOW - 10, 'last': NOW - 10}], found['marks'])
        nft.sets['smtp4'][1][BOB] = (600, None)
        found = self.reconcile(nft, {BOB: ('bob', 'tenants')}, now=NOW + 120)
        self.assertEqual((NOW - 10, NOW + 120), (found['marks'][0]['since'], found['marks'][0]['last']), 'the same episode')
        found = self.reconcile(nft, {BOB: ('bob', 'tenants')}, now=NOW + 120 + egress.EPISODE_SECONDS + 1)
        self.assertEqual(NOW + 121 + egress.EPISODE_SECONDS, found['marks'][0]['since'], 'a new one after a quiet spell')

    def test_an_ended_block_and_an_old_mark_are_dropped(self):
        nft = Nft({'blocked4': (600, {ALICE: (30, 1800)}), 'flood4': (600, {BOB: (590, None)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants'), BOB: ('bob', 'tenants')})
        nft.sets = {}
        found = self.reconcile(nft, {}, now=NOW + egress.KEPT_SECONDS)
        self.assertEqual([], found['blocks'])
        self.assertEqual([], found['marks'])

    def test_a_mark_of_an_address_no_container_holds_keeps_its_last_owner(self):
        nft = Nft()
        self.reconcile(nft, {BUILD: ('shop', 'build')})
        nft.sets['blocked4'] = (600, {BUILD: (1790, 1800)})
        found = self.reconcile(nft, {}, now=NOW + 5)
        self.assertEqual(('shop', 'build'), (found['blocks'][0]['owner'], found['blocks'][0]['network']))

    def test_an_account_block_and_a_build_block_of_one_project_are_two_blocks(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800), BUILD: (1700, 1800)})})
        found = self.reconcile(nft, {ALICE: ('shop', 'tenants'), BUILD: ('shop', 'build')})
        self.assertEqual([('shop', 'build', BUILD), ('shop', 'tenants', ALICE)],
                         [(known['owner'], known['network'], known['address']) for known in found['blocks']])
        found = self.reconcile(nft, {ALICE: ('shop', 'tenants')}, now=NOW + 5)
        self.assertEqual(['build', 'tenants'], [known['network'] for known in found['blocks']],
                         'the build block stays once its container is gone')

    def test_unblock_reaches_the_address_of_a_build_that_ended(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800), BUILD: (1700, 1800)})})
        self.reconcile(nft, {ALICE: ('shop', 'tenants'), BUILD: ('shop', 'build')})
        lifted = egress.unblock('shop', {ALICE: ('shop', 'tenants')}, run=nft, path=self.state)
        self.assertEqual(sorted([ALICE, BUILD]), lifted)
        self.assertEqual([], nft.held('blocked4'))

    def test_a_state_file_of_the_older_shape_is_rebuilt_from_the_table(self):
        os.makedirs(os.path.dirname(self.state), exist_ok=True)
        with open(self.state, 'w') as f:
            json.dump({'blocks': {'alice': {'network': 'tenants', 'address': ALICE, 'since': 1, 'until': NOW + 9}}}, f)
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)})})
        found = self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        self.assertEqual([('alice', 'tenants', NOW - 10)], [(k['owner'], k['network'], k['since']) for k in found['blocks']])

    def block_again(self, nft, at, own=1800):
        """The kernel's next scan block of ALICE, at `at` seconds past NOW."""
        nft.sets['blocked4'] = (600, {ALICE: (own, own)})
        return self.reconcile(nft, {ALICE: ('alice', 'tenants')}, now=NOW + at)['blocks'][0]

    def test_a_block_again_within_a_day_lasts_twice_as_long_each_time_up_to_a_day(self):
        nft, at, lasted = Nft(), 0, []
        for _ in range(6):
            found = self.block_again(nft, at)
            lasted.append(found['until'] - found['since'])
            at = found['until'] - NOW + 1
        self.assertEqual([1800, 3600, 7200, 14400, 28800, 57600], lasted)
        self.assertEqual(57600, nft.sets['blocked4'][1][ALICE][1], 'the kernel holds the longer block')
        lengthened = [call for call in nft.calls if call[0] == 'delete' and ';' in call]
        self.assertEqual(5, len(lengthened), 'replaced in one transaction each time')

    def test_the_doubling_stops_at_a_day(self):
        nft = Nft()
        first = self.block_again(nft, 0, own=50000)
        found = self.block_again(nft, first['until'] - NOW + 1, own=50000)
        self.assertEqual(egress.LONGEST_BLOCK_SECONDS, found['until'] - found['since'])

    def test_a_block_a_day_after_the_last_starts_again_from_the_setting(self):
        nft = Nft()
        self.block_again(nft, 0)
        found = self.block_again(nft, egress.REPEAT_SECONDS + 1800)
        self.assertEqual(1800, found['until'] - found['since'])

    def test_a_lifted_owner_starts_again_from_the_setting(self):
        nft = Nft()
        self.block_again(nft, 0)
        egress.unblock('alice', {ALICE: ('alice', 'tenants')}, run=nft, path=self.state)
        found = self.block_again(nft, 60)
        self.assertEqual(1800, found['until'] - found['since'])

    def test_unblock_lifts_the_block_and_clears_the_counters(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)}), 'newdst4': (3600, {ALICE: (3590, None)}),
                   'newdstweb4': (3600, {ALICE: (3590, None)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        self.assertEqual([ALICE], egress.unblock('alice', {ALICE: ('alice', 'tenants')}, run=nft, path=self.state))
        self.assertEqual(([], [], []), (nft.held('blocked4'), nft.held('newdst4'), nft.held('newdstweb4')),
                         'the web ports\' count of new addresses too')
        self.assertEqual([], self.reconcile(nft, {ALICE: ('alice', 'tenants')})['blocks'])

    def test_unblock_keeps_the_smtp_and_flood_marks_which_still_apply(self):
        nft = Nft({'blocked4': (600, {ALICE: (1790, 1800)}), 'smtp4': (600, {ALICE: (590, None)}),
                   'flood4': (600, {ALICE: (590, None)})})
        self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        egress.unblock('alice', {ALICE: ('alice', 'tenants')}, run=nft, path=self.state)
        self.assertEqual(([], [ALICE], [ALICE]), (nft.held('blocked4'), nft.held('smtp4'), nft.held('flood4')))
        found = self.reconcile(nft, {ALICE: ('alice', 'tenants')})
        self.assertEqual(['flood', 'smtp'], sorted(mark['kind'] for mark in found['marks']))

    def test_an_unblock_while_the_table_is_listed_stays_lifted(self):
        members = {ALICE: ('alice', 'tenants')}
        self.reconcile(Nft({'blocked4': (600, {ALICE: (1790, 1800)})}), members)
        nft = UnblockedWhileListing({'blocked4': (600, {ALICE: (1780, 1800)})}, 'alice', members, self.state)
        self.reconcile(nft, members, now=NOW + 10)
        nft.racing.join()
        found = self.reconcile(nft, members, now=NOW + 20)
        self.assertEqual([], found['blocks'])
        self.assertEqual([], nft.held('blocked4'), 'the block is not put back from a listing older than the unblock')

    def test_unblock_of_an_account_without_a_block_changes_nothing(self):
        nft = Nft({'newdst4': (3600, {BOB: (3590, None)})})
        self.assertEqual([], egress.unblock('bob', {BOB: ('bob', 'tenants')}, run=nft, path=self.state))

    def test_ipv6_addresses_use_their_own_sets(self):
        nft = Nft({'blocked6': (600, {'fd00::5': (57, 60)})})
        found = self.reconcile(nft, {'fd00::5': ('alice', 'tenants')})
        self.assertEqual('fd00::5', found['blocks'][0]['address'])
        egress.unblock('alice', {}, run=nft, path=self.state)
        self.assertEqual([], nft.held('blocked6'))

    def test_the_state_is_root_s_alone(self):
        self.reconcile(Nft(), {ALICE: ('alice', 'tenants')})
        self.assertEqual(0o600, os.stat(self.state).st_mode & 0o777)


TENANTS = {'Name': 'pash-tenants', 'Containers': {
    'a' * 64: {'Name': 'alice', 'IPv4Address': ALICE + '/16', 'MacAddress': '02:42:0a:c8:80:05'},
    'f' * 64: {'Name': 'bad name', 'IPv4Address': BOB + '/16'},
}}
BUILDS = {
    '/networks?filters=%7B%22label%22%3A+%5B%22com.panelalpha.role%3Dbuild%22%5D%7D': [{'Name': 'panelalpha-build'}],
    '/networks/panelalpha-build': {'Name': 'panelalpha-build', 'Id': '0123456789abcdef',
                                   'Options': {'com.docker.network.bridge.name': 'br-pa-build'},
                                   'Containers': {'b' * 64: {'Name': 'eager_turing', 'IPv4Address': BUILD + '/16'},
                                                  'c' * 64: {'Name': 'quiet_hopper', 'IPv4Address': '172.30.0.3/16'}}},
    '/containers/' + 'b' * 64 + '/json': {'Mounts': [{'Source': '/etc/ssl/certs'}, {'Source': '/home/shop/project'}]},
    '/containers/' + 'c' * 64 + '/json': {'Mounts': [{'Source': '/var/cache/panelalpha/projects/blog/npm'}]},
}


class Settings(unittest.TestCase):

    def test_the_scripts_settings_are_read_as_numbers(self):
        printed = b'EGRESS_BLOCK_SMTP=1\nEGRESS_SCAN_BLOCK_MINUTES=30\nnoise\nEGRESS_X=y\n'
        self.assertEqual({'EGRESS_BLOCK_SMTP': 1, 'EGRESS_SCAN_BLOCK_MINUTES': 30}, egress.settings(printed))


class Members(unittest.TestCase):

    def test_accounts_are_their_containers_and_builds_their_projects(self):
        found = egress.members(Daemon(BUILDS), TENANTS)
        self.assertEqual({ALICE: ('alice', 'tenants'), BUILD: ('shop', 'build'), '172.30.0.3': ('blog', 'build')}, found)

    def test_a_build_mounting_no_project_is_its_own_name(self):
        answers = {**BUILDS, '/containers/' + 'b' * 64 + '/json': {'Mounts': [{'Source': '/home/shop/../etc'}]}}
        self.assertEqual(('eager_turing', 'build'), egress.members(Daemon(answers), {})[BUILD])

    def test_the_build_bridges_as_docker_names_them_or_after_the_id(self):
        self.assertEqual(['br-pa-build'], egress.bridges(Daemon(BUILDS)))
        unnamed = {**BUILDS, '/networks/panelalpha-build': {'Name': 'panelalpha-build', 'Id': '0123456789abcdef'}}
        self.assertEqual(['br-0123456789ab'], egress.bridges(Daemon(unnamed)))

    def test_a_daemon_that_lists_no_build_network_gives_none(self):
        self.assertEqual([], egress.bridges(Daemon({})))

    def test_the_script_is_told_the_build_bridges(self):
        written = tenantports.lines(Daemon(BUILDS), {'IPAM': {'Config': [{'Subnet': '10.200.0.0/16'}]}}, links=[])
        self.assertIn(b'source br-pa-build\n', written)

    def test_the_events_watched_cover_the_build_networks(self):
        self.assertIn('panelalpha-build', tenantports.watched(Daemon(BUILDS)))


if __name__ == '__main__':
    unittest.main()
