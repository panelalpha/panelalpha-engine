"""The `files` area: one action per App\\System\\Privileged\\RootFiles method, under the same name.

Each path's mode follows whether its tool follows a final symlink and whether it creates a missing one. A `--host`
request may use only `homes` and `backup`, and a write below `engine` only the parts in ENGINE_WRITES.
"""

from .. import realpaths, values
from ..paths import (COPY_SOURCE, COPY_TARGET, CREATE, ENTRY, EXISTING, FOLLOW, MOVE_TARGET, NOFOLLOW, PARENTS, RESOLVE,
                     Onto)
from . import Action, Param
from .builders import entry_types, nitro_server_entries, on_paths, script
from .params import every, every_path, path, plain

HOMES = ('homes',)
ENGINE = ('engine',)
HOMES_ENGINE = ('homes', 'engine')
ENGINE_LOG = ('engine-log',)

FIND_REMOVE = ['-exec', 'rm', '-rf', '--', '{}', '+']

# install -m on descriptors: install itself unlinks its target, then chmods it by name.
INSTALL = r'''cp -- "$2" "$3" && chmod -- "$1" "$3"'''

FIRST_ACCOUNT_ID = 1000


def written(mode, roots, trim=False):
    return path(mode, roots, trim=trim, writes=True)


def all_written(mode, roots):
    return every_path(mode, roots, writes=True)


def all_made(mode, roots):
    return every_path(mode, roots, creates=True)


def excludes(patterns):
    return ['--exclude=' + pattern for pattern in patterns]


def protects(patterns):
    return ['--filter=P ' + pattern for pattern in patterns]


niceness = values.optional(values.one_of(tuple(str(n) for n in range(20)), 'niceness'))
io_class = values.optional(values.one_of(('2', '3'), 'IO class'))


def at_priority(nice, io_class):
    """`nice` and `ionice` lowering what follows; an empty value leaves that one as it is."""
    return [*(['nice', '-n', nice] if nice else []), *(['ionice', '-c', io_class] if io_class else [])]


def contents(operand):
    return operand if operand.endswith('/') else operand + '/'


def any_name_starting_with(prefixes):
    tests = []
    for prefix in prefixes:
        tests += (['-o'] if tests else []) + ['-name', prefix + '*']
    return ['(', *tests, ')']


def name_starting_with(prefix):
    values.name_pattern(prefix + '*')
    return prefix


def account_id(value):
    """An account's uid or gid, never root or a system account."""
    if int(values.account_id(value)) < FIRST_ACCOUNT_ID:
        values.refuse('account id', value)
    return value


def hand_tree_to_account(root, uid, gid, keep_docker):
    pruned = ['docker/*'] if keep_docker == '1' else []
    argv = ['find', root, '-mindepth', '1']
    for name in pruned:
        argv += ['-not', '(', '-path', root + '/' + name, '-prune', ')']
    return [*argv, '-exec', 'chown', '-h', f'{uid}:{gid}', '{}', '+']


def move(*options):
    """mv into the target only while the broker holds it open as a directory, else onto it with -T, so a link to a
    directory planted there after the check is never moved into."""
    return lambda source, target: ['mv', *options, *(['-T'] if isinstance(target, Onto) else []), '--', source, target]


def mirror(*options, rules=excludes):
    return lambda source, target, *rest: [
        'rsync', '-a', '--delete', *options, *(rules(rest[0]) if rest else []), '--', contents(source), contents(target),
    ]


def existing_real_paths(policy, given):
    """The real path of each given path in the homes that exists, resolved as the host sees it."""
    return realpaths.existing(policy.roots_for(HOMES, False), given)


ACTIONS = {
    'remove': Action((all_written(ENTRY, ENGINE),), on_paths('rm')),
    'removeIfExists': Action((all_written(ENTRY, HOMES_ENGINE),), on_paths('rm', '-f')),
    'removeTree': Action(
        (all_written(ENTRY, ('homes', 'engine', 'backup', 'archive-stage', 'coverage')),), on_paths('rm', '-rf'),
    ),
    'removeEmptyDirectory': Action((every_path(ENTRY, HOMES),), on_paths('rmdir')),
    'makeDirectory': Action((all_made(ENTRY, HOMES_ENGINE),), on_paths('mkdir')),
    'makeDirectoryWithMode': Action(
        (plain(values.mode), path(ENTRY, HOMES)), lambda mode, p: ['mkdir', '-m', mode, '--', p],
    ),
    'makeDirectories': Action(
        (all_made(PARENTS, ('homes', 'engine', 'backup', 'coverage')),), on_paths('mkdir', '-p'),
    ),
    'makeDirectoriesWithMode': Action(
        (plain(values.mode), every_path(PARENTS, HOMES)), lambda mode, ps: ['mkdir', '-p', '-m', mode, '--', *ps],
    ),
    'move': Action(
        (written(ENTRY, HOMES_ENGINE), written(MOVE_TARGET, HOMES_ENGINE)), move(), linkless=(0, 1),
    ),
    'moveOverwriting': Action(
        (path(ENTRY, HOMES), path(MOVE_TARGET, ('homes', 'backup'))), move('-f'),
    ),
    'rename': Action(
        (written(ENTRY, HOMES_ENGINE), written(ENTRY, HOMES_ENGINE)), lambda a, b: ['mv', '-T', '--', a, b],
        linkless=(0, 1),
    ),
    'copy': Action(
        (path(COPY_SOURCE, ('exchange', 'engine', 'backup', 'core-sources')),
         written(COPY_TARGET, ('exchange', 'engine', 'homes'))),
        lambda a, b: ['cp', '--', a, b],
    ),
    'replaceWithPreservedCopy': Action(
        (path(ENTRY, HOMES), path(ENTRY, HOMES)),
        lambda a, b: ['cp', '-p', '--no-dereference', '--remove-destination', '--', a, b],
    ),
    'copyTree': Action((path(ENTRY, HOMES), path(NOFOLLOW, HOMES)), lambda a, b: ['cp', '-a', '-T', '--', a, b]),
    'install': Action((plain(values.mode), path(COPY_SOURCE, ('exchange',)), path(COPY_TARGET, HOMES)), script(INSTALL)),
    'changeMode': Action(
        (plain(values.mode), all_written(EXISTING, HOMES_ENGINE)), lambda mode, ps: ['chmod', '--', mode, *ps],
    ),
    'changeModeRecursively': Action(
        (plain(values.mode), all_written(EXISTING, HOMES_ENGINE)), lambda mode, ps: ['chmod', '-R', '--', mode, *ps],
    ),
    'changeOwner': Action(
        (plain(values.owner), all_written(EXISTING, HOMES_ENGINE)), lambda owner, ps: ['chown', '--', owner, *ps],
    ),
    'changeOwnerRecursively': Action(
        (plain(values.owner), all_written(ENTRY, ('homes', 'engine', 'backup', 'core-storage', 'coverage'))),
        lambda owner, ps: ['chown', '-R', '--', owner, *ps],
    ),
    'handTreeToAccount': Action(
        (path(ENTRY, HOMES), plain(account_id), plain(account_id), plain(values.flag)), hand_tree_to_account,
    ),
    'touch': Action((all_written(CREATE, ('engine', 'coverage')),), on_paths('touch')),
    'read': Action((path(EXISTING, ('engine', 'engine-log', 'letsencrypt')),), lambda p: ['cat', '--', p]),
    'lastLines': Action((plain(values.count), path(EXISTING, ENGINE_LOG)), lambda n, p: ['tail', '-n', n, '--', p]),
    'exists': Action((path(FOLLOW, HOMES),), lambda p: ['test', '-e', p]),
    'isFile': Action((path(FOLLOW, ('homes', 'engine', 'core-sources')),), lambda p: ['test', '-f', p]),
    'isDirectory': Action(
        (path(FOLLOW, ('homes', 'engine', 'engine-log', 'core-sources')),), lambda p: ['test', '-d', p],
    ),
    'isSymlink': Action((path(ENTRY, HOMES_ENGINE),), lambda p: ['test', '-L', p]),
    'deviceNumbers': Action((every_path(ENTRY, HOMES),), on_paths('stat', '-c', '%d')),
    'modificationTime': Action((path(ENTRY, ENGINE_LOG),), lambda p: ['stat', '-c', '%Y', '--', p]),
    'permissionBits': Action((path(ENTRY, HOMES),), lambda p: ['stat', '-c', '%a', '--', p]),
    'existingRealPaths': Action(
        (Param(values.path_to_resolve, RESOLVE, HOMES, many=True),), existing_real_paths, native=True,
    ),
    'listNames': Action((path(EXISTING, ('homes', 'letsencrypt')),), lambda p: ['ls', '-1', '--', p]),
    'listAllNames': Action((path(EXISTING, HOMES_ENGINE),), lambda p: ['ls', '-1A', '--', p]),
    'mirror': Action(
        (path(EXISTING, HOMES_ENGINE, trim=True), written(NOFOLLOW, HOMES_ENGINE, trim=True)), mirror(), linkless=(0, 1),
    ),
    'mirrorByContent': Action(
        (path(EXISTING, HOMES, trim=True), path(NOFOLLOW, HOMES, trim=True)), mirror('--checksum', '--no-times'),
    ),
    'mirrorByContentProtecting': Action(
        (path(EXISTING, HOMES, trim=True), path(NOFOLLOW, HOMES, trim=True), every(values.operand)),
        mirror('--checksum', '--no-times', rules=protects),
    ),
    'mirrorExcluding': Action(
        (path(EXISTING, HOMES, trim=True), path(NOFOLLOW, HOMES, trim=True), every(values.operand)), mirror(),
    ),
    'createArchive': Action(
        (path(EXISTING, HOMES), path(CREATE, ('backup',)), plain(values.archive_entry), every(values.archive_pattern)),
        lambda d, archive, entry, patterns: ['tar', '-C', d, '-czf', archive, *excludes(patterns), '--', entry],
        use_cwd=False,
    ),
    'createArchiveAtPriority': Action(
        (
            path(EXISTING, HOMES), path(CREATE, ('backup',)), plain(niceness), plain(io_class),
            plain(values.archive_entry), every(values.archive_pattern),
        ),
        lambda d, archive, nice, io, entry, patterns: [
            *at_priority(nice, io), 'tar', '-C', d, '-czf', archive, *excludes(patterns), '--', entry,
        ],
        use_cwd=False,
    ),
    'extractArchive': Action(
        (path(EXISTING, ('backup',)), path(EXISTING, HOMES), plain(values.count)),
        lambda archive, d, strip: ['tar', '-xzf', archive, '-C', d, *([] if strip == '0' else [f'--strip-components={strip}'])],
        use_cwd=False,
    ),
    'diskUsageInMegabytes': Action((path(ENTRY, HOMES),), lambda p: ['du', '-shm', '--', p], rewrite=(b'\n', 0, True)),
    'diskUsageInBytes': Action((path(ENTRY, HOMES),), lambda p: ['du', '-sb', '--', p], rewrite=(b'\n', 0, True)),
    'diskFree': Action((path(FOLLOW, HOMES),), lambda p: ['df', '-P', '--', p]),
    'listEntryTypes': Action((path(ENTRY, HOMES),), entry_types),
    'relativeFilePaths': Action(
        (path(ENTRY, ('homes', 'engine', 'core-sources')),), lambda d: ['find', d, '-type', 'f', '-printf', '%P\\n'],
    ),
    'firstEntry': Action(
        (path(ENTRY, HOMES),), lambda d: ['find', d, '-mindepth', '1', '-maxdepth', '1', '-print', '-quit'],
        rewrite=(b'\n', 0, False),
    ),
    'symlinksIn': Action(
        (path(ENTRY, HOMES),), lambda d: ['find', d, '-mindepth', '1', '-maxdepth', '1', '-type', 'l'],
        rewrite=(b'\n', 0, False),
    ),
    'findInDirectoryIgnoringCase': Action(
        (path(ENTRY, ENGINE), plain(values.name_pattern)), lambda d, name: ['find', d, '-maxdepth', '1', '-iname', name],
        rewrite=(b'\n', 0, False),
    ),
    'emptyDirectory': Action(
        (path(ENTRY, HOMES),), lambda d: ['find', d, '-mindepth', '1', '-maxdepth', '1', *FIND_REMOVE],
    ),
    'removeEntriesNamed': Action(
        (path(ENTRY, HOMES), plain(values.name_pattern)),
        lambda d, name: ['find', d, '-mindepth', '1', '-maxdepth', '1', '-name', name, *FIND_REMOVE],
    ),
    'removeEntriesOlderThan': Action(
        (path(ENTRY, HOMES), plain(values.count)),
        lambda d, minutes: ['find', d, '-mindepth', '1', '-maxdepth', '1', '-mmin', '+' + minutes, *FIND_REMOVE],
    ),
    'moveEntriesInto': Action(
        (path(ENTRY, HOMES), path(EXISTING, HOMES)),
        lambda d, to: ['find', d, '-mindepth', '1', '-maxdepth', '1', '-exec', 'mv', '-t', to, '--', '{}', '+'],
    ),
    'deleteFilesTwoLevelsDownOlderThan': Action(
        (written(ENTRY, ENGINE), plain(values.count)),
        lambda d, minutes: ['find', d, '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '-mmin', '+' + minutes, '-delete'],
    ),
    'staleDirectoriesStartingWith': Action(
        (path(ENTRY, ('homes', 'archive-stage')), plain(name_starting_with), plain(values.count)),
        lambda d, prefix, minutes: [
            'find', d, '-mindepth', '1', '-maxdepth', '1', '-type', 'd', '-name', prefix + '*', '-mmin', '+' + minutes, '-print',
        ],
        rewrite=(b'\n', 0, False),
    ),
    'staleFilesStartingWith': Action(
        (path(ENTRY, HOMES, trim=True), plain(values.optional(values.count)), plain(values.count), every(name_starting_with)),
        lambda root, depth, minutes, prefixes: [
            'find', root, '-ignore_readdir_race', *(['-xdev'] if depth == '' else ['-maxdepth', depth]),
            '-type', 'f', *any_name_starting_with(prefixes), '-mmin', '+' + minutes, '-print0',
        ],
        rewrite=(b'\0', 0, False),
    ),
    'containerLogSizes': Action(
        (path(ENTRY, HOMES),),
        lambda d: ['find', d, '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '-name', '*-json.log*', '-printf', '%s\\n'],
    ),
    'parentsOfDirectoriesTwoLevelsDownNamed': Action(
        (path(ENTRY, HOMES), plain(values.name_pattern)),
        lambda d, name: ['find', d, '-mindepth', '2', '-maxdepth', '2', '-type', 'd', '-name', name, '-printf', '%h\\n'],
        rewrite=(b'\n', 0, False),
    ),
    'nitroServerEntries': Action(
        (path(ENTRY, HOMES, trim=True), plain(values.count)), nitro_server_entries, rewrite=(b'\n', 0, False),
    ),
}
