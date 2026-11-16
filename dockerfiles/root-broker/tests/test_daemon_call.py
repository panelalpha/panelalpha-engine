import types
import unittest

import support  # noqa: F401
from rootbroker import accountdaemon, accounts, hostdaemon, protocol, replies
from rootbroker.actions import accountdocker, hostapi
from rootbroker.actions.sequence import nothing
from rootbroker.dockerapi import Unavailable
from rootbroker.output import Output

ACCOUNT = accounts.Account('alice', 1001, 1001, '/home/alice', '/usr/sbin/nologin')
USERS = '/opt/panelalpha/shared-hosting/users'


class Written(Output):
    def __init__(self):
        self.out, self.err = b'', b''

    def stdout(self, data):
        self.out += data

    def stderr(self, data):
        self.err += data


class FakeDaemon:
    def __init__(self, name, seconds=None):
        self.name = name
        self.seconds = seconds
        self.closed = False

    def close(self):
        self.closed = True


def context():
    found = {ACCOUNT.name: ACCOUNT}
    policy = types.SimpleNamespace(accounts=types.SimpleNamespace(by_name=found.__getitem__), accounts_directory=USERS)
    return types.SimpleNamespace(policy=policy)


def raising(error):
    def work(daemon, output, *given):
        raise error
    return work


def printing(daemon, output, *given):
    output.stdout(repr((daemon.seconds, given)).encode())
    return 3


def ran(command, *given):
    output = Written()
    code = command.build(context(), *given)(output)
    return code, output.out, output.err


class HostCallTest(unittest.TestCase):
    def setUp(self):
        self.opened = []
        self.saved = hostdaemon.open_host
        hostdaemon.open_host = self.open_host

    def tearDown(self):
        hostdaemon.open_host = self.saved

    def open_host(self, seconds):
        self.opened.append(FakeDaemon(hostdaemon.NAME, seconds))
        return self.opened[-1]

    def test_the_work_gets_the_daemon_and_the_prepared_values_and_the_daemon_is_closed(self):
        command = hostapi.asking(nothing, printing, prepare=lambda context, a, b: (b, a), seconds=7)
        self.assertEqual(ran(command, 'a', 'b'), (3, b"(7, ('b', 'a'))", b''))
        self.assertEqual(ran(hostapi.asking(nothing, printing), 'a'), (3, b"(30, ('a',))", b''))
        self.assertEqual([daemon.closed for daemon in self.opened], [True, True])

    def test_what_goes_wrong_is_in_the_hosts_words_with_its_bound(self):
        for error, line in ((TimeoutError(), b"the host's Docker sent nothing for 7s\n"),
                            (ConnectionResetError(), b"the host's Docker stopped answering: ConnectionResetError()\n"),
                            (replies.Unexpected('Id'), b"the host's Docker answered with an unexpected Id\n")):
            with self.subTest(error=error):
                self.assertEqual(ran(hostapi.asking(nothing, raising(error), seconds=7)), (1, b'', line))
                self.assertTrue(self.opened[-1].closed)


class AccountCallTest(unittest.TestCase):
    def setUp(self):
        self.opened = []
        self.saved = accountdaemon.open_daemon
        accountdaemon.open_daemon = self.open_daemon

    def tearDown(self):
        accountdaemon.open_daemon = self.saved

    def open_daemon(self, account, accounts_directory):
        self.opened.append((FakeDaemon(account.name), accounts_directory))
        return self.opened[-1][0]

    def test_prepare_gets_the_account_and_the_work_only_what_prepare_returns(self):
        prepared = accountdocker.asking(nothing, printing, lambda context, account, value: (account.home, value))
        self.assertEqual(ran(prepared, 'alice', 'x'), (3, b"(None, ('/home/alice', 'x'))", b''))
        self.assertEqual(ran(accountdocker.asking(nothing, printing), 'alice', 'x'), (3, b"(None, ('x',))", b''))
        self.assertEqual([(daemon.closed, directory) for daemon, directory in self.opened], [(True, USERS)] * 2)

    def test_what_goes_wrong_is_in_the_words_of_the_accounts_daemon(self):
        for error, line, code in (
            (replies.Unexpected('State.Pid'), b'the Docker daemon of alice answered with an unexpected State.Pid\n', 1),
            (ConnectionResetError(), b'the Docker daemon of alice stopped answering: ConnectionResetError()\n',
             protocol.EXIT_UNAVAILABLE),
            (Unavailable('container alice is restarting'), b'container alice is restarting\n', protocol.EXIT_UNAVAILABLE),
            (TimeoutError(), b'the Docker daemon of alice did not answer in time\n', protocol.EXIT_UNAVAILABLE),
        ):
            with self.subTest(error=error):
                self.assertEqual(ran(accountdocker.asking(nothing, raising(error)), 'alice'), (code, b'', line))

    def test_a_daemon_that_does_not_open_is_told_and_no_work_runs(self):
        def opener(account, accounts_directory):
            raise Unavailable(f'container {account.name} is not running')
        command = accountdocker.asking(nothing, raising(AssertionError()), opener=opener)
        self.assertEqual(ran(command, 'alice'), (protocol.EXIT_UNAVAILABLE, b'', b'container alice is not running\n'))
        self.assertEqual(self.opened, [])


if __name__ == '__main__':
    unittest.main()
