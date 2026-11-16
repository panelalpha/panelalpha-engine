"""The `compose` area: App\\System\\Privileged\\RootCompose, RootEngineCompose and RootEngineServices, by method name.

An account's compose file may exec only into the account's own service. `up`, `down` and `build` stay compose; every
lookup is an Engine API call on the host's daemon, and the engine services' fixed commands are engineservices'.
"""

import functools

from .. import cli, composefiles, dockerapi, paths, values
from ..refused import Refused
from . import enginesettings, engineservices, hostapi
from .engineservices import SITES_HTTP, Service, engine_file
from .sequence import Command, flag, group, reads, rest

ACCOUNT_SERVICES = ('php', 'dind')


def account_service(value):
    if values.name(value) not in ACCOUNT_SERVICES:
        values.refuse("account's service", value)
    return value


def compose(context, file, *words):
    return ['docker', 'compose', *context.compose_file(file, words), *words]


def account_file(context, file):
    if not composefiles.is_account_file(file, context.policy):
        raise Refused(f"'{file}' is the engine's compose file; only an account's runs this")
    return file


def in_account(*words):
    """`docker compose <words>` of an account's compose file, checked as `account_file` checks it."""
    return Command(reads(values.path), lambda context, file: compose(context, account_file(context, file), *words))


def environment_options(environment):
    options = []
    for name, value in environment:
        options += ['-e', f'{name}={value}']
    return options


def account_user(context, file):
    """The account whose compose file it is, below the engine directory its walk stopped in."""
    return paths.components(file)[len(paths.components(context.policy.roots['engine'])) + 1]


def service_project(context, file, service, words, engine_services):
    """The service and the project its containers carry, None for the engine's, once the file is checked."""
    context.compose_file(file, words)
    if not composefiles.is_account_file(file, context.policy):
        if service not in engine_services:
            raise Refused(f"service {service!r} is not one this runs on in '{file}'")
        return None, service
    if service not in ACCOUNT_SERVICES:
        raise Refused(f"service {service!r} is not one this runs on in '{file}'")
    return composefiles.project_name(account_user(context, file)), service


def running_services(daemon, output, project):
    """The service names `docker compose ps --services --filter status=running` prints."""
    containers = Service(project).containers(daemon, running=True)
    names = {(container.get('Labels') or {}).get(dockerapi.SERVICE) for container in containers} - {None, ''}
    output.stdout(''.join(name + '\n' for name in sorted(names)).encode())
    return 0


def service_containers(daemon, output, project, service):
    """One `docker compose ps -a --format json` object a line, holding the labels its callers read."""
    for container in Service(project, service).containers(daemon):
        labels = container.get('Labels') or {}
        joined = ','.join(f'{name}={labels[name]}' for name in sorted(labels))
        output.stdout(cli.json_line({'Labels': joined}, ascii=True))
    return 0


def cpu_percent(daemon, output, project, service):
    """One `docker compose stats --no-stream --format {{.CPUPerc}}` line per container of the service."""
    return cli.printed(output, [f'{percent:.2f}%' for percent in Service(project, service).cpu_percents(daemon)])


def restart_containers(daemon, output, project, service):
    """`docker compose restart` of the service: every container of it, and nothing printed."""
    Service(project, service).restart(daemon)
    return 0


def account_running_services(context, file):
    """The account's own project, with its file checked as `account_file` checks it."""
    account_file(context, file)
    context.compose_file(file, ('ps', '--services', '--filter', 'status=running'))
    return (composefiles.project_name(account_user(context, file)),)


def engine_running_services(context, file):
    """None, for the engine's own project, with its file checked as `engine_file` checks it."""
    engine_file(context, file)
    context.compose_file(file, ('ps', '--services', '--filter', 'status=running'))
    return (None,)


read_exec_with_tty = reads(
    values.path, group(values.environment_name, values.text), values.optional(values.owner),
    values.optional(values.path), account_service, rest(values.text),
)


def exec_with_tty_in_account(context, file, environment, user, workdir, service, command):
    account_file(context, file)
    return compose(
        context, file, 'exec', *environment_options(environment), *(['-u', user] if user else []),
        *(['-w', workdir] if workdir else []), '--', service, *command,
    )


def engine_compose(context, directory, *words):
    return ['docker', 'compose', '--project-directory', context.engine_directory(directory, words), *words]


def service_lookup(work, *words, engine_services=(SITES_HTTP,), on_host=False):
    """`work` on a compose file's service, the file checked as compose checks it for `words`."""
    prepare = functools.partial(service_project, words=words, engine_services=engine_services)
    return hostapi.asking(reads(values.path, values.name), work, prepare, on_host=on_host)


ACTIONS = {
    'up': Command(
        reads(values.path, flag('force-recreate')),
        lambda context, file, recreate: compose(
            context, account_file(context, file), 'up', '-d', '--remove-orphans', *(['--force-recreate'] if recreate else []),
        ),
    ),
    'down': in_account('down'),
    'downWithVolumes': in_account('down', '-v', '--remove-orphans'),
    'build': in_account('build'),
    'execWithTty': Command(read_exec_with_tty, exec_with_tty_in_account, terminal=True),
    'engineUpWithoutDependencies': Command(
        reads(values.path, values.name),
        lambda context, directory, service: engine_compose(context, directory, 'up', '-d', '--no-deps', '--', service),
    ),
    'runningServices': hostapi.asking(reads(values.path), running_services, account_running_services),
    'engineRunningServices': hostapi.asking(reads(values.path), running_services, engine_running_services),
    'restart': service_lookup(restart_containers, 'restart', on_host=True),
    'serviceContainers': service_lookup(service_containers, 'ps', '-a', '--format', 'json'),
    'cpuPercent': service_lookup(cpu_percent, 'stats', '--no-stream', '--format', '{{.CPUPerc}}', engine_services=()),
    **engineservices.ACTIONS,
    **enginesettings.ACTIONS,
}
