"""The engine's own services on the host's daemon: their containers, and the fixed commands core runs in them.

`core` and `metrics` in the engine's compose file are root on the host, so an engine service runs only the commands
below, each in the service's running container through the host's Engine API, as `docker compose exec` ran it.
"""

import hashlib
import json
import re
import urllib.parse
from dataclasses import dataclass

from .. import cli, composefiles, dockerapi, hostdaemon, values
from ..dockerapi import Failed, quoted
from ..refused import Refused
from . import builders, hostapi
from .sequence import flag, nothing, reads, rest
from .shell import escapeshellarg

SITES_HTTP, MAIL, FTP, SFTP, LIGHTHOUSE, SITES_DB = 'sites-http', 'mail', 'ftp', 'sftp', 'lighthouse', 'sites-db'

FTP_PASSWORD = 'PA_FTP_PASSWORD'
FTP_PASSWORD_PIPE = 'printf \'%s\\n%s\\n\' "$' + FTP_PASSWORD + '" "$' + FTP_PASSWORD + '" | '
LITESPEED_PASSWORD = 'PA_LITESPEED_ADMIN_PASSWORD'
SITES_DB_PASSWORD = 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; '
IMPORT_LOCK_DIR = '/run/lock'
# a definer never spans a quote or a backslash, so rewriting one inside a string cannot move where the string ends
DEFINER_PART = r'''\([^`'"\\]\|``\)*'''
ANY_DEFINER = 'DEFINER=`' + DEFINER_PART + '`\\(@`' + DEFINER_PART + '`\\)\\{0,1\\}'
PREG_SPECIAL = set('.\\+*?[^]$(){}=!<>|:-#/')

IMPORT_SCRIPT = r'''set -o pipefail
A=@ACCOUNT@
query() { MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb -uroot -N -B -e "$1" </dev/null; }
own() {
    local method
    method=$(query "SELECT CONCAT(JSON_VALUE(priv, '\$.plugin'), ' ', JSON_VALUE(priv, '\$.authentication_string')) FROM mysql.global_priv WHERE user = '$A' AND host = '%'") || return 2
    [[ $method =~ ^mysql_native_password\ (\*[0-9A-F]{40})?$ ]] || return 1
    H=${BASH_REMATCH[1]}
}
restore() {
    local status=$?
    trap - EXIT
    trap '' HUP INT TERM
    own
    case $? in
        0) query "ALTER USER '$A'@'%' IDENTIFIED VIA mysql_native_password USING '$H'" && exit $status ;;
        1) exit $status ;;
    esac
    echo "the temporary sign-in of '$A'@'%' was not taken back; the next import as it takes it back" >&2
    exit 1
}
exec 9>@LOCK@ && flock -w 600 9 || { echo "another import as '$A'@'%' is still running" >&2; exit 1; }
own || { echo "refused: '$A'@'%' does not exist or does not sign in with mysql_native_password" >&2; exit 1; }
T=$(tr -dc A-Za-z0-9 </dev/urandom | head -c 32)
trap restore EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
printf "ALTER USER '%s'@'%%' IDENTIFIED VIA mysql_native_password USING '%s' OR mysql_native_password USING PASSWORD('%s')" "$A" "$H" "$T" | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb -uroot || exit
@STREAM@ | MYSQL_PWD="$T" mariadb --sandbox --user="$A" -- @DATABASE@'''

MODSECURITY_CHECK_SCRIPT = '''T=$1
nginx -t -q -c "$T/nginx.conf" || exit
timeout -k 1 20 nginx -c "$T/nginx.conf" &
pid=$!
trap 'kill $pid 2>/dev/null; wait $pid 2>/dev/null' EXIT
trap 'exit 1' HUP INT TERM
i=0
until [ -S "$T/check.sock" ]; do
    kill -0 $pid 2>/dev/null || exit 1
    i=$((i + 1)); [ $i -lt 250 ] || { echo 'the check server did not start' >&2; exit 1; }
    sleep 0.02
done
ask() { curl -s -o /dev/null -w '%{http_code}' --max-time 5 --unix-socket "$T/check.sock" "$@"; }
for p in first phase1 phase2 phase3; do
    echo "modsecurity-check $p $(ask "http://localhost$2$p")"
done
echo "modsecurity-check body $(ask --data "$3=1" "http://localhost$2body")"
plain() { ask -A 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0' \\
    -H 'Accept: text/html,*/*;q=0.8' -H 'Accept-Language: en' http://localhost/; }
echo "modsecurity-check plain $(plain)"'''

LITESPEED_ADMIN = '/usr/local/lsws/admin'
LITESPEED_PASSWORD_SCRIPT = f'''if [ -e {LITESPEED_ADMIN}/fcgi-bin/admin_php ]; then
  echo "admin:$({LITESPEED_ADMIN}/fcgi-bin/admin_php -q {LITESPEED_ADMIN}/misc/htpasswd.php "${LITESPEED_PASSWORD}")" > {LITESPEED_ADMIN}/conf/htpasswd;
else
  echo "admin:$({LITESPEED_ADMIN}/fcgi-bin/admin_php5 -q {LITESPEED_ADMIN}/misc/htpasswd.php "${LITESPEED_PASSWORD}")" > {LITESPEED_ADMIN}/conf/htpasswd;
fi'''

# also kills an `exim4 -bd` the init script missed, which holds 127.0.0.1:25 without a pid file
EXIM_RESTART_SCRIPT = '''service exim4 stop || true
daemons() {
    for p in /proc/[0-9]*; do
        [ "$(cat "$p/comm" 2>/dev/null)" = exim4 ] || continue
        case " $(tr '\\0' ' ' < "$p/cmdline" 2>/dev/null)" in
            *" -bd"*) echo "${p#/proc/}" ;;
        esac
    done
}
left=$(daemons)
[ -n "$left" ] && kill $left 2>/dev/null
for _ in $(seq 20); do [ -z "$(daemons)" ] && break; sleep 0.5; done
left=$(daemons)
[ -n "$left" ] && kill -9 $left 2>/dev/null
service exim4 start'''

SITES_DB_CACHE_SIZES = ('mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e '
                        '"select @@innodb_buffer_pool_size, @@key_buffer_size, @@aria_pagecache_buffer_size"')


@dataclass(frozen=True)
class Service:
    """A compose project's service on the host's daemon, or the whole project without a `name`; a None `project` is
    the engine's, the one the broker's own container is in."""

    project: str | None
    name: str | None = None

    def containers(self, daemon, running=False):
        """`/containers/json` of the project, or of its service, under the labels compose sets on every container."""
        project = hostdaemon.engine_project(daemon) if self.project is None else self.project
        filters = dockerapi.compose_filters(project, running=running)
        if self.name is not None:
            filters['label'].append(f'{dockerapi.SERVICE}={self.name}')
        query = {'filters': json.dumps(filters), **({} if running else {'all': '1'})}
        found = daemon.json('GET', '/containers/json?' + urllib.parse.urlencode(query), missing=())
        return found if isinstance(found, list) else []

    def ids(self, daemon):
        """Every container's id, failing as compose did when the service has none."""
        found = [container['Id'] for container in self.containers(daemon) if isinstance(container.get('Id'), str)]
        if not found:
            raise Failed(f'service "{self.name}" is not running')
        return found

    def restart(self, daemon):
        for container in self.ids(daemon):
            daemon.expect('POST', f'/containers/{quoted(container)}/restart', (200, 204))

    def cpu_percents(self, daemon):
        """Each container's CPU percent off one stats sample, read as it is asked for."""
        query = urllib.parse.urlencode({'stream': 'false'})
        for container in self.ids(daemon):
            yield cli.cpu_percent(daemon.json('GET', f'/containers/{quoted(container)}/stats?{query}', missing=()))

    def exec(self, daemon, output, argv, environment=(), stdin=None):
        """`argv` in the running container of the engine's service that `docker compose exec` picks."""
        container = hostdaemon.engine_service(daemon, self.name)
        return daemon.exec(output, container, argv, environment=environment, stdin=stdin)


def engine_file(context, file):
    if composefiles.is_account_file(file, context.policy):
        raise Refused(f"'{file}' is an account's compose file; this runs only in the engine's")
    return file


@dataclass(frozen=True)
class EngineExec:
    """The core method's command in an engine service's running container, as `docker compose exec` ran it."""

    service: str
    command: object
    stdin: object = None
    passes: tuple = ()

    def prepare(self, context, first, *taken):
        """`command(context, *values)`, once the first value is the engine's project directory for sites-db and the
        engine's compose file for every other service."""
        if self.service == SITES_DB:
            context.engine_directory(first, ())
        else:
            engine_file(context, first)
        return (self.command(context, *taken),)

    def __call__(self, daemon, output, argv):
        environment = [(name, output.passed.get(name, '')) for name in self.passes]
        return Service(None, self.service).exec(daemon, output, argv, environment, self.stdin)


def in_service(service, read, command, stdin=None, passes=(), on_host=False):
    """An action of `service` reading the engine's file or directory, then `read`, and running `command`'s argv."""
    run = EngineExec(service, command, stdin, passes)
    return hostapi.asking(reads(values.path, read), run, run.prepare, passes=passes, on_host=on_host)


def fixed(service, *argv, on_host=False):
    return in_service(service, nothing, lambda context: list(argv), on_host=on_host)


def sites_db(read, script, stdin=None):
    """`script(*values)` run by bash in sites-db, with the root password taken from its own variable."""
    return in_service(SITES_DB, read, lambda context, *taken: ['bash', '-c', SITES_DB_PASSWORD + script(*taken)], stdin)


def sites_db_import(read, script, stdin=None):
    """`script(*values)` run by bash in sites-db, root only for what `import_as()` says is root's."""
    return in_service(SITES_DB, read, lambda context, *taken: ['bash', '-c', script(*taken)], stdin)


litespeed_verb = values.one_of(('restart', 'fullrestart', 'condrestart'), 'lswsctrl command')
sftp_login = values.matching(re.compile(r'[a-zA-Z0-9_]+'), 'SFTP login')
database = values.matching(re.compile(r'[A-Za-z_-][A-Za-z0-9_-]*'), 'database')
table = values.matching(re.compile(r'[A-Za-z0-9_$]+'), 'table')
mysql_account = values.matching(re.compile(r'[A-Za-z0-9_-]+'), 'MySQL user')


def import_account(value):
    """A project's own account, never sites-db's root."""
    if mysql_account(value).lower() == 'root':
        values.refuse('import account', value)
    return value


def operand_matching(pattern, kind):
    """An operand `pattern` matches whole, any other refused as a `kind`."""
    check = values.matching(re.compile(pattern), kind)
    return lambda value: check(values.operand(value))


email = operand_matching(r'\S+@[^\s@]+', 'email address')
url = operand_matching(r'https?://\S+', 'URL')


def ftp_directory(value):
    if not values.path(value).startswith('/home/ftpuser/'):
        values.refuse('FTP directory', value)
    return value


def lighthouse_rules(value):
    rule = r'MAP [A-Za-z0-9*.:\[\]_-]+ (?:~NOTFOUND|[A-Za-z0-9.:\[\]]+)'
    if not all(re.fullmatch(rule, part) for part in value.split(', ')):
        values.refuse('resolver rules', value)
    return value


def modsecurity_test_dir(context, value):
    engine = re.escape(context.policy.roots['engine'].rstrip('/'))
    pattern = engine + r'/config/modsecurity/\.custom-rules-test-[0-9a-f]+'
    if not re.fullmatch(pattern, value):
        values.refuse('ModSecurity test directory', value)
    return value


def modsecurity_nginx_check(context, directory):
    return builders.script(MODSECURITY_CHECK_SCRIPT)(
        modsecurity_test_dir(context, directory), '/panelalpha-modsecurity-check/', 'panelalpha_check',
    )


def ftp_add_user(context, user, uid, gid, directory, quota):
    words = ['pure-pw', 'useradd', escapeshellarg(user), '-m', '-u', escapeshellarg(uid), '-g', escapeshellarg(gid),
             '-d', escapeshellarg(directory), *(['-N', escapeshellarg(quota)] if quota else [])]
    return ['/bin/sh', '-c', FTP_PASSWORD_PIPE + ' '.join(words)]


def ftp_set_password(context, user):
    return ['/bin/sh', '-c', FTP_PASSWORD_PIPE + ' '.join(['pure-pw', 'passwd', escapeshellarg(user), '-m'])]


def sftp_kill_script(logins):
    patterns = ' '.join(escapeshellarg('^sshd: ' + login + '(@|\\s|$)') for login in logins)
    monitors = ' '.join(escapeshellarg('^sshd: ' + login + ' \\[priv\\]') for login in logins)
    return '\n'.join([
        'killed=0',
        f'for pattern in {monitors}; do',
        '  n=$(pgrep -cf "$pattern" 2>/dev/null || true)',
        '  killed=$((killed + ${n:-0}))',
        'done',
        f'for pattern in {patterns}; do',
        '  pids=$(pgrep -f "$pattern" 2>/dev/null || true)',
        '  [ -n "$pids" ] && kill -TERM $pids 2>/dev/null || true',
        'done',
        'sleep 2',
        f'for pattern in {patterns}; do',
        '  pids=$(pgrep -f "$pattern" 2>/dev/null || true)',
        '  [ -n "$pids" ] && kill -KILL $pids 2>/dev/null || true',
        'done',
        'echo "$killed"',
    ])


def sftp_kill(context, logins):
    return ['sh', '-c', sftp_kill_script(logins)] if logins else values.refuse('SFTP logins', '')


def test_email(context, address):
    message = ('From: PanelAlpha Engine <noreply@example.invalid>\n'
               f'To: {address}\n'
               'Subject: Test email from PanelAlpha Engine\n\n'
               'This is a test email sent from PanelAlpha Engine.\n')
    return ['bash', '-c', 'echo ' + escapeshellarg(message) + " | exim4 -v -odf -f '<>' " + escapeshellarg(address)]


def lighthouse_audit(context, address, desktop, rules):
    flags = ['--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--ignore-certificate-errors',
             f'--host-resolver-rules="{rules}"']
    report = hashlib.md5(address.encode()).hexdigest() + ('-desktop' if desktop else '-mobile') + '.json'
    return [
        'lighthouse', address, '--output', 'json', '--output-path', f'/data/{report}',
        '--chrome-flags=' + escapeshellarg(' '.join(flags)),
        '--ignore-status-code', '--no-enable-error-reporting', '--only-audits=final-screenshot',
        '--only-categories=performance', *(['--preset=desktop'] if desktop else []),
    ]


def preg_quote(value):
    """PHP's preg_quote() with `/` as the delimiter."""
    return ''.join('\\' + c if c in PREG_SPECIAL else ('\\000' if c == '\0' else c) for c in value)


def definer(user):
    return f'`{user}`@`%`'


def schema_rewrite(source, target, account):
    """sed renaming the schema and giving every DEFINER to `account`, on schema lines only, so a data row holding
    `source.` survives."""
    replacement = target.replace('\\', '\\\\').replace('&', '\\&').replace('/', '\\/')
    pattern = preg_quote(source)
    expressions = [
        f's/`{pattern}`\\./`{replacement}`./g',
        f's/\\b{pattern}\\./{replacement}./g',
        f's/{ANY_DEFINER}/DEFINER={definer(account)}/g',
    ]
    return 'sed' + ''.join(' -e ' + escapeshellarg(f'/^INSERT INTO /!{{/^(/!{expression}}}') for expression in expressions)


def sites_db_dump(name, only):
    if only:
        return 'mariadb-dump --single-transaction -uroot -- ' + escapeshellarg(name) + ' ' + escapeshellarg(only)
    return 'mariadb-dump --single-transaction --routines --triggers --events -uroot -- ' + escapeshellarg(name)


def sites_db_restore(name):
    return 'mariadb -uroot -- ' + escapeshellarg(name)


def import_as(account, name, stream):
    """`stream` loaded into `name` signed in as `account` with a password made here for this import, which the trap
    takes back however the script ends; root only reads and sets the account's sign-in."""
    return (IMPORT_SCRIPT
            .replace('@ACCOUNT@', escapeshellarg(account))
            .replace('@LOCK@', escapeshellarg(f'{IMPORT_LOCK_DIR}/panelalpha-sites-db-import-{account}'))
            .replace('@STREAM@', stream)
            .replace('@DATABASE@', escapeshellarg(name)))


def sites_db_copy(source, target, only, account):
    dump = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" ' + sites_db_dump(source, only)
    return import_as(account, target, f'{dump} | {schema_rewrite(source, target, account)}')


def sites_db_restore_copy(source, target, account):
    return import_as(account, target, schema_rewrite(source, target, account))


def sites_db_drop_table(name, only):
    drop = 'DROP TABLE IF EXISTS `' + only + '`'
    return 'mariadb -uroot -e ' + escapeshellarg(drop) + ' -- ' + escapeshellarg(name)


ACTIONS = {
    'sitesDbCacheSizes': in_service(SITES_DB, nothing, lambda context: ['sh', '-c', SITES_DB_CACHE_SIZES]),
    'sitesDbDump': sites_db(reads(database, values.optional(table)), sites_db_dump),
    'sitesDbRestore': sites_db(database, sites_db_restore, stdin=dockerapi.CLIENT),
    'sitesDbCopy': sites_db_import(reads(database, database, values.optional(table), import_account), sites_db_copy),
    'sitesDbRestoreCopy': sites_db_import(
        reads(database, database, import_account), sites_db_restore_copy, stdin=dockerapi.CLIENT,
    ),
    'sitesDbDropTable': sites_db(reads(database, table), sites_db_drop_table),
    'nginxConfigTest': fixed(SITES_HTTP, 'nginx', '-t', on_host=True),
    'apacheConfigTest': fixed(SITES_HTTP, 'apachectl', '-t', on_host=True),
    'openTcpSockets': fixed(SITES_HTTP, 'cat', '/proc/net/tcp'),
    'nginxReload': fixed(SITES_HTTP, 'sh', '-c', '/etc/init.d/nginx reload'),
    'modsecurityNginxCheck': in_service(SITES_HTTP, values.path, modsecurity_nginx_check, on_host=True),
    'modsecurityApacheCheck': in_service(
        SITES_HTTP, values.path,
        lambda context, directory: ['httpd', '-t', '-f', modsecurity_test_dir(context, directory) + '/httpd.conf'],
        on_host=True,
    ),
    'apacheGraceful': fixed(SITES_HTTP, 'apachectl', '-k', 'graceful'),
    'apacheRestart': fixed(SITES_HTTP, 'apachectl', '-k', 'restart'),
    'litespeedControl': in_service(SITES_HTTP, litespeed_verb, lambda context, verb: ['/usr/local/lsws/bin/lswsctrl', verb]),
    'litespeedVersion': fixed(SITES_HTTP, 'cat', '/usr/local/lsws/VERSION'),
    'litespeedConfigTest': fixed(SITES_HTTP, '/usr/local/lsws/bin/lshttpd', '-r'),
    'litespeedResetAdminPassword': in_service(
        SITES_HTTP, nothing, lambda context: ['bash', '-c', LITESPEED_PASSWORD_SCRIPT], passes=(LITESPEED_PASSWORD,),
    ),
    'eximUpdateConfig': fixed(MAIL, 'update-exim4.conf'),
    'eximRestart': fixed(MAIL, 'sh', '-c', EXIM_RESTART_SCRIPT),
    'eximSendTestEmail': in_service(MAIL, email, test_email),
    'sftpSyncLogins': fixed(SFTP, 'bash', '/etc/sftp/sync-logins.sh'),
    'sftpKillSessions': in_service(SFTP, rest(sftp_login), sftp_kill),
    'ftpTouchPasswordFile': fixed(FTP, 'touch', '/etc/pureftpd/pureftpd.passwd'),
    'ftpMakeDatabase': fixed(FTP, 'pure-pw', 'mkdb'),
    'ftpShowUser': in_service(FTP, values.operand, lambda context, user: ['pure-pw', 'show', user]),
    'ftpSetQuota': in_service(
        FTP, reads(values.operand, values.optional(values.positive)),
        lambda context, user, quota: ['pure-pw', 'usermod', user, '-N', quota],
    ),
    'ftpDeleteUser': in_service(FTP, values.operand, lambda context, user: ['pure-pw', 'userdel', user, '-m']),
    'ftpAddUser': in_service(
        FTP, reads(values.text, values.positive, values.positive, ftp_directory, values.optional(values.positive)),
        ftp_add_user, passes=(FTP_PASSWORD,),
    ),
    'ftpSetPassword': in_service(FTP, values.text, ftp_set_password, passes=(FTP_PASSWORD,)),
    'lighthouseAudit': in_service(LIGHTHOUSE, reads(url, flag('desktop'), lighthouse_rules), lighthouse_audit),
}
