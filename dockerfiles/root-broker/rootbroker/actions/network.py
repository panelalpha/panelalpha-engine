"""The `network` area: one action per App\\System\\Privileged\\RootNetwork method, in the host's namespaces only."""

from .. import hostvalues, values
from .hosted import fixed, host
from .sequence import reads

LOOKUP = ['curl', '--silent', '--show-error', '--fail', '--ipv4', '--proto', '=http,https']
SOCKET_OPTIONS = {'tcp': '-t', 'udp': '-u'}


def family_option(family):
    return ['-6'] if family == '6' else []


protocol = values.one_of(SOCKET_OPTIONS, 'protocol')


def change_route(verb):
    return host(
        reads(hostvalues.network, hostvalues.address, hostvalues.interface),
        lambda context, net, gateway, device: ['ip', 'route', verb, net, 'via', gateway, 'dev', device],
    )


def change_address(verb):
    return host(
        reads(hostvalues.network, hostvalues.interface),
        lambda context, net, device: ['ip', 'addr', verb, net, 'dev', device],
    )


ACTIONS = {
    'defaultRoute': host(
        reads(hostvalues.family),
        lambda context, family: ['ip', *family_option(family), 'route', 'show', 'default'],
    ),
    'addresses': fixed('ip', '-4', 'addr', 'show'),
    'addressDetails': fixed('ip', '-json', '-details', '-4', 'addr', 'show'),
    'interfaceAddresses': host(reads(hostvalues.interface), lambda context, device: ['ip', 'addr', 'show', 'dev', device]),
    'route': host(
        reads(hostvalues.family, hostvalues.network),
        lambda context, family, net: ['ip', *family_option(family), 'route', 'show', net],
    ),
    'addRoute': change_route('add'),
    'deleteRoute': change_route('del'),
    'addAddress': change_address('add'),
    'deleteAddress': change_address('del'),
    'restartNetworking': fixed('service', 'networking', 'restart'),
    'publicAddressLookup': host(
        reads(hostvalues.ipv4, hostvalues.url, values.positive),
        lambda context, local, url, timeout: [*LOOKUP, '--max-time', timeout, '--interface', local, '--url', url],
    ),
    'listeningSockets': host(reads(protocol), lambda context, name: ['ss', '-Hln', SOCKET_OPTIONS[name]]),
}
