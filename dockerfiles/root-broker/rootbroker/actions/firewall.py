"""The `firewall` area: one action per App\\System\\Privileged\\RootFirewall method, in the host's namespaces only.

ufw takes only its own words and every file is a fixed one. The scripts are in rootbroker.actions.hostscripts, and the
engine's firewall.sh runs only while root alone can change it and the provider scripts it runs.
"""

import contextlib
import os

from .. import hostdaemon, hostvalues, scriptrun, values
from . import hostapi, hostscripts
from .builders import script
from .host import engine_script
from .hosted import fixed, host
from .sequence import nothing, reads

RULES = '/etc/ufw/user.rules'
RULES6 = '/etc/ufw/user6.rules'
CONF = '/etc/ufw/ufw.conf'
SNAPSHOT = '/opt/panelalpha/shared-hosting/data/ufw-snapshot'
FIREWALL_SCRIPT = 'scripts/firewall.sh'
PROVIDERS = 'scripts/firewall'
TRUSTED_LISTS = ('/etc/fail2ban/panelalpha-ignoreip', '/etc/fail2ban/panelalpha-api-ignoreip')
LOCKS = ('/opt/panelalpha/shared-hosting/data/ufw.lock', '/opt/panelalpha/shared-hosting/data/ufw-trusted.lock')
FAIL2BAN_LOG = '/var/log/fail2ban.log'
UFW_LOG = '/var/log/ufw.log'
SNAPSHOTTED = (SNAPSHOT, RULES, RULES6, CONF)
DOCKER_SUBNETS = 'PA_DOCKER_SUBNETS'


trusted_list_path = values.one_of(TRUSTED_LISTS, 'trusted list')
lock_file = values.one_of(LOCKS, 'firewall lock')


def firewall_script(policy):
    """firewall.sh, checked with each provider script it may run, as the broker's entrypoint checks them."""
    for name in providers(policy):
        engine_script(policy, f'{PROVIDERS}/{name}')
    return engine_script(policy, FIREWALL_SCRIPT)


def providers(policy):
    try:
        names = os.listdir(policy.roots['engine'].rstrip('/') + '/' + PROVIDERS)
    except (FileNotFoundError, NotADirectoryError):
        return []
    return sorted(name for name in names if name.endswith('.sh'))


def run_firewall_script(flag):
    return host(nothing, lambda context: ['bash', firewall_script(context.policy), flag])


def checked_firewall_script(context):
    """firewall.sh for an action that reads the host's Docker before it runs, checked as firewall_script checks it."""
    return (firewall_script(context.policy),)


def apply_trusted_lists(daemon, output, path):
    """`firewall.sh --fail2ban` with the host's Docker subnets from the Engine API in PA_DOCKER_SUBNETS, none when
    the daemon does not answer; its output and exit code are the action's."""
    subnets = []
    with contextlib.suppress(*hostdaemon.UNANSWERED):
        subnets = hostdaemon.every_subnet(daemon)
    environment = {DOCKER_SUBNETS: ' '.join(subnets)}
    return scriptrun.relayed(output, scriptrun.ran(['bash', path, '--fail2ban'], environment=environment))


ACTIONS = {
    'version': fixed('ufw', 'version'),
    'status': fixed('ufw', 'status', 'verbose'),
    'ufw': host(lambda given: (hostvalues.ufw_words(given.rest(values.word)),), lambda context, words: ['ufw', *words]),
    'refusedRules': run_firewall_script('--refused'),
    'applyTrustedLists': hostapi.asking(nothing, apply_trusted_lists, prepare=checked_firewall_script, host_only=True),
    'rules': fixed('cat', RULES),
    'rules6': fixed('cat', RULES6),
    'readTrustedList': host(reads(trusted_list_path), lambda context, path: ['cat', path]),
    'writeTrustedList': host(
        reads(trusted_list_path, hostvalues.trusted_list),
        lambda context, path, content: script(hostscripts.WRITE_LIST)(path, content),
    ),
    'unban': host(reads(hostvalues.address_or_network), lambda context, address: ['fail2ban-client', 'unban', address]),
    'ensureLockFile': host(reads(lock_file), lambda context, path: script(hostscripts.ENSURE_LOCK)(path)),
    'snapshotRules': fixed(*script(hostscripts.SNAPSHOT_RULES)(*SNAPSHOTTED)),
    'restoreRules': fixed(*script(hostscripts.RESTORE_RULES)(*SNAPSHOTTED)),
    'timezoneOffset': fixed('date', '+%:z'),
    'fail2banLog': host(reads(values.positive), lambda context, lines: ['tail', '-n', lines, FAIL2BAN_LOG]),
    'kernelBlockLog': host(
        reads(values.positive),
        lambda context, lines: ['journalctl', '-k', '--no-pager', '-o', 'short-iso', '-n', lines, '-g', 'UFW BLOCK'],
    ),
    'ufwLog': host(reads(values.positive), lambda context, lines: ['tail', '-n', lines, UFW_LOG]),
}
