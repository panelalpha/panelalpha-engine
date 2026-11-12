"""The `account` area: one action per App\\System\\Privileged\\AccountFiles, AccountCommands and AccountFileManager method.

Paths lie under the account's `home` and `account-engine` roots, plus the engine's stage directories and the
exchange directory. AccountFileManager paths are `as-given`, since the file manager follows links on purpose.
"""

import posixpath
import re

from .. import values
from ..paths import (AS_GIVEN, CREATE, CREATE_PRIVATE, ENTRY, EXISTING, MOVE_TARGET, NOFOLLOW, PARENTS, RESOLVE,
                     ROOT_ITSELF, TAGGED)
from ..refused import Refused
from . import ACCOUNT, USER, Action, Param, home, scripts
from .builders import entry_types, nitro_server_entries, on_paths, script
from .params import every, every_path, path, plain

HOME = ('home',)
HOMES = ('homes',)
ENGINE = ('engine',)
EXCHANGE = ('exchange',)
ACCOUNT_ENGINE = ('account-engine',)
STAGED = ('archive-stage',)
UNZIP_STAGE = ('unzip-stage',)
ZIP_STAGES = ('archive-stage', 'unzip-stage')
FILE_STREAM = '/usr/local/lib/panelalpha/pa-file-stream'
UTF8_UNZIP = ['env', 'LC_ALL=C.UTF-8', 'unzip']

GIT_OVERRIDES = ['-c', 'core.fsmonitor=false', '-c', 'log.showSignature=false']
GIT_SUBCOMMANDS = ('rev-parse', 'config', 'log')
GIT_OPTIONS = ('--is-inside-work-tree', '--is-shallow-repository', '--abbrev-ref', '--get', '-1')
GIT_ENVIRONMENT = ('GIT_ALLOW_PROTOCOL', 'GIT_TERMINAL_PROMPT', 'GIT_ASKPASS', 'SSH_ASKPASS', 'GIT_SSH_COMMAND')
SAFE_PATH = r'[A-Za-z0-9_./-]+'
CREDENTIAL_PREFIXES = ('.panelalpha-git-askpass-', '.panelalpha-git-ssh-')
SSH_COMMAND = re.compile(
    f'ssh -i ({SAFE_PATH}) -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes -o StrictHostKeyChecking=yes'
    f' -o UserKnownHostsFile=({SAFE_PATH}) -o GlobalKnownHostsFile=/dev/null'
)


def given(roots):
    return Param(values.path, AS_GIVEN, roots)


def as_account(params, build, **options):
    return Action((plain(values.account_id), plain(values.account_id), *params), build, identity=ACCOUNT, **options)


def disk_usage(given_values, placed, excluded):
    original = given_values[0].rstrip('/')
    exclusions = [placed.rstrip('/') + ex[len(original):] if within(ex, original) else ex for ex in given_values[1]]
    return ['du', '-shm', *['--exclude=' + ex for ex in exclusions], '--', placed]


def within(candidate, directory):
    return candidate == directory or candidate.startswith(directory + '/')


def git_read(arguments):
    subcommand = arguments[0] if arguments else ''
    if subcommand not in GIT_SUBCOMMANDS or (subcommand == 'config' and arguments[1:2] != ['--get']):
        raise Refused(f'git {" ".join(arguments)!r} is not a read this action runs')
    for argument in arguments[1:]:
        if argument not in GIT_OPTIONS and not (argument.startswith('--format=') and '%G' not in argument):
            values.operand(argument)
    return arguments


def git_on_checkout(checkout, arguments):
    return ['git', '-c', 'safe.directory=' + checkout, *GIT_OVERRIDES, '-C', checkout, *git_read(arguments)]


def owned_by_the_account(account, _path, owner):
    if owner != f'{account.uid}:{account.gid}':
        raise Refused(f'owner {owner!r} is not uid:gid of {account.name}')


def git_command(account, command):
    program = environment_end(command)
    if command[:1] != ['env'] or command[program:program + 1] != ['git']:
        raise Refused('the command must be `env NAME=value... git ...`')
    for assignment in command[1:program]:
        git_assignment(account, assignment)
    for index, word in enumerate(command[program:], program):
        if word == '-C' and index + 1 == len(command):
            raise Refused('-C names no directory')
        if word == '-C':
            git_directory(account, command[index + 1])


def environment_end(command):
    index = 1
    while index < len(command) and '=' in command[index] and command[index] != 'git':
        index += 1
    return index


def git_assignment(account, assignment):
    name, value = assignment.split('=', 1)
    if name not in GIT_ENVIRONMENT:
        raise Refused(f'{name} is not part of the git environment')
    if name == 'GIT_SSH_COMMAND':
        ssh_command(account, value)
    elif name == 'GIT_ASKPASS' and value != '/bin/false':
        credential_file(account, value)


def ssh_command(account, value):
    match = SSH_COMMAND.fullmatch(value)
    if match is None:
        raise Refused('GIT_SSH_COMMAND is not the engine\'s ssh line')
    for credential in match.groups():
        credential_file(account, credential)


def credential_file(account, value):
    if not re.fullmatch(SAFE_PATH, value) or '..' in value.split('/'):
        raise Refused(f'{value!r} is not a credential file the engine installs')
    directory, name = posixpath.split(value)
    beside_the_home = directory == posixpath.dirname(account.home)
    if not name.startswith(CREDENTIAL_PREFIXES) or not (within(directory, account.home) or beside_the_home):
        raise Refused(f'{value!r} is not a credential file the engine installs for {account.name}')


def git_directory(account, directory):
    if not within(posixpath.normpath(directory), account.home):
        raise Refused(f"'{directory}' is not below {account.home}")


def zip_command(zip_path, entry, level, from_date):
    return ['zip', '-r' + level, *(['--from-date', from_date] if from_date else []), zip_path, entry]


def resolve(pin):
    return ['--resolve', pin] if pin else []


ACCOUNT_FILES = {
    'write': as_account((path(ENTRY, HOME), plain(values.mode)), script(scripts.WRITE)),
    'makeDirectories': as_account((every_path(PARENTS, HOME),), on_paths('mkdir', '-p')),
    'removeIfExists': as_account((every_path(ENTRY, HOME),), on_paths('rm', '-f')),
    'changeMode': as_account(
        (plain(values.mode), every_path(NOFOLLOW, HOME)), lambda mode, ps: ['chmod', '--', mode, *ps],
    ),
    'changeModeRecursively': as_account(
        (plain(values.mode), every_path(NOFOLLOW, HOME)), lambda mode, ps: ['chmod', '-R', '--', mode, *ps],
    ),
    'moveOverwriting': as_account((path(ENTRY, HOME), path(MOVE_TARGET, HOME)), lambda a, b: ['mv', '-f', '--', a, b]),
    'replaceWithPreservedCopy': as_account(
        (path(ENTRY, HOME), path(ENTRY, HOME)),
        lambda a, b: ['cp', '-p', '--no-dereference', '--remove-destination', '--', a, b],
    ),
    'touch': as_account((every_path(CREATE, HOME),), on_paths('touch')),
    'read': as_account((path(NOFOLLOW, HOME),), lambda p: ['cat', '--', p]),
    'listAllNames': as_account((path(NOFOLLOW, HOME),), lambda p: ['ls', '-1A', '--', p]),
    'isSymlink': as_account((path(ENTRY, HOME),), lambda p: ['test', '-L', p]),
    'isRealDirectory': as_account((path(ENTRY, HOME),), script(scripts.REAL_DIRECTORY)),
    'diskUsageInMegabytes': as_account(
        (path(ENTRY, HOME), every(values.path)), disk_usage, with_values=True, rewrite=(b'\n', 0, True),
    ),
    'listEntryTypes': as_account((path(ENTRY, HOME),), entry_types),
    'nitroServerEntries': as_account(
        (path(ENTRY, HOME, trim=True), plain(values.count)), nitro_server_entries, rewrite=(b'\n', 0, False),
    ),
}

ACCOUNT_COMMANDS = {
    'listZipNames': Action((path(EXISTING, ZIP_STAGES),), lambda a: [*UTF8_UNZIP, '-Z1', a]),
    'listZipEntries': Action((path(EXISTING, ZIP_STAGES),), lambda a: [*UTF8_UNZIP, '-Z', a]),
    'readZipMember': Action(
        (path(EXISTING, ZIP_STAGES), plain(values.operand)), lambda a, name: [*UTF8_UNZIP, '-p', a, name],
    ),
    'renameInHome': Action((given(HOMES), given(HOMES), given(HOMES)), home.rename, native=True),
    'readInHome': Action((given(HOMES), given(HOMES)), home.read, native=True),
    'fileStream': as_account(
        (plain(values.fopen_mode), Param(values.path_to_resolve, RESOLVE, ('home', 'account-engine')),
         path(NOFOLLOW, ('home', 'account-engine'))),
        lambda given, mode, p, root: [FILE_STREAM, mode, p, given[2], root], with_values=True,
    ),
    'gitOnAccountCheckout': as_account((given(HOME), every(values.word)), git_on_checkout),
    'gitOnHost': Action(
        (plain(values.username), every(values.word)), lambda command: command,
        identity=USER, host_only=True, verify=git_command,
    ),
    'copyReplacingAsAccount': as_account(
        (path(ENTRY, HOME), path(ENTRY, HOME)), lambda a, b: ['cp', '-P', '--remove-destination', '--', a, b],
    ),
    'extractZip': as_account((path(NOFOLLOW, STAGED), path(NOFOLLOW, HOME)), script(scripts.EXTRACT_ZIP)),
    'extractTar': as_account(
        (path(NOFOLLOW, STAGED), path(NOFOLLOW, HOME)),
        lambda a, d: ['tar', '--no-same-owner', '--no-same-permissions', '-xzf', a, '-C', d],
    ),
    'normaliseModes': as_account((path(NOFOLLOW, HOME),), lambda d: ['chmod', '-R', 'u+rwX,go=rX', '--', d]),
    'readRegularFile': as_account((path(NOFOLLOW, HOME),), script(scripts.READ_REGULAR_FILE)),
    'extractTarFromStdin': as_account(
        (path(NOFOLLOW, HOME),), lambda d: ['tar', '-xzpf', '-', '--no-same-owner', '-C', d],
    ),
    'mergeTree': as_account(
        (path(NOFOLLOW, HOME), path(NOFOLLOW, HOME), every(values.entry_name)), script(scripts.COPY_THROUGH_TAR, 'bash'),
    ),
    'placeFile': as_account(
        (path(ROOT_ITSELF, HOME), plain(values.relative_path), plain(values.octal_mode), plain(values.entry_name)),
        script(scripts.WRITE_FILE),
    ),
    'removeFileBelow': as_account(
        (path(ROOT_ITSELF, HOME), plain(values.relative_path), plain(values.entry_name)), script(scripts.REMOVE_FILE),
    ),
    'ensureDirectoryBelow': as_account(
        (path(ROOT_ITSELF, HOME), plain(values.relative_path), plain(values.octal_mode)), script(scripts.ENTER_DIRECTORY),
    ),
    'lastLinesOfLog': as_account((path(ENTRY, ACCOUNT_ENGINE), plain(values.count)), script(scripts.TAIL_LOG)),
    'ensureLogFile': as_account(
        (path(ENTRY, ACCOUNT_ENGINE), plain(values.owner_ids)), script(scripts.ENSURE_LOG_FILE),
        verify=owned_by_the_account,
    ),
    'selfSignedCertificate': Action(
        (path(CREATE_PRIVATE, ENGINE, writes=True), path(CREATE, ENGINE, writes=True), plain(values.certificate_subject),
         plain(values.hostname)),
        lambda key, cert, subject, domain: [
            'openssl', 'req', '-x509', '-newkey', 'rsa:4096', '-keyout', key, '-out', cert, '-sha256', '-days', '365',
            '-nodes', '-subj', subject, '-addext', 'subjectAltName=DNS:' + domain,
        ],
    ),
}

ACCOUNT_FILE_MANAGER = {
    'makeFolder': as_account(
        (given(HOME), plain(values.flag)),
        lambda p, parents: script(scripts.MKDIR_SCRIPT)(p, ['-p', '--'] if parents == '1' else ['--'], p),
    ),
    'writeCopy': as_account((given(EXCHANGE), given(HOME)), script(scripts.WRITE_AS_USER_SCRIPT)),
    'exists': as_account((given(HOME),), lambda p: ['test', '-e', p]),
    'isFile': as_account((given(HOME),), lambda p: ['test', '-f', p]),
    'resolvedFile': as_account((Param(values.path_to_resolve, RESOLVE, HOME),), script(scripts.RESOLVED_FILE_SCRIPT)),
    'entryFields': as_account((given(HOME),), lambda p: ['find', p, '-maxdepth', '0', '-printf', scripts.ENTRY_FIELDS]),
    'listPage': as_account(
        (given(HOME), plain(values.count), plain(values.flag)),
        lambda d, limit, hidden: script(scripts.LIST_PAGE_SCRIPT)(
            d, limit, [] if hidden == '1' else ['!', '-name', '.*'],
        ),
    ),
    'subdirectoryParents': as_account(
        (given(HOME),), lambda d: ['find', d, '-mindepth', '2', '-maxdepth', '2', '-type', 'd', '-printf', '%h\\0'],
    ),
    'moveEntry': as_account((given(HOME), given(HOME)), lambda a, b: ['mv', a, b]),
    'copyEntry': as_account((given(HOME), given(HOME)), lambda a, b: ['cp', '-a', a, b]),
    'zip': as_account(
        (given(HOME), given(HOME), plain(values.optional(values.count)), plain(values.optional(values.operand))),
        zip_command,
    ),
    'zipFrom': as_account(
        (given(HOME), given(HOME), plain(lambda name: values.operand(values.entry_name(name))),
         plain(values.optional(values.count)), plain(values.optional(values.operand))),
        lambda workdir, *rest: ['env', '--chdir=' + workdir, *zip_command(*rest)],
    ),
    'copyArchiveToStage': as_account((given(HOME), given(UNZIP_STAGE)), script(scripts.STAGE_COPY_SCRIPT)),
    'unzipInto': as_account((given(UNZIP_STAGE), given(UNZIP_STAGE)), script(scripts.EXTRACT_ZIP)),
    'untarInto': as_account((given(UNZIP_STAGE), given(UNZIP_STAGE)), lambda a, d: ['tar', '-zxvf', a, '-C', d]),
    'gunzipTo': as_account((given(UNZIP_STAGE), given(UNZIP_STAGE)), script(scripts.GUNZIP_SCRIPT)),
    'moveUnpackedInto': as_account(
        (given(UNZIP_STAGE), path(ROOT_ITSELF, HOME), given(HOME)), script(scripts.MOVE_UNPACKED_SCRIPT),
    ),
    'removeEntry': as_account(
        (given(HOME), plain(values.flag)), lambda p, recursive: ['rm', '-f', *(['-r'] if recursive == '1' else []), p],
    ),
    'diskUsageExcluding': as_account(
        (given(HOME), plain(values.path)), lambda p, ex: ['du', '-shm', '--exclude=' + ex, p],
    ),
    'moveEntriesInto': as_account(
        (given(HOME), given(HOME), plain(values.flag)),
        lambda a, b, override: [
            'find', a, '-mindepth', '1', '-maxdepth', '1',
            '-exec', 'mv', '--force' if override == '1' else '--no-clobber', '-t', b, '--', '{}', '+',
        ],
    ),
    'renameOver': as_account((given(HOME), given(HOME)), lambda a, b: ['mv', '-fT', '--', a, b]),
    'removeFile': as_account((given(HOME),), lambda p: ['rm', '-f', '--', p]),
    'download': as_account(
        (plain(values.url), given(HOME), plain(values.count), plain(values.optional(values.operand))),
        lambda url, d, limit, pin: [
            'curl', '-fsS', '--max-filesize', limit, '-o', d, '-w', '%{http_code}', *resolve(pin), url,
        ],
    ),
    'redirectLocation': as_account(
        (plain(values.url), plain(values.optional(values.operand))),
        lambda url, pin: ['curl', '-sS', '-o', '/dev/null', '-w', '%{redirect_url}', *resolve(pin), url],
    ),
    'setMode': as_account((given(HOME), plain(values.octal_mode)), lambda p, mode: ['chmod', mode, p]),
    'confine': as_account(
        (path(ROOT_ITSELF, HOME), Param(values.tagged_path, TAGGED, HOME, many=True)), script(scripts.CONFINE_SCRIPT),
    ),
    'entryType': as_account((given(HOME),), lambda p: script(scripts.ENTRY_TYPE_SCRIPT)(p, p.rstrip('/'))),
}

ACTIONS = {**ACCOUNT_FILES, **ACCOUNT_COMMANDS, **ACCOUNT_FILE_MANAGER}
