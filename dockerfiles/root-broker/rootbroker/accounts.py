"""Accounts from the host's passwd (`/proc/1/root/etc/passwd`), and becoming one.

An account has one passwd entry, uid 1000 or above, its primary gid, and a home directly below the homes root.
"""

import os
import posixpath
from dataclasses import dataclass

from .refused import Refused

PASSWD = '/proc/1/root/etc/passwd'
FIRST_UID = 1000
NOBODY = 65534
SECURE_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin'


@dataclass(frozen=True)
class Account:
    name: str
    uid: int
    gid: int
    home: str
    shell: str

    def sudo_environment(self):
        return {
            'PATH': SECURE_PATH, 'HOME': self.home, 'SHELL': self.shell, 'USER': self.name, 'LOGNAME': self.name,
            'MAIL': f'/var/mail/{self.name}',
        }


class Accounts:
    def __init__(self, passwd=PASSWD, homes='/home'):
        self.passwd = passwd
        self.homes = homes

    def entries(self):
        try:
            with open(self.passwd, encoding='utf-8', errors='surrogateescape') as f:
                lines = f.read().splitlines()
        except OSError as e:
            raise Refused(f'the host account database is not readable: {e.strerror}') from e
        return [entry for entry in map(parse, lines) if entry is not None]

    def by_ids(self, uid_text, gid_text):
        uid, gid = int(uid_text), int(gid_text)
        if uid == 0 or gid == 0:
            raise Refused('uid and gid 0 are root, never an account')
        account = next((entry for entry in self.entries() if entry.uid == uid), None)
        if account is None:
            raise Refused(f'uid {uid} is no account')
        if account.gid != gid:
            raise Refused(f'gid {gid} is not the primary group of uid {uid}')
        return self.checked(account)

    def by_name(self, name):
        account = next((entry for entry in self.entries() if entry.name == name), None)
        if account is None:
            raise Refused(f'{name!r} is no account')
        return self.checked(account)

    def checked(self, account):
        if account.uid < FIRST_UID or account.uid == NOBODY or account.name == 'nobody':
            raise Refused(f'uid {account.uid} ({account.name}) is not a hosting account')
        if posixpath.dirname(posixpath.normpath(account.home)) != posixpath.normpath(self.homes):
            raise Refused(f'the home of {account.name}, {account.home!r}, is not directly below {self.homes}')
        return account


def parse(line):
    fields = line.split(':')
    if len(fields) != 7 or not fields[2].isdigit() or not fields[3].isdigit():
        return None
    return Account(fields[0], int(fields[2]), int(fields[3]), fields[5], fields[6])


def become(account, own_groups=False):
    if own_groups:
        os.initgroups(account.name, account.gid)
    else:
        os.setgroups([])
    os.setresgid(account.gid, account.gid, account.gid)
    os.setresuid(account.uid, account.uid, account.uid)
