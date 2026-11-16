"""The engine settings core may change while it runs as www-data that something outside core reads: the site
database's cache sizes in the engine's compose `.env`, which compose reads when it recreates sites-db. Every other
setting core reads at use lives in its database instead, and `.env-core` is never written here.

Every other key is refused, so COMPOSE_PROFILES, the database passwords and the rest stay root's. A value is one word
of the characters `.env` keeps unquoted, so it can start no line of its own. The file is rewritten in place, never
replaced, and its previous contents go to `.env.pae-backup`, with its mode and owner.
"""

import os
import re
import stat

from .. import composefiles, paths, values
from ..refused import Refused
from .failures import Failures
from .sequence import Command, reads, rest

COMPOSE = '.env'
BACKUP_SUFFIX = '.pae-backup'
LARGEST = 1 << 20

KEYS = frozenset({
    'SITES_DB_INNODB_BUFFER_POOL_SIZE', 'SITES_DB_KEY_BUFFER_SIZE', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE',
})

WORD = re.compile(r'[A-Za-z0-9_./@:,*+-]{0,16384}')
LIVE = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=')
COMMENTED = re.compile(r'^\s*#\s*([A-Za-z_][A-Za-z0-9_]*)\s*=')
FAILURES = Failures((Refused, '{e}', 2), (OSError, '{e}', 1))


def setting_value(value):
    return values.matching(WORD, 'setting value')(value)


def key_of(line):
    match = LIVE.match(line)
    return match.group(1) if match else None


def commented_key_of(line):
    match = COMMENTED.match(line)
    return match.group(1) if match else None


def unquoted(value):
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] == '"':
        return value[1:-1].replace('\\"', '"').replace('\\\\', '\\')
    if len(value) >= 2 and value[0] == value[-1] == "'":
        return value[1:-1]
    return value


def current(lines, key):
    """The value a live line sets `key` to, None when none does."""
    for line in lines:
        if key_of(line) == key:
            return unquoted(line[line.index('=') + 1:])
    return None


def applied(lines, key, value):
    """`key=value` over the live line, else over the commented placeholder, else appended after one blank line."""
    entry = f'{key}={value}'
    for finder in (key_of, commented_key_of):
        for index, line in enumerate(lines):
            if finder(line) == key:
                return lines[:index] + [entry] + lines[index + 1:]
    while lines and lines[-1].strip() == '':
        lines = lines[:-1]
    return lines + ['', entry, '']


def updated(content, settings):
    lines = content.split('\n')
    for key, value in settings:
        if current(lines, key) != value:
            lines = applied(lines, key, value)
    return '\n'.join(lines)


def check_keys(settings):
    for key, _ in settings:
        if key not in KEYS:
            raise Refused(f"{key!r} is not a setting of {COMPOSE} core may change")


def checked_directory(policy, directory):
    engine = policy.roots[composefiles.ENGINE]
    if paths.components(directory) != paths.components(engine):
        raise Refused(f"'{directory}' is not the engine's directory")
    return directory


def engine_directory(policy, directory):
    return composefiles.walk(checked_directory(policy, directory), [], policy.engine_owners)


def open_settings(directory, name, path, owners, flags=paths.READ):
    try:
        fd = os.open(name, flags, dir_fd=directory)
    except FileNotFoundError as e:
        raise Refused(f"'{path}' does not exist") from e
    except OSError as e:
        if e.errno not in paths.LINKED:
            raise
        raise Refused(f"'{path}' is a symlink") from e
    return composefiles.owned_file(fd, path, owners, composefiles.ROOT_ONLY)


def read_all(fd, path):
    os.set_blocking(fd, True)
    content = os.pread(fd, LARGEST + 1, 0)
    if len(content) > LARGEST:
        raise Refused(f"'{path}' is larger than a settings file the engine keeps")
    return content.decode()


def write_all(fd, data):
    os.ftruncate(fd, 0)
    offset = 0
    while offset < len(data):
        offset += os.pwrite(fd, data[offset:], offset)


def back_up(directory, name, source, content):
    status = os.fstat(source)
    fd = os.open(name + BACKUP_SUFFIX, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=directory)
    try:
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            raise Refused(f'{name}{BACKUP_SUFFIX} is not a file')
        os.fchmod(fd, 0o600)
        write_all(fd, content)
        os.fchown(fd, status.st_uid, status.st_gid)
        os.fchmod(fd, stat.S_IMODE(status.st_mode))
    finally:
        os.close(fd)


def set_settings(policy, directory, settings):
    path = f'{directory.rstrip("/")}/{COMPOSE}'
    engine = engine_directory(policy, directory)
    try:
        fd = open_settings(engine, COMPOSE, path, policy.engine_owners, os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            content = read_all(fd, path)
            changed = updated(content, settings)
            if changed != content:
                back_up(engine, COMPOSE, fd, content.encode())
                write_all(fd, changed.encode())
        finally:
            os.close(fd)
    finally:
        os.close(engine)


def print_settings(output, policy, directory, keys):
    path = f'{directory.rstrip("/")}/{COMPOSE}'
    engine = engine_directory(policy, directory)
    try:
        fd = open_settings(engine, COMPOSE, path, policy.engine_owners)
        try:
            lines = read_all(fd, path).split('\n')
        finally:
            os.close(fd)
    finally:
        os.close(engine)
    found = [(key, current(lines, key)) for key in keys]
    output.stdout(''.join(f'{key}={value}\n' for key, value in found if value is not None).encode())


def set_env(context, directory, words):
    """Sets the sites-db cache sizes in the engine's `.env` in place, keeping the old file as `.env.pae-backup`."""
    settings = pairs(words)
    check_keys(settings)
    checked_directory(context.policy, directory)
    return FAILURES.finishing(set_settings, context.policy, directory, settings)


def env_values(context, directory, keys):
    """`KEY=value` for each sites-db cache size a live line of the engine's `.env` sets, one a line."""
    check_keys([(key, '') for key in keys])
    checked_directory(context.policy, directory)
    return FAILURES.writing(print_settings, context.policy, directory, keys)


def pairs(words):
    if len(words) % 2:
        raise Refused('a setting without a value')
    return [(values.environment_name(key), setting_value(value)) for key, value in zip(words[::2], words[1::2])]


ACTIONS = {
    'engineSetEnv': Command(reads(values.path, rest(values.text)), set_env, native=True),
    'engineEnvValues': Command(reads(values.path, rest(values.environment_name)), env_values, native=True),
}
