"""Values of host commands: addresses, interfaces, ufw rule words, a fail2ban list.

The same rules as App\\System\\Privileged\\HostValues, checked again.
"""

import ipaddress
import re

from .values import matching, one_of, refuse

INTERFACE = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.:@-]{0,31}')
PORTS = re.compile(r'[0-9]+(?::[0-9]+)?(?:,[0-9]+(?::[0-9]+)?)*')
PROTOCOL = re.compile(r'[a-z0-9]+')
COMMENT_REFUSED = re.compile(r"['\r\n\x00]")
URL = re.compile(r'https?://[^\s\x00-\x1f\x7f]+', re.IGNORECASE | re.ASCII)
DEVICE = re.compile(r'/dev/[A-Za-z0-9][A-Za-z0-9_.:/-]*')
MASK = re.compile(r'[0-9]{1,3}')
TRUSTED_LINE = re.compile(r'(\S+)(?: # [^\r\n\x00]*)?', re.ASCII)
UFW_COMMANDS = (['--force', 'enable'], ['reload'], ['disable'])
UFW_ACTIONS = ('allow', 'deny', 'reject', 'limit')


family = one_of(('4', '6'), 'IP family')


def parsed_address(value):
    if '%' in value:
        return None
    try:
        return ipaddress.ip_address(value)
    except ValueError:
        return None


def address(value):
    if parsed_address(value) is None:
        refuse('IP address', value)
    return value


def ipv4(value):
    if not isinstance(parsed_address(value), ipaddress.IPv4Address):
        refuse('IPv4 address', value)
    return value


def network(value):
    """`address/mask`, the mask within the address's own family."""
    host, _, mask = value.partition('/')
    parsed = parsed_address(host)
    if parsed is None or not MASK.fullmatch(mask) or int(mask) > parsed.max_prefixlen:
        refuse('network', value)
    return value


def address_or_network(value):
    return network(value) if '/' in value else address(value)


interface = matching(INTERFACE, 'network interface')
url = matching(URL, 'URL')


def device(value):
    if not DEVICE.fullmatch(value) or '/../' in value:
        refuse('block device', value)
    return value


def rule_address(value):
    if value == 'any':
        return True
    try:
        address_or_network(value)
        return True
    except Exception:
        return False


RULE_PARTS = (
    ('proto', lambda v: PROTOCOL.fullmatch(v) is not None, False),
    ('from', rule_address, True),
    ('to', rule_address, True),
    ('port', lambda v: PORTS.fullmatch(v) is not None, False),
    ('comment', lambda v: COMMENT_REFUSED.search(v) is None, False),
)


def word_at(words, at):
    return words[at] if at < len(words) else None


def is_ufw_rule(words):
    at = 0
    at += 1 if word_at(words, at) == 'route' else 0
    at += 1 if word_at(words, at) in ('prepend', 'delete') else 0
    if word_at(words, at) not in UFW_ACTIONS:
        return False
    at += 1
    at += 1 if word_at(words, at) in ('in', 'out') else 0
    for keyword, check, required in RULE_PARTS:
        if word_at(words, at) != keyword:
            if required:
                return False
            continue
        if word_at(words, at + 1) is None or not check(words[at + 1]):
            return False
        at += 2
    return at == len(words)


def ufw_words(words):
    """ufw's own words: `--force enable`, `reload`, `disable`, or one rule
    `[route] [prepend|delete] ACTION [in|out] [proto P] from A to B [port P] [comment C]`."""
    words = list(words)
    if words in UFW_COMMANDS or is_ufw_rule(words):
        return words
    refuse('ufw command', ' '.join(words))


def trusted_list(value):
    """One `address # comment` line per entry, as UfwFirewall writes a fail2ban trusted list."""
    if value != '' and not value.endswith('\n'):
        refuse('trusted list', value)
    for line in (value.rstrip('\n').split('\n') if value else []):
        match = TRUSTED_LINE.fullmatch(line)
        if match is None:
            refuse('trusted list line', line)
        address_or_network(match.group(1))
    return value
