"""The engine's own services through the host's daemon: every exec, the lookups, and the queued reload's job.

GROUNDTRUTH-less by nature: the docker CLI's own bytes for these commands are pinned here instead.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.parse

import support
from rootbroker import dockerapi, hostdaemon
from rootbroker.actions import Policy, compose, engineservices, registry, webserver
from test_account_docker import Hijack, HijackReadingStdin, frame, serve

EXEC_ID = 'e' * 64
ENGINE = '/opt/panelalpha/shared-hosting'
MODSECURITY = 'config/modsecurity/.custom-rules-test-0a1b2c3d4e5f'
CONTAINERS = {
    'sites-http': 'c' * 64,
    'sites-db': 'd' * 64,
    'mail': 'a' * 64,
    'sftp': 'b' * 64,
    'ftp': 'f' * 64,
    'lighthouse': '9' * 64,
}
EMAIL = "o'brien+test@example.com"
LOGINS = ['alice', 'bob_2']
MODSECURITY_CHECK = '''T=$1
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
LITESPEED_PASSWORD = '''if [ -e /usr/local/lsws/admin/fcgi-bin/admin_php ]; then
  echo "admin:$(/usr/local/lsws/admin/fcgi-bin/admin_php -q /usr/local/lsws/admin/misc/htpasswd.php "$PA_LITESPEED_ADMIN_PASSWORD")" > /usr/local/lsws/admin/conf/htpasswd;
else
  echo "admin:$(/usr/local/lsws/admin/fcgi-bin/admin_php5 -q /usr/local/lsws/admin/misc/htpasswd.php "$PA_LITESPEED_ADMIN_PASSWORD")" > /usr/local/lsws/admin/conf/htpasswd;
fi'''
EXIM_RESTART = '''service exim4 stop || true
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
SFTP_KILL = '''killed=0
for pattern in '^sshd: alice \\[priv\\]' '^sshd: bob_2 \\[priv\\]'; do
  n=$(pgrep -cf "$pattern" 2>/dev/null || true)
  killed=$((killed + ${n:-0}))
done
for pattern in '^sshd: alice(@|\\s|$)' '^sshd: bob_2(@|\\s|$)'; do
  pids=$(pgrep -f "$pattern" 2>/dev/null || true)
  [ -n "$pids" ] && kill -TERM $pids 2>/dev/null || true
done
sleep 2
for pattern in '^sshd: alice(@|\\s|$)' '^sshd: bob_2(@|\\s|$)'; do
  pids=$(pgrep -f "$pattern" 2>/dev/null || true)
  [ -n "$pids" ] && kill -KILL $pids 2>/dev/null || true
done
echo "$killed"'''
TEST_EMAIL = ("bash", "-c", "echo 'From: PanelAlpha Engine <noreply@example.invalid>\nTo: o'\\''brien+test@example.com\n"
             "Subject: Test email from PanelAlpha Engine\n\nThis is a test email sent from PanelAlpha Engine.\n' "
             "| exim4 -v -odf -f '<>' 'o'\\''brien+test@example.com'")
LIGHTHOUSE = [
    'lighthouse', 'https://alice.example.com/', '--output', 'json',
    '--output-path', '/data/0f80d94c608addae7c3324534430f986-desktop.json',
    '--chrome-flags=\'--headless --no-sandbox --disable-gpu --disable-dev-shm-usage --ignore-certificate-errors '
    '--host-resolver-rules="MAP alice.example.com 203.0.113.7, MAP localhost ~NOTFOUND, MAP 10.* ~NOTFOUND"\'',
    '--ignore-status-code', '--no-enable-error-reporting', '--only-audits=final-screenshot',
    '--only-categories=performance', '--preset=desktop',
]
SITES_DB_DUMP = ['bash', '-c', 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mariadb-dump --single-transaction --routines '
                              '--triggers --events -uroot -- \'alice_wp\'']
SITES_DB_COPY = [
    'bash', '-c', 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; set -o pipefail; mariadb-dump --single-transaction '
    '--routines --triggers --events -uroot -- \'alice_wp\' | sed '
    '-e \'/^INSERT INTO /!{/^(/!s/`alice_wp`\\./`bob_wp`./g}\' '
    '-e \'/^INSERT INTO /!{/^(/!s/\\balice_wp\\./bob_wp./g}\' '
    '-e \'/^INSERT INTO /!{/^(/!s/DEFINER=`alice_wp`@`%`/DEFINER\\n`bob_wp`@`%`/g}\' '
    '-e \'/^INSERT INTO /!{/^(/!s/DEFINER=`alice_shop`@`%`/DEFINER\\n`bob_shop`@`%`/g}\' '
    '-e \'/^INSERT INTO /!{/^(/!s/DEFINER=`\\([^`]\\|``\\)*`\\(@`\\([^`]\\|``\\)*`\\)\\{0,1\\}/DEFINER=`bob_no.definer`@`%`/g}\' '
    '-e \'/^INSERT INTO /!{/^(/!s/DEFINER\\n/DEFINER=/g}\' | mariadb -uroot -- \'bob_wp\'',
]
# (action, 'file' or 'dir' first value, the rest of the values, on the host, service, Cmd, Env, passing)
EXECS = [
    ('nginxConfigTest', 'file', [], True, 'sites-http', ['nginx', '-t'], [], ()),
    ('apacheConfigTest', 'file', [], True, 'sites-http', ['apachectl', '-t'], [], ()),
    ('openTcpSockets', 'file', [], False, 'sites-http', ['cat', '/proc/net/tcp'], [], ()),
    ('nginxReload', 'file', [], False, 'sites-http', ['sh', '-c', '/etc/init.d/nginx reload'], [], ()),
    ('modsecurityNginxCheck', 'file', ['{engine}/{modsecurity}'], True, 'sites-http',
     ['sh', '-c', MODSECURITY_CHECK, 'sh', '{engine}/{modsecurity}', '/panelalpha-modsecurity-check/',
      'panelalpha_check'], [], ()),
    ('modsecurityApacheCheck', 'file', ['{engine}/{modsecurity}'], True, 'sites-http',
     ['httpd', '-t', '-f', '{engine}/{modsecurity}/httpd.conf'], [], ()),
    ('apacheGraceful', 'file', [], False, 'sites-http', ['apachectl', '-k', 'graceful'], [], ()),
    ('apacheRestart', 'file', [], False, 'sites-http', ['apachectl', '-k', 'restart'], [], ()),
    ('litespeedControl', 'file', ['condrestart'], False, 'sites-http',
     ['/usr/local/lsws/bin/lswsctrl', 'condrestart'], [], ()),
    ('litespeedVersion', 'file', [], False, 'sites-http', ['cat', '/usr/local/lsws/VERSION'], [], ()),
    ('litespeedConfigTest', 'file', [], False, 'sites-http', ['/usr/local/lsws/bin/lshttpd', '-r'], [], ()),
    ('litespeedResetAdminPassword', 'file', [], False, 'sites-http', ['bash', '-c', LITESPEED_PASSWORD],
     ['PA_LITESPEED_ADMIN_PASSWORD=s3cr3t'], ('PA_LITESPEED_ADMIN_PASSWORD',)),
    ('eximUpdateConfig', 'file', [], False, 'mail', ['update-exim4.conf'], [], ()),
    ('eximRestart', 'file', [], False, 'mail', ['sh', '-c', EXIM_RESTART], [], ()),
    ('eximSendTestEmail', 'file', [EMAIL], False, 'mail', list(TEST_EMAIL), [], ()),
    ('sftpSyncLogins', 'file', [], False, 'sftp', ['bash', '/etc/sftp/sync-logins.sh'], [], ()),
    ('sftpKillSessions', 'file', LOGINS, False, 'sftp', ['sh', '-c', SFTP_KILL], [], ()),
    ('ftpTouchPasswordFile', 'file', [], False, 'ftp', ['touch', '/etc/pureftpd/pureftpd.passwd'], [], ()),
    ('ftpMakeDatabase', 'file', [], False, 'ftp', ['pure-pw', 'mkdb'], [], ()),
    ('ftpShowUser', 'file', ['web@example.com'], False, 'ftp', ['pure-pw', 'show', 'web@example.com'], [], ()),
    ('ftpSetQuota', 'file', ['web@example.com', '512'], False, 'ftp',
     ['pure-pw', 'usermod', 'web@example.com', '-N', '512'], [], ()),
    ('ftpDeleteUser', 'file', ['web@example.com'], False, 'ftp',
     ['pure-pw', 'userdel', 'web@example.com', '-m'], [], ()),
    ('ftpAddUser', 'file', ["it's@example.com", '1001', '1002', '/home/ftpuser/alice/public_html', '512'], False, 'ftp',
     ['/bin/sh', '-c', 'printf \'%s\\n%s\\n\' "$PA_FTP_PASSWORD" "$PA_FTP_PASSWORD" | pure-pw useradd '
                       '\'it\'\\\'\'s@example.com\' -m -u \'1001\' -g \'1002\' -d \'/home/ftpuser/alice/public_html\' '
                       '-N \'512\''], ['PA_FTP_PASSWORD=s3cr3t'], ('PA_FTP_PASSWORD',)),
    ('ftpSetPassword', 'file', ['web@example.com'], False, 'ftp',
     ['/bin/sh', '-c', 'printf \'%s\\n%s\\n\' "$PA_FTP_PASSWORD" "$PA_FTP_PASSWORD" | pure-pw passwd '
                       '\'web@example.com\' -m'], ['PA_FTP_PASSWORD=s3cr3t'], ('PA_FTP_PASSWORD',)),
    ('lighthouseAudit', 'file', ['https://alice.example.com/', 'desktop',
                                 'MAP alice.example.com 203.0.113.7, MAP localhost ~NOTFOUND, MAP 10.* ~NOTFOUND'],
     False, 'lighthouse', LIGHTHOUSE, [], ()),
    ('sitesDbCacheSizes', 'dir', [], False, 'sites-db',
     ['sh', '-c', 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e "select @@innodb_buffer_pool_size, '
                 '@@key_buffer_size, @@aria_pagecache_buffer_size"'], [], ()),
    ('sitesDbDump', 'dir', ['alice_wp', ''], False, 'sites-db', SITES_DB_DUMP, [], ()),
    ('sitesDbRestore', 'dir', ['bob_wp'], False, 'sites-db',
     ['bash', '-c', 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mariadb -uroot -- \'bob_wp\''], [], ()),
    ('sitesDbCopy', 'dir', ['alice_wp', 'bob_wp', '', 'bob_no.definer', '2', 'alice_wp', 'bob_wp', 'alice_shop',
                            'bob_shop'], False, 'sites-db', SITES_DB_COPY, [], ()),
    ('sitesDbDropTable', 'dir', ['bob_wp', 'wp_posts'], False, 'sites-db',
     ['bash', '-c', 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mariadb -uroot -e '
                    '\'DROP TABLE IF EXISTS `wp_posts`\' -- \'bob_wp\''], [], ()),
]


class EngineTest(support.Serving):
    actions = registry()

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-engine-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.stderr_path = os.path.join(self.scratch, 'broker.stderr')
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
        self.original = (hostdaemon.SOCKET, hostdaemon.engine_project, webserver.REBIND_SECONDS)
        hostdaemon.SOCKET = self.daemon_socket
        hostdaemon.engine_project = lambda daemon: 'shared-hosting'
        webserver.REBIND_SECONDS = 0
        super().setUp()
        self.make('engine', 'docker-compose.yml', b'services: {}\n')
        self.make('engine', 'users/alice/docker-compose.yml', b'services: {}\n')
        self.routes[('GET', '/containers/json')] = (200, self.listing)
        for container in CONTAINERS.values():
            self.exec_routes(container)

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET, hostdaemon.engine_project, webserver.REBIND_SECONDS = self.original
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        pass

    def broker_policy(self):
        return Policy(self.roots, accounts_db=self.accounts, become=self.become, owners={os.getuid()},
                      engine_owners={os.getuid()}, render=support.no_services)

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        status, body = self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))
        return body(path) if callable(body) else (status, body)

    def listing(self, path):
        """The container of the service the lookup filtered on."""
        filters = self.filters(path)
        labels = dict(item.split('=', 1) for item in filters['label'])
        service = labels.get(dockerapi.SERVICE, '')
        labels = {**labels, hostdaemon.CONTAINER_NUMBER: '1'}
        return 200, [{'Id': CONTAINERS.get(service, '0' * 64), 'Labels': labels}]

    def filters(self, path):
        return json.loads(urllib.parse.parse_qs(urllib.parse.urlsplit(path).query)['filters'][0])

    def filled(self, word):
        return word.replace('{engine}', self.roots['engine']).replace('{modsecurity}', MODSECURITY)

    def exec_routes(self, container, hijack=None, code=0):
        self.routes[('POST', f'/containers/{container}/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, Hijack(b'') if hijack is None else hijack)
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': code, 'Running': False})

    def engine_file(self):
        return self.path('engine', 'docker-compose.yml')

    def account_file(self):
        return self.path('engine', 'users', 'alice', 'docker-compose.yml')

    def ask(self, action, *given, host=False, passing=(), environment=None, stdin=b''):
        flags = [*(['--host'] if host else []), *(word for name in passing for word in ('--env', name))]
        argv = [sys.executable, '-I', support.CLIENT, *flags, action, '--', *given]
        env = {**os.environ, 'PA_ROOT_SOCKET': self.socket, **(environment or {})}
        return subprocess.run(argv, input=stdin, capture_output=True, env=env, timeout=support.SECONDS)

    def bodies(self, path):
        return [json.loads(body) for method, asked, body, _ in self.sent if urllib.parse.urlsplit(asked).path == path]

    def commands(self, path):
        return [body['Cmd'] for body in self.bodies(path)]


class ExecTest(EngineTest):
    def test_each_action_runs_its_own_command_in_its_service_through_the_daemon(self):
        for name, where, given, host, service, command, environment, passing in EXECS:
            with self.subTest(action=name):
                first = self.engine_file() if where == 'file' else self.roots['engine']
                given = [self.filled(value) for value in given]
                expected = [self.filled(value) for value in command]
                container = CONTAINERS[service]
                self.exec_routes(container)
                result = self.ask(f'compose.{name}', first, *given, host=host, passing=passing,
                                  environment=dict(pair.split('=', 1) for pair in environment))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, b'')
                self.assertEqual(self.bodies(f'/containers/{container}/exec')[-1], {
                    'AttachStdout': True, 'AttachStderr': True, 'AttachStdin': name == 'sitesDbRestore',
                    'Tty': False, 'Cmd': expected, 'Env': environment,
                })

    def test_the_service_is_the_first_running_container_of_the_brokers_project(self):
        self.exec_routes(CONTAINERS['mail'])
        self.ask('compose.eximUpdateConfig', self.engine_file())
        self.assertEqual(self.filters(self.asked[0][1]), {
            'label': ['com.docker.compose.project=shared-hosting', 'com.docker.compose.service=mail'],
            'status': ['running'],
        })

    def test_a_service_with_no_running_container_fails_as_compose_did(self):
        self.routes[('GET', '/containers/json')] = (200, [])
        result = self.ask('compose.nginxReload', self.engine_file())
        self.assertEqual((result.returncode, result.stderr), (1, b'service "sites-http" is not running\n'))

    def test_the_restore_relays_the_client_s_stdin_and_the_command_s_output(self):
        self.exec_routes(CONTAINERS['sites-db'], HijackReadingStdin(frame(1, b'restored\n') + frame(2, b'note\n')), 4)
        dump = b''.join(b'INSERT INTO t VALUES (%d);\n' % i for i in range(150000))
        result = self.ask('compose.sitesDbRestore', self.roots['engine'], 'bob_wp', stdin=dump)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (4, b'restored\n', b'note\n'))
        self.assertEqual(next(body for kind, _, body, _ in self.sent if kind == 'STDIN'), dump)
        self.assertEqual(self.bodies(f'/containers/{CONTAINERS["sites-db"]}/exec')[-1], {
            'AttachStdout': True, 'AttachStderr': True, 'AttachStdin': True, 'Tty': False,
            'Cmd': ['bash', '-c', 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mariadb -uroot -- \'bob_wp\''], 'Env': [],
        })

    def test_the_exit_code_and_the_daemon_s_words_are_the_command_s(self):
        self.routes[('POST', f'/containers/{CONTAINERS["sites-http"]}/exec')] = (409, {'message': 'container  is   paused'})
        result = self.ask('compose.nginxReload', self.engine_file())
        self.assertEqual((result.returncode, result.stderr), (1, b'Error response from daemon: container is paused\n'))


class LookupTest(EngineTest):
    def test_running_services_prints_the_account_s_running_service_names(self):
        self.routes[('GET', '/containers/json')] = (200, [
            {'Id': '1' * 64, 'Labels': {dockerapi.SERVICE: 'php'}},
            {'Id': '2' * 64, 'Labels': {dockerapi.SERVICE: 'dind'}},
            {'Id': '3' * 64, 'Labels': {dockerapi.SERVICE: 'php'}},
        ])
        result = self.ask('compose.runningServices', self.account_file())
        self.assertEqual((result.returncode, result.stdout), (0, b'dind\nphp\n'), result.stderr)
        self.assertEqual(self.filters(self.asked[0][1]), {
            'label': ['com.docker.compose.project=alice', 'com.docker.compose.oneoff=False'],
            'status': ['running'],
        })

    def test_running_services_of_the_engines_file_reads_the_brokers_project(self):
        self.routes[('GET', '/containers/json')] = (200, [{'Id': '1' * 64, 'Labels': {dockerapi.SERVICE: 'sites-http'}}])
        result = self.ask('compose.engineRunningServices', self.engine_file())
        self.assertEqual((result.returncode, result.stdout), (0, b'sites-http\n'), result.stderr)
        self.assertEqual(self.filters(self.asked[0][1])['label'][0], 'com.docker.compose.project=shared-hosting')

    def test_service_containers_prints_the_label_list_each_caller_reads(self):
        self.routes[('GET', '/containers/json')] = (200, [
            {'Id': '1' * 64, 'Labels': {dockerapi.SERVICE: 'sites-http', 'com.panelalpha.webserver': 'nginx-proxy'}},
            {'Id': '2' * 64, 'Labels': {dockerapi.SERVICE: 'sites-http'}},
        ])
        result = self.ask('compose.serviceContainers', self.engine_file(), 'sites-http')
        self.assertEqual((result.returncode, result.stdout), (0, b'{"Labels":"com.docker.compose.service=sites-http,'
                                                                  b'com.panelalpha.webserver=nginx-proxy"}\n'
                                                                  b'{"Labels":"com.docker.compose.service=sites-http"}\n'),
                         result.stderr)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[0][1]).query)
        self.assertEqual(query['all'], ['1'])

    def test_cpu_percent_is_the_cli_s_formula_on_one_sample(self):
        self.routes[('GET', '/containers/json')] = (200, [{'Id': '1' * 64, 'Labels': {dockerapi.SERVICE: 'dind'}}])
        self.routes[('GET', '/containers/' + '1' * 64 + '/stats')] = (200, {
            'cpu_stats': {'cpu_usage': {'total_usage': 200000000, 'percpu_usage': [1, 2]}, 'system_cpu_usage': 4000000000,
                          'online_cpus': 2},
            'precpu_stats': {'cpu_usage': {'total_usage': 100000000}, 'system_cpu_usage': 2000000000},
        })
        result = self.ask('compose.cpuPercent', self.account_file(), 'dind')
        self.assertEqual((result.returncode, result.stdout), (0, b'10.00%\n'), result.stderr)
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[-1][1]).query)
        self.assertEqual(query, {'stream': ['false']})

    def test_cpu_percent_reads_the_online_cpus_off_the_per_cpu_list_when_the_field_is_missing(self):
        self.routes[('GET', '/containers/json')] = (200, [{'Id': '1' * 64, 'Labels': {dockerapi.SERVICE: 'dind'}}])
        self.routes[('GET', '/containers/' + '1' * 64 + '/stats')] = (200, {
            'cpu_stats': {'cpu_usage': {'total_usage': 300, 'percpu_usage': [1, 2, 3, 4]}, 'system_cpu_usage': 1000},
            'precpu_stats': {'cpu_usage': {'total_usage': 100}, 'system_cpu_usage': 600},
        })
        self.assertEqual(self.ask('compose.cpuPercent', self.account_file(), 'dind').stdout, b'200.00%\n')

    def test_restart_posts_every_container_of_the_service_and_prints_nothing(self):
        self.routes[('GET', '/containers/json')] = (200, [
            {'Id': '1' * 64, 'Labels': {dockerapi.SERVICE: 'sites-http'}},
            {'Id': '2' * 64, 'Labels': {dockerapi.SERVICE: 'sites-http'}},
        ])
        self.routes[('POST', '/containers/' + '1' * 64 + '/restart')] = (204, b'')
        self.routes[('POST', '/containers/' + '2' * 64 + '/restart')] = (204, b'')
        result = self.ask('compose.restart', self.engine_file(), 'sites-http', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual([path for method, path in self.asked if path.endswith('/restart')],
                         ['/containers/' + '1' * 64 + '/restart', '/containers/' + '2' * 64 + '/restart'])

    def test_a_restart_of_a_service_with_no_container_fails_as_compose_did(self):
        self.routes[('GET', '/containers/json')] = (200, [])
        result = self.ask('compose.restart', self.engine_file(), 'sites-http', host=True)
        self.assertEqual((result.returncode, result.stderr), (1, b'service "sites-http" is not running\n'))


class QueuedReloadTest(EngineTest):
    def serve(self, server):
        with open(self.stderr_path, 'wb') as log:
            os.dup2(log.fileno(), 2)
        super().serve(server)

    def broker_stderr(self):
        try:
            with open(self.stderr_path, 'rb') as f:
                return f.read()
        except FileNotFoundError:
            return b''

    def wait_for(self, question):
        deadline = time.monotonic() + support.SECONDS
        while time.monotonic() < deadline:
            if question():
                return True
            time.sleep(0.05)
        return question()

    def reload_routes(self, hijack, code=0):
        self.routes[('POST', f'/containers/{CONTAINERS["sites-http"]}/exec')] = (201, {'Id': EXEC_ID})
        self.routes[('POST', f'/exec/{EXEC_ID}/start')] = (101, hijack)
        self.routes[('GET', f'/exec/{EXEC_ID}/json')] = (200, {'ExitCode': code, 'Running': False})

    def ask_reload(self, reload):
        return self.ask('webserver.queueReload', self.engine_file(), reload, host=True)

    def plain(self, reload, hijack):
        self.reload_routes(hijack)
        return self.ask_reload(reload)

    def restarted(self):
        return [path for method, path in self.asked if path.endswith('/restart')]

    def test_a_reload_is_answered_at_once_and_its_output_reaches_the_broker_s_stderr(self):
        hijack = Hijack(frame(1, b'reloading\n') + frame(2, b'a warning\n'))
        result = self.plain('nginx', hijack)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertTrue(self.wait_for(lambda: b'reloading\na warning\n' in self.broker_stderr()))
        self.assertEqual(self.commands(f'/containers/{CONTAINERS["sites-http"]}/exec')[-1],
                         ['/etc/init.d/nginx', 'reload'])

    def test_every_reload_kind_runs_its_own_command(self):
        for reload, command in (('litespeed', ['/usr/local/lsws/bin/lswsctrl', 'condrestart']),
                                ('apache', ['apachectl', '-k', 'graceful'])):
            with self.subTest(reload=reload):
                self.asked.clear()
                path = f'/containers/{CONTAINERS["sites-http"]}/exec'
                self.assertEqual(self.plain(reload, Hijack(b'')).returncode, 0)
                self.assertTrue(self.wait_for(lambda: self.commands(path)[-1:] == [command]))
                self.assertEqual(self.restarted(), [])

    def test_a_rebinding_restart_is_refused_only_on_nginxs_own_verdict(self):
        refused = Hijack(frame(2, b'nginx: [emerg] unknown directive "bogus"\nnginx: configuration file test failed\n'))
        result = self.plain('nginx-rebind', refused)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(self.wait_for(lambda: b'sites-http restart refused, nginx -t: nginx: [emerg] unknown directive '
                                              b'"bogus"\nnginx: configuration file test failed\n' in self.broker_stderr()))
        self.assertEqual(self.commands(f'/containers/{CONTAINERS["sites-http"]}/exec')[-1], ['nginx', '-t'])
        self.assertEqual(self.restarted(), [])

    def test_a_rebinding_restart_goes_ahead_once_the_config_passes(self):
        self.routes[('POST', f'/containers/{CONTAINERS["sites-http"]}/restart')] = (204, b'')
        result = self.plain('nginx-rebind', Hijack(frame(1, b'configuration file test is successful\n')))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(self.wait_for(lambda: self.restarted() == ['/containers/' + CONTAINERS['sites-http'] + '/restart']))
        self.assertNotIn(b'refused', self.broker_stderr())

    def test_a_container_that_cannot_be_asked_does_not_count_as_a_broken_config(self):
        self.reload_routes(Hijack(b''))
        self.routes[('POST', f'/containers/{CONTAINERS["sites-http"]}/exec')] = (409, {'message': 'Container is restarting'})
        self.routes[('POST', f'/containers/{CONTAINERS["sites-http"]}/restart')] = (204, b'')
        self.assertEqual(self.ask_reload('nginx-rebind').returncode, 0)
        self.assertTrue(self.wait_for(lambda: self.restarted() == ['/containers/' + CONTAINERS['sites-http'] + '/restart']))

    def test_a_reload_of_a_file_that_is_not_the_engines_is_refused_before_the_job(self):
        result = self.ask('webserver.queueReload', '/tmp/docker-compose.yml', 'nginx', host=True)
        self.assertEqual(result.returncode, 77, result.stderr)
        self.assertEqual(self.asked, [])

    def test_a_reload_kind_the_broker_does_not_know_is_refused(self):
        result = self.ask('webserver.queueReload', self.engine_file(), 'restart-everything', host=True)
        self.assertEqual(result.returncode, 77, result.stderr)


class SftpKillScriptTest(unittest.TestCase):
    def test_each_login_is_matched_by_its_own_name_and_never_by_uid(self):
        script = engineservices.sftp_kill_script(['alice_sftp', 'bob_sftp'])

        self.assertEqual(1, script.count("'^sshd: alice_sftp \\[priv\\]'"))
        self.assertEqual(1, script.count("'^sshd: bob_sftp \\[priv\\]'"))
        self.assertEqual(2, script.count("'^sshd: alice_sftp(@|\\s|$)'"))
        self.assertEqual(2, script.count("'^sshd: bob_sftp(@|\\s|$)'"))
        self.assertNotIn('pkill', script)
        self.assertNotIn(' -u ', script)
        self.assertTrue(script.endswith('echo "$killed"'))


class NativeActionsTest(unittest.TestCase):
    def native(self):
        return {name for name, action in registry().items()
                if action.native and name.split('.')[0] in ('compose', 'webserver')}

    def test_every_native_compose_or_webserver_action_is_covered_here(self):
        covered = {f'compose.{name}' for name, *_ in EXECS}
        covered |= {'compose.runningServices', 'compose.engineRunningServices', 'compose.serviceContainers',
                    'compose.cpuPercent', 'compose.restart', 'webserver.queueReload'}
        self.assertEqual(self.native(), covered)

    def test_up_down_and_build_stay_compose(self):
        for name in ('compose.up', 'compose.down', 'compose.downWithVolumes', 'compose.build', 'compose.execWithTty',
                     'compose.engineUpWithoutDependencies'):
            with self.subTest(action=name):
                self.assertFalse(registry()[name].native)

    def test_the_rebinding_restart_still_waits_five_seconds(self):
        self.assertEqual(webserver.REBIND_SECONDS, 5)


if __name__ == '__main__':
    unittest.main()
