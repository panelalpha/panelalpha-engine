"""The compose files the broker runs `docker compose` on: the engine's own, and each account's in `<engine>/users/`.

Every component down to the file must be owned by root or core, not a symlink and not writable by anyone else.
A home never qualifies, since a DinD tenant can leave root-owned files there.
"""

import functools
import os
import posixpath
import re
import stat

from . import composepolicy, ownership, paths, values
from .refused import Refused

FILE = 'docker-compose.yml'
ACCOUNTS = 'users'
ENGINE = 'engine'
ACCOUNT = 'account'
HOMES = 'homes'
STARTS = frozenset({'up', 'create', 'run', 'start', 'restart', 'build', 'pull'})
ENGINE_READS = re.compile(r'(?:docker-)?compose(?:\.override)?\.ya?ml(?:-.*)?|\.env')
NOT_A_PROJECT_NAME = re.compile(r'[^-_a-z0-9]')
ROOT_AND_ENGINE = 'root and the engine'
ROOT_ONLY = 'root'


def kind_of(path, engine):
    rest = paths.below(engine, path)
    if not rest:
        raise Refused(f"'{path}' is not a compose file the engine keeps")
    if rest == [FILE]:
        return ENGINE
    if len(rest) == 3 and rest[0] == ACCOUNTS and rest[2] == FILE:
        values.username(rest[1])
        return ACCOUNT
    raise Refused(f"'{path}' is not a compose file the engine keeps")


def check_owner(fd, path, owners, who=ROOT_AND_ENGINE):
    status = os.fstat(fd)
    if not ownership.written_only_by(status, owners):
        os.close(fd)
        raise Refused(f"'{path}' could have been written by someone other than {who}; once it is known to be the "
                      f"engine's own, run on the host: {ownership.fix(status, owners, path)}")
    return fd


def owned_file(fd, path, owners, who=ROOT_AND_ENGINE):
    """`fd` when it is a regular file owned as `owners` allow; closed and refused otherwise."""
    if not stat.S_ISREG(os.fstat(fd).st_mode):
        os.close(fd)
        raise Refused(f"'{path}' is not a file")
    return check_owner(fd, path, owners, who)


def open_file(directory, path):
    """The compose file in the opened `directory`, never a link; `path` names it in a refusal."""
    try:
        return paths.open_beneath(directory, FILE, paths.READ)
    except FileNotFoundError as e:
        raise Refused(f"'{path}' does not exist") from e
    except OSError as e:
        if e.errno not in paths.LINKED:
            raise
        raise Refused(f"'{path}' is a symlink or not a directory") from e


def walk(engine, relative, owners):
    """Opens and checks each component below the engine directory and returns the last one's fd."""
    walked = engine.rstrip('/')
    owned = functools.partial(check_owner, owners=owners)
    with paths.Walk(owned(os.open(engine, paths.DIRECTORY), walked), walked) as descent, descent.refusing():
        return descent.down(relative, check=owned).taken()


def place_file(placement, path, policy, words=()):
    """The options naming root's engine compose file, or an account one of the templates' shape, in its opened directory."""
    engine = policy.roots[ENGINE]
    kind = kind_of(path, engine)
    relative = paths.below(engine, path)
    directory = walk(engine, relative[:-1], policy.owners)
    try:
        content = read(owned_file(open_file(directory, path), path, policy.owners), path)
        if kind == ENGINE and starts(words):
            check_engine_start(directory, engine, policy)
    except BaseException:
        os.close(directory)
        raise
    in_directory(placement, directory, '/' + '/'.join(paths.components(path)[:-1]))
    if kind == ENGINE:
        return ['-f', './' + FILE]
    return account_options(placement, relative[1], content, policy, words)


def place_engine_directory(placement, path, policy, words=()):
    """`.` in the opened engine directory, for `--project-directory`."""
    engine = policy.roots[ENGINE]
    if paths.components(path) != paths.components(engine):
        raise Refused(f"'{path}' is not the engine's project directory")
    directory = walk(engine, [], policy.owners)
    try:
        if starts(words):
            check_engine_start(directory, engine, policy)
    except BaseException:
        os.close(directory)
        raise
    return in_directory(placement, directory, engine)


def starts(words):
    return bool(words) and words[0] in STARTS


def read(fd, path):
    try:
        content = os.read(fd, composepolicy.LARGEST + 1)
    finally:
        os.close(fd)
    if len(content) > composepolicy.LARGEST:
        raise Refused(f"'{path}' is larger than a compose file the engine writes")
    return content


def check_engine_start(directory, engine, policy):
    """The engine's directory and each file compose reads from it unasked, owned by root and writable by no one else."""
    os.close(check_owner(os.dup(directory), engine, policy.engine_owners, ROOT_ONLY))
    for name in sorted(os.listdir(directory)):
        if ENGINE_READS.fullmatch(name):
            check_engine_file(directory, name, engine.rstrip('/') + '/' + name, policy.engine_owners)


def check_engine_file(directory, name, path, owners):
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=directory)
    except FileNotFoundError:
        return
    if not stat.S_ISREG(os.fstat(fd).st_mode):
        os.close(fd)
        raise Refused(f"'{path}' is not a file")
    os.close(check_owner(fd, path, owners, ROOT_ONLY))


def account_options(placement, user, content, policy, words):
    """Pinned to the account's project, without its `.env`, and reading the bytes checked here rather than the file."""
    directory = placement.cwd_path
    if starts(words):
        engine = os.open(policy.roots[ENGINE], paths.DIRECTORY)
        try:
            privileged = composepolicy.privileged_runtime(engine, policy.engine_owners)
        finally:
            os.close(engine)
        options = ['-p', project_name(user), '--profile', '*', '--project-directory', directory]
        home = posixpath.normpath(policy.roots[HOMES].rstrip('/') + '/' + user)
        account = composepolicy.Account(user, project_name(user), directory, placement.cwd, home, privileged)
        composepolicy.check(policy.render(content, options), account)
    pinned = ['--env-file', '/dev/null', '-p', project_name(user), '--project-directory', '.']
    return [*pinned, '-f', snapshot(placement, content)]


def project_name(user):
    """The name compose gives a project from its directory, so pinning it changes nothing for a file that sets none."""
    return NOT_A_PROJECT_NAME.sub('', user.lower()).lstrip('_-')


def snapshot(placement, content):
    fd = os.memfd_create(FILE, os.MFD_CLOEXEC)
    try:
        os.write(fd, content)
    except BaseException:
        os.close(fd)
        raise
    placement.fds.append(fd)
    return f'/dev/fd/{fd}'


def in_directory(placement, fd, path):
    if placement.cwd is None:
        placement.cwd, placement.cwd_path = fd, path
        return '.'
    os.close(fd)
    raise Refused('one compose command names one project')


def is_account_file(path, policy):
    return kind_of(path, policy.roots[ENGINE]) == ACCOUNT
