"""run_host_script, and the broker's entrypoint and tenant-network service that run the host's firewalls through it."""

import functools
import os
import re
import shutil
import subprocess
import tempfile
import time
import unittest

import support
from rootbroker import egress, tenantports

ENGINE = os.path.join(support.HERE, '..', '..', '..')
LIBRARY = os.path.join(ENGINE, 'config', 'root-broker', 'services', '.lib', 'host-script.sh')
SOURCED = '/etc/s6/root-broker/.lib/host-script.sh'
ENTRYPOINT = os.path.join(ENGINE, 'dockerfiles', 'entrypoint-root-broker.sh')
REFUSAL = "pa-root-owned: '{}' could be written by someone other than root"


@unittest.skipUnless(os.path.isdir(os.path.join(ENGINE, 'config')), 'needs the engine checkout around the broker')
class Scratch(unittest.TestCase):
    def setUp(self):
        self.base = os.path.realpath(tempfile.mkdtemp(prefix='host-script-'))
        self.script = os.path.join(self.base, 'scripts', 'firewall.sh')
        self.failed = os.path.join(self.base, 'log', 'firewall.failed')
        self.bin = os.path.join(self.base, 'bin')
        os.makedirs(os.path.dirname(self.script))
        os.makedirs(self.bin)
        open(self.script, 'w').close()
        self.fake('pa-root-owned', 'echo "$*" >>"$0.argv"; cat "$0.says" >&2 2>/dev/null; exit "$(cat "$0.exit" 2>/dev/null || echo 0)"')
        self.fake('nsenter', 'echo "$*" >>"$0.argv"; cat "$0.says" 2>/dev/null; exit "$(cat "$0.exit" 2>/dev/null || echo 0)"')
        self.library = os.path.join(self.base, 'host-script.sh')
        with open(self.library, 'w') as f:
            f.write(f". '{LIBRARY}'\nROOT_OWNED='{os.path.join(self.bin, 'pa-root-owned')}'\n")

    def tearDown(self):
        shutil.rmtree(self.base, ignore_errors=True)

    def fake(self, name, body):
        path = os.path.join(self.bin, name)
        with open(path, 'w') as f:
            f.write('#!/bin/sh\n' + body + '\n')
        os.chmod(path, 0o755)

    def answer(self, name, says, code):
        with open(os.path.join(self.bin, name + '.says'), 'w') as f:
            f.write(says)
        with open(os.path.join(self.bin, name + '.exit'), 'w') as f:
            f.write(str(code))

    def asked(self, name):
        try:
            with open(os.path.join(self.bin, name + '.argv')) as f:
                return f.read().splitlines()
        except FileNotFoundError:
            return []

    def refuse(self, path):
        self.answer('pa-root-owned', REFUSAL.format(path) + '\n', 1)

    def run_shell(self, shell, *lines):
        """Each run in a shell of its own, as each apply after a network event runs, with pa-root-owned faked."""
        program = '\n'.join(lines).replace(SOURCED, self.library)
        env = dict(os.environ, PATH=self.bin + os.pathsep + os.environ['PATH'])
        result = subprocess.run([shell], input=program, capture_output=True, text=True, env=env, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stderr.splitlines()

    def recorded(self):
        with open(self.failed) as f:
            return f.read().strip()


class RunHostScriptTest(Scratch):
    def apply(self, checked=None):
        return self.run_shell(
            'sh', f'. {SOURCED}',
            f"run_host_script tag 'the thing' 'it breaks' '{self.failed}' '{checked or self.script}' sh '{self.script}' --apply",
        )

    def test_a_refused_script_is_logged_once_with_the_command_that_fixes_it(self):
        self.refuse(self.script)

        first = self.apply()
        second = self.apply()

        self.assertEqual(first, [
            f'tag: the thing was not applied ({REFUSAL.format(self.script)}), so it breaks. '
            "An engine update or a source deploy puts the engine's scripts back, writable by root alone. "
            f"By hand, once '{self.script}' is known to be the engine's own: "
            f"chown root:root '{self.script}' && chmod go-w '{self.script}'.",
        ])
        self.assertEqual(second, [])
        self.assertEqual(self.recorded(), first[0])
        self.assertEqual(self.asked('nsenter'), [])

    def test_every_path_named_is_checked_and_a_pattern_names_what_it_matches(self):
        directory = os.path.join(os.path.dirname(self.script), 'firewall')
        os.makedirs(directory)
        for name in ('ufw.sh', 'other.sh'):
            open(os.path.join(directory, name), 'w').close()

        self.apply(f'{self.script} {directory} {directory}/*.sh')

        self.assertEqual(self.asked('pa-root-owned'),
                         [f'{self.script} {directory} {directory}/other.sh {directory}/ufw.sh'])

    def test_a_refusal_that_lasts_is_logged_again_after_an_hour(self):
        self.refuse(self.script)
        self.apply()
        an_hour_ago = time.time() - 3601
        os.utime(self.failed, (an_hour_ago, an_hour_ago))

        self.assertEqual(len(self.apply()), 1)
        self.assertEqual(self.apply(), [])

    def test_a_different_reason_is_logged_at_once(self):
        self.refuse(self.script)
        self.apply()
        self.refuse(os.path.dirname(self.script))

        logged = self.apply()

        self.assertEqual(len(logged), 1)
        self.assertIn(REFUSAL.format(os.path.dirname(self.script)), logged[0])

    def test_a_run_after_a_failure_is_logged_once_and_clears_the_record(self):
        self.refuse(self.script)
        self.apply()
        self.answer('pa-root-owned', '', 0)

        self.assertEqual(self.apply(), ['tag: the thing applies again'])
        self.assertFalse(os.path.exists(self.failed))
        self.assertEqual(self.apply(), [])

    def test_a_run_that_works_says_nothing_and_records_nothing(self):
        self.assertEqual(self.apply(), [])
        self.assertFalse(os.path.exists(self.failed))
        self.assertEqual(self.asked('nsenter'), [f'--target 1 --all sh {self.script} --apply'])

    def test_a_script_that_fails_is_logged_with_its_last_line(self):
        self.answer('nsenter', 'firewall: applying\nfirewall: no provider script for ufw\n\n', 1)

        logged = self.apply()

        self.assertEqual(len(logged), 1)
        self.assertIn('(firewall: no provider script for ufw)', logged[0])
        self.assertIn(f'Run sh {self.script} --apply on the host for its whole output.', logged[0])
        self.assertEqual(self.recorded(), logged[0])

    def test_a_missing_script_is_logged(self):
        os.remove(self.script)

        logged = self.apply()

        self.assertEqual(len(logged), 1)
        self.assertIn(f'({self.script} is missing)', logged[0])
        self.assertEqual(self.asked('pa-root-owned'), [])

    def test_the_record_is_readable_by_the_engine_and_written_whole(self):
        self.refuse(self.script)
        self.apply()

        self.assertEqual(os.stat(self.failed).st_mode & 0o777, 0o644)
        self.assertEqual(os.listdir(os.path.dirname(self.failed)), [os.path.basename(self.failed)])


class CheckHostScriptTest(Scratch):
    def check(self):
        return self.run_shell(
            'sh', f'. {SOURCED}',
            f"check_host_script tag 'the thing' 'it breaks' '{self.failed}' '{self.script}'; echo \"exit $?\" >&2",
        )

    def test_a_refusal_is_logged_and_kept_as_a_run_logs_it_and_the_check_fails(self):
        self.refuse(self.script)

        logged = self.check()

        self.assertEqual(len(logged), 2)
        self.assertTrue(logged[0].startswith(f'tag: the thing was not applied ({REFUSAL.format(self.script)})'))
        self.assertEqual(logged[1], 'exit 1')
        self.assertEqual(self.recorded(), logged[0])
        self.assertEqual(self.asked('nsenter'), [])

    def test_a_script_that_may_run_passes_runs_nothing_and_keeps_the_record(self):
        self.refuse(self.script)
        self.check()
        recorded = self.recorded()
        self.answer('pa-root-owned', '', 0)

        self.assertEqual(self.check(), ['exit 0'])
        self.assertEqual(self.recorded(), recorded)
        self.assertEqual(self.asked('nsenter'), [])


class Tenants:
    """The host's daemon with the accounts' network on it and no member on the network."""

    def json(self, method, path, **_):
        return {'Name': tenantports.NETWORK, 'IPAM': {'Config': [{'Subnet': '10.201.0.0/16'}]}}


class NoTenants:
    """The host's daemon before the accounts' network is made."""

    def json(self, method, path, **_):
        return None


class Silent:
    """A host daemon that does not answer."""

    def json(self, method, path, **_):
        raise ConnectionRefusedError


class TenantNetworkServiceTest(Scratch):
    def setUp(self):
        super().setUp()
        self.saved = (tenantports.HOST_SCRIPT, tenantports.FAILED, tenantports.LIMITS_FAILED, tenantports.host_links,
                      os.environ['PATH'], egress.reconcile)
        self.limits = os.path.join(os.path.dirname(self.script), tenantports.LIMITS)
        self.limits_failed = os.path.join(self.base, 'log', 'limits.failed')
        open(self.limits, 'w').close()
        tenantports.HOST_SCRIPT, tenantports.FAILED, tenantports.LIMITS_FAILED = self.library, self.failed, self.limits_failed
        tenantports.host_links = list
        os.environ['PATH'] = self.bin + os.pathsep + os.environ['PATH']
        egress.reconcile = functools.partial(egress.reconcile, path=os.path.join(self.base, 'log', 'tenant-egress.json'))

    def tearDown(self):
        (tenantports.HOST_SCRIPT, tenantports.FAILED, tenantports.LIMITS_FAILED, tenantports.host_links,
         os.environ['PATH'], egress.reconcile) = self.saved
        super().tearDown()

    def apply(self, daemon=None):
        """One round of the service, what it logged read off its stderr."""
        with tempfile.TemporaryFile() as logged:
            kept = os.dup(2)
            os.dup2(logged.fileno(), 2)
            try:
                tenantports.applied(daemon or Tenants(), self.script)
            finally:
                os.dup2(kept, 2)
                os.close(kept)
            logged.seek(0)
            return logged.read().decode().splitlines()

    def test_the_tenant_firewall_runs_in_the_hosts_namespaces(self):
        self.assertEqual(self.apply(), [])
        self.assertEqual(self.asked('pa-root-owned'), [self.script, self.limits, self.script],
                         'the script checked first, then its limits, then the script by the run')
        self.assertEqual(self.asked('nsenter'), [f'--target 1 --all sh {self.script} --ports-on-stdin',
                                                 '--target 1 --all nft -j list set inet pa_egress blocked4'],
                         'then the outbound limits\' marks are read, also in the host\'s namespaces')

    def test_a_missing_or_refused_limits_script_is_logged_and_the_firewall_runs_without_it(self):
        os.remove(self.limits)
        logged = self.apply()
        self.assertEqual(len(logged), 1)
        self.assertTrue(logged[0].startswith(f'tenant-network: the outbound limits script was not applied ({self.limits} is missing)'),
                        logged[0])
        self.assertIn('the tenant firewall still applies', logged[0])
        self.assertEqual(self.asked('nsenter')[0], f'--target 1 --all sh {self.script} --ports-on-stdin --without-limits')
        self.assertFalse(os.path.exists(self.failed), 'the tenant firewall itself applied')
        open(self.limits, 'w').close()
        self.fake('pa-root-owned', f'echo "$*" >>"$0.argv"; [ "$1" != "{self.limits}" ] || {{ echo "{REFUSAL.format(self.limits)}" >&2; exit 1; }}')
        self.assertEqual(len(self.apply()), 1, 'a new reason is logged at once')
        self.assertEqual(self.asked('nsenter')[2], f'--target 1 --all sh {self.script} --ports-on-stdin --without-limits')
        self.assertEqual(self.apply(), [], 'the same one again is not')
        self.fake('pa-root-owned', 'echo "$*" >>"$0.argv"')
        self.assertEqual(self.apply(), ['tenant-network: the outbound limits script applies again'])
        self.assertFalse(os.path.exists(self.limits_failed))
        self.assertEqual(self.asked('nsenter')[-2], f'--target 1 --all sh {self.script} --ports-on-stdin')

    def test_a_refusal_says_what_an_account_loses(self):
        self.refuse(self.script)

        logged = self.apply()

        self.assertEqual(len(logged), 1)
        self.assertTrue(logged[0].startswith('tenant-network: the tenant firewall was not applied ('), logged[0])
        self.assertIn('so an account container Docker starts on its own gets no network until the engine starts it.',
                      logged[0])
        self.assertEqual(self.recorded(), logged[0])

    def test_a_refusal_is_reported_while_the_network_is_missing_or_the_daemon_is_silent(self):
        for daemon in (NoTenants(), Silent()):
            with self.subTest(daemon=type(daemon).__name__):
                if os.path.exists(self.failed):
                    os.remove(self.failed)
                self.refuse(self.script)

                logged = self.apply(daemon)

                self.assertEqual(len(logged), 1)
                self.assertTrue(logged[0].startswith('tenant-network: the tenant firewall was not applied ('), logged[0])
                self.assertEqual(self.recorded(), logged[0])
                self.assertEqual(self.asked('nsenter'), [])


class EntrypointTest(Scratch):
    def apply(self):
        with open(ENTRYPOINT) as f:
            entrypoint = f.read()
        function = re.search(r'^apply_host_firewall\(\) \{\n.*?^\}$', entrypoint, re.M | re.S).group(0)
        return self.run_shell('bash', 'set -e', f"firewall_script='{self.script}'", f"firewall_failed='{self.failed}'",
                              function, '(set +e; apply_host_firewall)')

    def test_the_host_firewall_and_every_provider_script_are_checked_before_it_runs(self):
        providers = os.path.join(os.path.dirname(self.script), 'firewall')
        os.makedirs(providers)
        open(os.path.join(providers, 'ufw.sh'), 'w').close()

        self.assertEqual(self.apply(), [])

        self.assertEqual(self.asked('pa-root-owned'), [f'{self.script} {providers} {providers}/ufw.sh'])
        self.assertEqual(self.asked('nsenter'), [f'--target 1 --all timeout 30 bash {self.script} --apply'])

    def test_a_refusal_is_logged_and_kept_for_the_engine(self):
        self.refuse(self.script)

        logged = self.apply()

        self.assertEqual(len(logged), 1)
        self.assertTrue(logged[0].startswith("root-broker: the host firewall's Docker hook was not applied ("), logged[0])
        self.assertIn("so the ports Docker publishes may not be filtered as the firewall's rules say.", logged[0])
        self.assertEqual(self.recorded(), logged[0])
        self.assertEqual(self.asked('nsenter'), [])

    def test_the_broker_starts_whatever_the_firewall_does(self):
        with open(ENTRYPOINT) as f:
            entrypoint = f.read()

        self.assertIn('(set +e; apply_host_firewall) &\n', entrypoint)
        self.assertLess(entrypoint.index('(set +e; apply_host_firewall) &'), entrypoint.index('exec s6-svscan'))


if __name__ == '__main__':
    unittest.main()
