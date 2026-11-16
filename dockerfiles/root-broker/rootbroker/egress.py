"""The outbound limits' marks (scripts/tenant-egress-limits.sh), each named after the container that made it.

The kernel marks addresses only: a scan block, a flood or an SMTP attempt is an address in one of the table's sets. The
broker remembers which container held each address of the accounts' and the build networks when it last looked, so a
mark names its owner, an account's scan block stays on the account when its container comes back on another address,
and an address that passes to another container leaves its marks and counters behind: a new project never inherits a
block. The state lives in one file, read and written under a lock by the tenant-network service and the two actions.
"""

import contextlib
import fcntl
import ipaddress
import json
import os
import re
import subprocess
import tempfile
import time
import urllib.parse

from . import hostdaemon

TABLE = 'pa_egress'
NFT = ('nsenter', '--target', '1', '--all', 'nft')
STATE = '/opt/panelalpha/log/tenant-egress.json'
TENANTS = 'tenants'
BUILD = 'build'
ROLE = 'com.panelalpha.role'
MARKS = ('flood', 'smtp', 'scan')
COUNTERS = ('newdst', 'newdstweb', 'connrate')
LIFTED = ('blocked', 'scan')
EPISODE_SECONDS = 600
KEPT_SECONDS = 86400
REPEAT_SECONDS = 86400
LONGEST_BLOCK_SECONDS = 86400
NFT_SECONDS = 10
OWNER = re.compile(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}')
HOME = re.compile(r'^/home/([a-z0-9][a-z0-9_-]{0,63})/project(/|$)')
CACHE = re.compile(r'^/var/cache/panelalpha/projects/([a-z0-9][a-z0-9_-]{0,63})(/|$)')


def nft(*arguments):
    """One nft run on the host, its output kept."""
    return subprocess.run([*NFT, *arguments], capture_output=True, timeout=NFT_SECONDS)


def family(address):
    return '6' if ipaddress.ip_address(address).version == 6 else '4'


def listing(run=nft):
    """`set name -> {address: (timeout, expires)}` of the mark sets, or None while the table is missing. The counters
    and the seen pairs are not read: they can hold far more elements than the marks."""
    sets = {}
    for name in (f'{mark}{suffix}' for mark in ('blocked', *MARKS) for suffix in '46'):
        done = run('-j', 'list', 'set', 'inet', TABLE, name)
        if done.returncode != 0:
            return None
        try:
            objects = json.loads(done.stdout).get('nftables') or []
        except (ValueError, AttributeError):
            return None
        for item in objects:
            described = item.get('set') if isinstance(item, dict) else None
            if isinstance(described, dict) and described.get('name') == name:
                sets[name] = dict(filter(None, (element(found, described) for found in described.get('elem') or ())))
    return sets


def element(found, described):
    """`(address, (timeout, expires))` of one set element, None for one that is not a single address."""
    held = found.get('elem') if isinstance(found, dict) else None
    value = held.get('val') if isinstance(held, dict) else found
    if not isinstance(value, str):
        return None
    try:
        address = str(ipaddress.ip_address(value))
    except ValueError:
        return None
    timeout = held.get('timeout', described.get('timeout', 0)) if isinstance(held, dict) else described.get('timeout', 0)
    expires = held.get('expires', timeout) if isinstance(held, dict) else timeout
    return address, (seconds(timeout), seconds(expires))


def seconds(value):
    return value if isinstance(value, (int, float)) and value >= 0 else 0


def forget(address, sets, run=nft, marks=('blocked', *MARKS)):
    """The address's `marks`, from each set that holds it, and its counters, which may hold none."""
    suffix = family(address)
    for name in marks:
        if address in sets.get(name + suffix, {}):
            run('delete', 'element', 'inet', TABLE, name + suffix, '{ ' + address + ' }')
    for name in COUNTERS:
        run('delete', 'element', 'inet', TABLE, name + suffix, '{ ' + address + ' }')


def block(address, remaining, run=nft):
    """The address blocked for what is left of its owner's block."""
    run('add', 'element', 'inet', TABLE, 'blocked' + family(address), f'{{ {address} timeout {max(int(remaining), 1)}s }}')


def lengthen(address, remaining, run=nft):
    """The address's block replaced by a longer one in one transaction, so it is never lifted in between."""
    name = 'blocked' + family(address)
    run('delete', 'element', 'inet', TABLE, name, f'{{ {address} }}', ';',
        'add', 'element', 'inet', TABLE, name, f'{{ {address} timeout {max(int(remaining), 1)}s }}')


def repeated(held, owner, network, since):
    """How many blocks the owner had on this network in the day before `since`."""
    return sum(1 for began in held['history'].get(owner, {}).get(network, ())
               if since - REPEAT_SECONDS <= began < since)


@contextlib.contextmanager
def state(path=STATE):
    """The state, locked for the block and written back after it."""
    os.makedirs(os.path.dirname(path), mode=0o755, exist_ok=True)
    with open(path + '.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            with open(path) as current:
                held = json.load(current)
        except (OSError, ValueError):
            held = {}
        held = {key: held.get(key) if isinstance(held.get(key), dict) else {} for key in ('owners', 'blocks', 'marks', 'history')}
        held['blocks'] = {owner: blocks for owner, blocks in held['blocks'].items()
                          if isinstance(blocks, dict) and all(isinstance(known, dict) for known in blocks.values())}
        yield held
        written = tempfile.NamedTemporaryFile('w', dir=os.path.dirname(path), prefix='.tenant-egress.', delete=False)
        with written:
            json.dump(held, written, sort_keys=True)
        os.chmod(written.name, 0o600)
        os.replace(written.name, path)


def reconcile(members, now=None, run=nft, path=STATE):
    """The marks the table holds named and kept, from `members` (`address -> (owner, network)`); the report. The table
    is read under the lock, so an unblock is never undone by a listing taken before it."""
    now = time.time() if now is None else now
    with state(path) as held:
        sets = listing(run)
        if sets is not None:
            hand_over(members, sets, held, run)
            record_blocks(members, sets, held, now, run)
            follow(members, sets, held, now, run)
            record_marks(members, sets, held, now)
        expire(held, now)
        return report(sets is not None, held, now)


def hand_over(members, sets, held, run):
    """An address another container now holds leaves its marks and counters behind."""
    owners = held['owners']
    for address, (owner, network) in members.items():
        previous = owners.get(address)
        if isinstance(previous, dict) and previous.get('owner') != owner:
            forget(address, sets, run)
            for name in list(sets):
                sets[name].pop(address, None)
        owners[address] = {'owner': owner, 'network': network}


def owner_of(address, members, held):
    current = members.get(address)
    if current is not None:
        return current
    known = held['owners'].get(address)
    return (known['owner'], known['network']) if isinstance(known, dict) else None


def record_blocks(members, sets, held, now, run=nft):
    """Each blocked address on its owner's block on that network: since when, until when, on which address. A new
    block of an owner blocked before in the day lasts twice as long as the one before it, at most a day."""
    for address, (timeout, expires) in {**sets.get('blocked4', {}), **sets.get('blocked6', {})}.items():
        found = owner_of(address, members, held)
        if found is None:
            continue
        owner, network = found
        since, until = now - (timeout - expires), now + expires
        blocks = held['blocks'].setdefault(owner, {})
        known = blocks.get(network)
        if not isinstance(known, dict) or known.get('until', 0) < since:
            longest = max(timeout, min(timeout * 2 ** repeated(held, owner, network, since), LONGEST_BLOCK_SECONDS))
            if since + longest > until + 1:
                until = since + longest
                lengthen(address, until - now, run)
            blocks[network] = {'address': address, 'since': round(since), 'until': round(until)}
            began = held['history'].setdefault(owner, {}).setdefault(network, [])
            if round(since) not in began:
                began.append(round(since))
        else:
            known['until'] = max(known.get('until', 0), round(until))
            known['address'] = address


def follow(members, sets, held, now, run):
    """An owner's block on each network on the address its container there holds now, for what is left of it."""
    for owner, blocks in held['blocks'].items():
        for network, known in blocks.items():
            remaining = known.get('until', 0) - now
            if remaining <= 0:
                continue
            for address, (holder, holder_network) in members.items():
                if holder == owner and holder_network == network and address not in sets.get('blocked' + family(address), {}):
                    block(address, remaining, run)
                    known['address'] = address


def record_marks(members, sets, held, now):
    """A flood, an SMTP attempt or a scan that was only dropped: when it began and when it was last seen, by owner."""
    for kind in MARKS:
        for address, (timeout, expires) in {**sets.get(kind + '4', {}), **sets.get(kind + '6', {})}.items():
            found = owner_of(address, members, held)
            if found is None:
                continue
            owner, network = found
            last = round(now - (timeout - expires))
            episodes = held['marks'].setdefault(owner, {})
            known = episodes.get(kind)
            if not isinstance(known, dict) or last - known.get('last', 0) > EPISODE_SECONDS:
                episodes[kind] = {'network': network, 'address': address, 'since': last, 'last': last}
            else:
                known['last'] = max(known.get('last', 0), last)
                known['address'] = address


def expire(held, now):
    """A block that ended, a mark not seen for a day and the start of a block more than a day ago are dropped."""
    held['blocks'] = {owner: kept for owner, kept in (
        (owner, {network: known for network, known in blocks.items() if known.get('until', 0) > now})
        for owner, blocks in held['blocks'].items()) if kept}
    held['history'] = {owner: kept for owner, kept in (
        (owner, {network: [began for began in times if isinstance(began, (int, float)) and now - began < REPEAT_SECONDS]
                 for network, times in history.items() if isinstance(times, list)})
        for owner, history in held['history'].items() if isinstance(history, dict)) if any(kept.values())}
    held['marks'] = {owner: kept for owner, kept in (
        (owner, {kind: known for kind, known in episodes.items()
                 if isinstance(known, dict) and now - known.get('last', 0) < KEPT_SECONDS})
        for owner, episodes in held['marks'].items() if isinstance(episodes, dict)) if kept}


def report(active, held, now):
    """What the engine reads: whether the limits are in place, each block and each recent mark by owner."""
    return {
        'active': active,
        'blocks': [{'owner': owner, 'network': network, **known, 'seconds_left': max(0, round(known['until'] - now))}
                   for owner, blocks in sorted(held['blocks'].items()) for network, known in sorted(blocks.items())],
        'marks': [{'owner': owner, 'kind': kind, **known}
                  for owner, episodes in sorted(held['marks'].items()) for kind, known in sorted(episodes.items())],
    }


def settings(printed):
    """`NAME -> value` of the limits script's `--settings` lines, each a whole number."""
    found = {}
    for line in printed.decode(errors='replace').splitlines():
        name, _, value = line.partition('=')
        if re.fullmatch(r'EGRESS_[A-Z_]+', name) and value.isdigit():
            found[name] = int(value)
    return found


def unblock(owner, members, run=nft, path=STATE):
    """The owner's scan block lifted: its addresses lose the block, the scan mark and their counters, and the owner its
    earlier blocks, so they start afresh. SMTP and flood marks stay, since those limits still apply. The addresses."""
    with state(path) as held:
        sets = listing(run) or {}
        known = held['blocks'].pop(owner, None)
        held['history'].pop(owner, None)
        marks = held['marks'].get(owner)
        if isinstance(marks, dict):
            marks.pop('scan', None)
            if not marks:
                held['marks'].pop(owner)
        addresses = {address for address, (holder, _) in members.items() if holder == owner}
        addresses |= {address for address, entry in held['owners'].items() if isinstance(entry, dict) and entry.get('owner') == owner}
        addresses |= {block['address'] for block in (known or {}).values() if isinstance(block.get('address'), str)}
        for address in sorted(addresses):
            forget(address, sets, run, LIFTED)
        return sorted(addresses) if known else []


def members(daemon, tenants):
    """`address -> (owner, network)` of every container on the accounts' network and on each build network.

    An account is its container's name. A build container is unnamed, so it is the project whose checkout or cache it
    mounts, and its own name when it mounts neither."""
    found = {}
    for address, owner in attached(tenants):
        found[address] = (owner, TENANTS)
    for network in build_networks(daemon):
        for address, owner, container in attached_with_ids(network):
            found[address] = (project_of(daemon, container) or owner, BUILD)
    return found


def attached(network):
    return [(address, owner) for address, owner, _ in attached_with_ids(network)]


def attached_with_ids(network):
    """`(address, name, container id)` per container the network lists with a name and an address."""
    listed = (network or {}).get('Containers') or {}
    seen = []
    for container, described in sorted(listed.items()) if isinstance(listed, dict) else ():
        if not isinstance(described, dict):
            continue
        name, address = described.get('Name'), described.get('IPv4Address')
        if not isinstance(name, str) or not OWNER.fullmatch(name) or not isinstance(address, str):
            continue
        try:
            seen.append((str(ipaddress.IPv4Address(address.partition('/')[0])), name, container))
        except ValueError:
            continue
    return seen


def build_networks(daemon):
    """Every network labelled as a build network, inspected; none while the daemon does not answer."""
    query = urllib.parse.urlencode({'filters': json.dumps({'label': [f'{ROLE}={BUILD}']})})
    try:
        return inspected_networks(daemon, daemon.json('GET', '/networks?' + query))
    except hostdaemon.UNANSWERED:
        return []


def inspected_networks(daemon, listed):
    names = [summary.get('Name') for summary in (listed if isinstance(listed, list) else ()) if isinstance(summary, dict)]
    inspected = (daemon.json('GET', '/networks/' + urllib.parse.quote(name, safe='')) for name in names
                 if isinstance(name, str) and OWNER.fullmatch(name))
    return [network for network in inspected if isinstance(network, dict)]


def bridges(daemon):
    """The bridge of each build network, as Docker names it or after its id."""
    found = []
    for network in build_networks(daemon):
        options = network.get('Options') if isinstance(network.get('Options'), dict) else {}
        bridge = options.get('com.docker.network.bridge.name') or 'br-' + str(network.get('Id', ''))[:12]
        if isinstance(bridge, str) and re.fullmatch(r'[a-zA-Z0-9_.-]{1,15}', bridge):
            found.append(bridge)
    return found


def project_of(daemon, container):
    """The account whose checkout or project cache the container mounts, None for none."""
    described = daemon.json('GET', '/containers/' + urllib.parse.quote(container, safe='') + '/json')
    mounts = described.get('Mounts') if isinstance(described, dict) else None
    for mount in mounts if isinstance(mounts, list) else ():
        source = mount.get('Source') if isinstance(mount, dict) else None
        found = (HOME.match(source) or CACHE.match(source)) if isinstance(source, str) else None
        if found:
            return found.group(1)
    return None
