"""The host's own docker run: what it creates, in which order, what it relays and what it removes.

A fake host daemon answers by route, as in test_host_daemon.py; every request the broker makes is kept.
"""

import fcntl
import json
import os
import shutil
import tempfile
import time
import unittest
import urllib.parse

import support
from rootbroker import hostdaemon, protocol
from rootbroker.actions import hostruns, rootscripts
from test_account_docker import Chunks, Hijack, HijackReadingStdin, Raw, frame, serve

CONTAINER_ID = 'c' * 64
CA = '/etc/ssl/certs/ca-certificates.crt'
SERIAL = '/usr/local/lsws/conf/serial.no'
REGISTRY = '127.0.0.1:5000'
REF = REGISTRY + '/buildcache/github.com/acme/app:main'
BUILDCTL = ['build', '--progress=plain', '--frontend', 'dockerfile.v0', '--local', 'context=/src',
            '--local', 'dockerfile=/src', '--opt', 'filename=Dockerfile',
            '--export-cache', 'type=local,dest=/out,mode=max,oci-mediatypes=true,image-manifest=true']
ATTACHED = {'AttachStdin': False, 'AttachStdout': True, 'AttachStderr': True, 'Tty': False, 'OpenStdin': False}


class HostRunsTest(support.Serving):
    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-host-runs-')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(os.path.join(self.scratch, 'docker.sock'), self.answer, self.asked, self.sent)
        self.daemon.handle_error = self.hung_up
        self.originals = (hostdaemon.SOCKET, rootscripts.PUSH_LOCK)
        hostdaemon.SOCKET = os.path.join(self.scratch, 'docker.sock')
        rootscripts.PUSH_LOCK = os.path.join(self.scratch, 'cache-registry.lock')
        super().setUp()
        self.checkout = self.directory('homes', 'u/project')
        self.cache = self.directory('project-caches', 'u')
        self.routes.update({
            ('POST', '/containers/create'): (201, {'Id': CONTAINER_ID}),
            ('POST', f'/containers/{CONTAINER_ID}/attach'): (101, Hijack(b'')),
            ('POST', f'/containers/{CONTAINER_ID}/start'): (204, b''),
            ('POST', f'/containers/{CONTAINER_ID}/wait'): (200, {'StatusCode': 0}),
            ('DELETE', f'/containers/{CONTAINER_ID}'): (204, b''),
        })

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET, rootscripts.PUSH_LOCK = self.originals
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def hung_up(self, request, address):
        """A run the client gave up on leaves the fake daemon writing to its closed socket."""

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        answer = self.routes.get((method, route), (404, {'message': f'No such object: {route}'}))
        return answer(path) if callable(answer) else answer

    def requests(self):
        return [(method, urllib.parse.urlsplit(path).path) for method, path in self.asked]

    def created(self):
        return next(json.loads(payload) for method, path, payload, headers in self.sent if path == '/containers/create')

    def ask(self, action, *values, code=0, out=b'', err=b''):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/attach')] = (101, Hijack(frame(1, out) + frame(2, err)))
        self.routes[('POST', f'/containers/{CONTAINER_ID}/wait')] = (200, {'StatusCode': code})
        return self.pa_root(action, *values)

    def work(self, leaf, run='0a1b2c3d4e5f'):
        return os.path.join(self.roots['engine'], 'data', 'build-cache', run, leaf)

    def build(self, labels=(('com.panelalpha.deploy.step', 'build'),), user='1001:1001', memory='2048m',
              network='panelalpha-build', mounts=None, read_only=None, workdir='/app',
              environment=(('SSL_CERT_FILE', CA), ('CI', 'true')), image='node:22-bookworm-slim',
              script='npm ci && npm run build'):
        mounts = [('/app', self.checkout), ('/var/cache/pa-js', self.cache)] if mounts is None else mounts
        read_only = [(CA, CA)] if read_only is None else read_only
        given = [str(len(labels))]
        for name, value in labels:
            given += [name, value]
        given += [user, memory, network, str(len(mounts))]
        for target, source in mounts:
            given += [target, source]
        given.append(str(len(read_only)))
        for target, source in read_only:
            given += [target, source]
        given += [workdir, str(len(environment))]
        for name, value in environment:
            given += [name, value]
        return [*given, image, script]

    def build_kit(self, read_only=None, writable=None, network='panelalpha-build', limit='4g', arguments=None):
        read_only = [('/src', self.work('src'))] if read_only is None else read_only
        writable = [('/out', self.work('out-0a1b2c3d'))] if writable is None else writable
        given = [network, limit, '4096', str(len(read_only))]
        for target, source in read_only:
            given += [target, source]
        given.append(str(len(writable)))
        for target, source in writable:
            given += [target, source]
        return [*given, *(BUILDCTL if arguments is None else arguments)]


class CreateTest(HostRunsTest):
    def test_a_node_build_creates_the_hardened_container_the_cli_made(self):
        result = self.ask('docker.nodeBuild', *self.build())
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''), result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': 'node:22-bookworm-slim',
            'Cmd': ['sh', '-c', 'npm ci && npm run build'],
            'Labels': {'com.panelalpha.deploy.step': 'build'},
            'User': '1001:1001',
            'WorkingDir': '/app',
            'Env': [f'SSL_CERT_FILE={CA}', 'CI=true'],
            'HostConfig': {
                'AutoRemove': False,
                'Binds': [f'{self.checkout}:/app', f'{self.cache}:/var/cache/pa-js', f'{CA}:{CA}:ro'],
                'NetworkMode': 'panelalpha-build',
                'SecurityOpt': ['no-new-privileges'],
                'CapDrop': ['ALL'],
                'Memory': 2048 << 20,
                'MemorySwap': 2048 << 20,
                'OomScoreAdj': 1000,
                'PidsLimit': 512,
            },
        })

    def test_a_memory_limit_takes_its_unit_in_either_case_as_the_cli_does(self):
        result = self.ask('docker.nodeBuild', *self.build(memory='2G'))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created()['HostConfig']['Memory'], 2 << 30)
        refused = self.pa_root('docker.nodeBuild', *self.build(memory='2T'))
        self.assertEqual((refused.returncode, refused.stderr), (protocol.EXIT_REFUSED, b"pa-root: refused: memory '2T' is not allowed\n"))

    def test_a_php_build_skips_the_entrypoint_and_runs_under_sh(self):
        values = self.build(
            user='1001:1002', memory='2g', network='', mounts=[('/app', self.checkout)], read_only=[],
            workdir='/app/web', environment=(('COMPOSER_HOME', '/var/cache/pa-composer'),),
            image='panelalpha/php:8.3-apache', script='composer install --no-dev',
        )
        result = self.ask('docker.phpBuild', *values)
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.created()
        self.assertEqual((body['Image'], body['Entrypoint'], body['Cmd']), (
            'panelalpha/php:8.3-apache', ['sh'], ['-e', '-c', 'composer install --no-dev'],
        ))
        self.assertEqual(body['HostConfig'], {
            'AutoRemove': False,
            'Binds': [f'{self.checkout}:/app'],
            'SecurityOpt': ['no-new-privileges'],
            'CapDrop': ['ALL'],
            'Memory': 2 << 30,
            'MemorySwap': 2 << 30,
            'OomScoreAdj': 1000,
            'PidsLimit': 512,
        })
        self.assertNotIn('NetworkMode', body['HostConfig'])

    def test_a_composer_install_creates_the_composer_image_under_sh(self):
        values = self.build(
            user='1001:1002', labels=(), mounts=[('/app', self.checkout)], read_only=[], environment=(),
            image='composer:2', script='composer install --no-scripts',
        )
        result = self.ask('docker.composerInstall', *values)
        self.assertEqual(result.returncode, 0, result.stderr)
        body = self.created()
        self.assertEqual((body['Entrypoint'], body['Cmd'], body['Labels']), (
            ['sh'], ['-c', 'composer install --no-scripts'], {},
        ))
        self.assertNotIn('Env', body)

    def test_reading_a_file_from_an_image_never_pulls_and_never_reaches_a_network(self):
        result = self.ask('docker.readFileFromImage', '0', 'panelalpha/php:8.3', '/etc/passwd')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': 'panelalpha/php:8.3',
            'Cmd': ['--', '/etc/passwd'],
            'Entrypoint': ['cat'],
            'Labels': {},
            'HostConfig': {'AutoRemove': False, 'Binds': [], 'NetworkMode': 'none'},
        })

    def test_the_litespeed_serial_is_the_only_mount_of_that_run(self):
        serial = self.path('engine', 'webserver-config', 'litespeed', 'serial.no.tmp')
        result = self.ask('docker.checkLiteSpeedSerial', '0', serial)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': 'litespeedtech/litespeed',
            'Cmd': ['-r'],
            'Entrypoint': ['/usr/local/lsws/bin/lshttpd'],
            'Labels': {},
            'HostConfig': {'AutoRemove': False, 'Binds': [f'{serial}:{SERIAL}']},
        })

    def test_a_buildkit_run_gets_the_sysbox_runtime_and_the_build_network(self):
        result = self.ask('build-cache.buildKit', *self.build_kit())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': 'moby/buildkit:v0.34.1',
            'Cmd': BUILDCTL,
            'Entrypoint': ['buildctl-daemonless.sh'],
            'Labels': {},
            'HostConfig': {
                'AutoRemove': False,
                'Binds': [f"{self.work('src')}:/src:ro", f"{self.work('out-0a1b2c3d')}:/out"],
                'NetworkMode': 'panelalpha-build',
                'Runtime': 'sysbox-runc',
                'Memory': 4 << 30,
                'PidsLimit': 4096,
            },
        })

    def test_railpack_prepares_its_plan_in_the_checkout(self):
        image = 'ghcr.io/panelalpha/engine-user-dind:v2.2.0'
        result = self.ask('build-cache.railpackPrepare', 'panelalpha-build', '4g', '4096', image,
                          self.work('src'), self.work('plan'))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': image,
            'Cmd': ['prepare', '.', '--plan-out', '/plan/railpack-plan-tmp.json'],
            'Entrypoint': ['railpack'],
            'Labels': {},
            'WorkingDir': '/src',
            'HostConfig': {
                'AutoRemove': False,
                'Binds': [f"{self.work('src')}:/src:ro", f"{self.work('plan')}:/plan"],
                'NetworkMode': 'panelalpha-build',
                'Runtime': 'sysbox-runc',
                'Memory': 4 << 30,
                'PidsLimit': 4096,
            },
        })

    def test_an_operator_image_is_copied_out_of_the_writer_as_root_on_the_host(self):
        result = self.ask('build-cache.copyImageToLayout', REGISTRY, REGISTRY + '/acme/base:1.2',
                          self.work('layout-0a1b2c3d'), '1.2')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': 'ghcr.io/regclient/regctl:v0.11.7',
            'Cmd': ['--host', f'reg={REGISTRY},tls=disabled', 'image', 'copy', REGISTRY + '/acme/base:1.2',
                    'ocidir:///oci:1.2'],
            'Labels': {},
            'User': '0:0',
            'HostConfig': {
                'AutoRemove': False,
                'Binds': [f"{self.work('layout-0a1b2c3d')}:/oci"],
                'NetworkMode': 'host',
            },
        })

    def test_publishing_copies_the_layout_the_build_wrote_read_only(self):
        result = self.ask('scripts.publishBuildCache', self.work('out-1a2b3c4d'), REF)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.created(), {
            **ATTACHED,
            'Image': 'ghcr.io/regclient/regctl:v0.11.7',
            'Cmd': ['--host', f'reg={REGISTRY},tls=disabled', 'image', 'copy', 'ocidir:///oci', REF],
            'Labels': {},
            'User': '0:0',
            'HostConfig': {
                'AutoRemove': False,
                'Binds': [f"{self.work('out-1a2b3c4d')}:/oci:ro"],
                'NetworkMode': 'host',
            },
        })


class RunTest(HostRunsTest):
    def test_the_run_attaches_before_the_start_and_waits_before_it_removes(self):
        result = self.ask('docker.nodeBuild', *self.build(), code=3)
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertEqual(self.requests(), [
            ('POST', '/containers/create'),
            ('POST', f'/containers/{CONTAINER_ID}/attach'),
            ('POST', f'/containers/{CONTAINER_ID}/start'),
            ('POST', f'/containers/{CONTAINER_ID}/wait'),
            ('DELETE', f'/containers/{CONTAINER_ID}'),
        ])

    def test_the_output_is_relayed_and_the_exit_code_is_the_containers(self):
        result = self.ask('docker.composerInstall', *self.build(), code=7,
                          out=b'installed\n', err=b'a warning\n')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (7, b'installed\n', b'a warning\n'))

    def test_a_missing_image_is_pulled_before_the_container_is_made_again(self):
        self.routes[('POST', '/containers/create')] = self.missing_once()
        self.routes[('POST', '/images/create')] = (200, Chunks([b'{"status":"Pulling"}\n',
                                                               b'{"status":"Downloaded"}\n']))
        result = self.ask('docker.nodeBuild', *self.build())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, b"Unable to find image 'node:22-bookworm-slim' locally\n")
        self.assertEqual(self.requests()[:3], [
            ('POST', '/containers/create'), ('POST', '/images/create'), ('POST', '/containers/create'),
        ])
        pulled = urllib.parse.parse_qs(urllib.parse.urlsplit(self.asked[1][1]).query)
        self.assertEqual(pulled, {'fromImage': ['node'], 'tag': ['22-bookworm-slim']})

    def missing_once(self):
        made = []

        def create(path):
            made.append(path)
            if len(made) == 1:
                return 404, {'message': 'No such image: node:22-bookworm-slim'}
            return 201, {'Id': CONTAINER_ID}

        return create

    def test_pull_never_fails_as_the_cli_did_without_trying_the_registry(self):
        self.routes[('POST', '/containers/create')] = (404, {'message': 'No such image: panelalpha/php:8.3'})
        result = self.ask('docker.readFileFromImage', '0', 'panelalpha/php:8.3', '/etc/passwd')
        self.assertEqual((result.returncode, result.stdout), (125, b''))
        self.assertEqual(result.stderr, (
            b'docker: Error response from daemon: No such image: panelalpha/php:8.3\n\n'
            b"Run 'docker run --help' for more information\n"
        ))
        self.assertEqual(self.requests(), [('POST', '/containers/create')])

    def test_a_start_the_daemon_refuses_is_the_cli_s_125_and_leaves_no_container(self):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/start')] = (500, {'message': 'oci runtime error'})
        result = self.ask('docker.nodeBuild', *self.build())
        self.assertEqual(result.returncode, 125)
        self.assertEqual(result.stderr, (
            b'docker: Error response from daemon: oci runtime error\n\n'
            b"Run 'docker run --help' for more information\n"
        ))
        self.assertEqual(self.requests()[-1], ('DELETE', f'/containers/{CONTAINER_ID}'))

    def test_an_attach_the_daemon_refuses_is_the_cli_s_125(self):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/attach')] = (500, {'message': 'attach failed'})
        result = self.pa_root('docker.nodeBuild', *self.build())
        self.assertEqual((result.returncode, result.stderr), (125, (
            b'docker: Error response from daemon: attach failed\n\n'
            b"Run 'docker run --help' for more information\n"
        )))
        self.assertNotIn(('POST', f'/containers/{CONTAINER_ID}/start'), self.requests())

    def test_a_container_created_without_its_id_is_unexpected_and_never_attached_to(self):
        self.routes[('POST', '/containers/create')] = (201, {'Warnings': []})
        result = self.pa_root('docker.nodeBuild', *self.build())
        self.assert_answered(result, 1, b"the host's Docker answered with an unexpected Id\n")
        self.assertEqual(self.requests(), [('POST', '/containers/create')])

    def test_a_wait_answered_without_a_status_code_is_unexpected_and_the_container_removed(self):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/wait')] = (200, {'Error': None})
        result = self.pa_root('docker.nodeBuild', *self.build())
        self.assert_answered(result, 1, b"the host's Docker answered with an unexpected StatusCode\n")
        self.assertEqual(self.requests()[-1], ('DELETE', f'/containers/{CONTAINER_ID}'))

    def test_an_attach_answered_with_no_status_line_is_a_daemon_that_stopped_answering(self):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/attach')] = (101, Raw(b'HTTP/1.1 abc\r\n\r\n'))
        result = self.pa_root('docker.nodeBuild', *self.build())
        self.assert_answered(result, 1, b"the host's Docker stopped answering: BadStatusLine('HTTP/1.1 abc')\n")
        self.assertNotIn(('POST', f'/containers/{CONTAINER_ID}/start'), self.requests())
        self.assertEqual(self.requests()[-1], ('DELETE', f'/containers/{CONTAINER_ID}'))

    def test_a_client_that_hangs_up_mid_run_leaves_no_container_behind(self):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/attach')] = (101, HijackReadingStdin(b''))
        conn = self.connect()
        conn.sendall(protocol.request('docker.nodeBuild', self.build(), False, {}) + protocol.frame(protocol.STDIN_END))
        conn.close()
        self.wait_for(lambda: ('DELETE', f'/containers/{CONTAINER_ID}') in self.requests())
        self.assertNotIn(('POST', f'/containers/{CONTAINER_ID}/wait'), self.requests())

    def wait_for(self, condition):
        for _ in range(500):
            if condition():
                return
            time.sleep(0.02)
        self.fail('the container was not removed')


class TimeoutTest(HostRunsTest):
    def setUp(self):
        self.seconds = hostruns.RUN_SECONDS
        hostruns.RUN_SECONDS = 0.2
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostruns.RUN_SECONDS = self.seconds

    def test_a_run_that_says_nothing_for_its_whole_wait_is_removed(self):
        self.routes[('POST', f'/containers/{CONTAINER_ID}/attach')] = (101, HijackReadingStdin(b''))
        result = self.pa_root('docker.nodeBuild', *self.build())
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr, b"the host's Docker sent nothing for 0.2s\n")
        self.assertIn(('DELETE', f'/containers/{CONTAINER_ID}'), self.requests())
        self.assertNotIn(('POST', f'/containers/{CONTAINER_ID}/wait'), self.requests())


class PushLockTest(HostRunsTest):
    def test_the_publish_holds_the_push_lock_while_it_runs(self):
        probed = []
        self.routes[('POST', '/containers/create')] = self.while_locked(probed)
        result = self.ask('scripts.publishBuildCache', self.work('out-1a2b3c4d'), REF)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(probed, [True])
        with open(rootscripts.PUSH_LOCK, 'rb') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(lock, fcntl.LOCK_UN)

    def while_locked(self, probed):
        def create(path):
            with open(rootscripts.PUSH_LOCK, 'rb') as lock:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    probed.append(True)
                else:
                    probed.append(False)
                    fcntl.flock(lock, fcntl.LOCK_UN)
            return 201, {'Id': CONTAINER_ID}

        return create

    def test_another_publisher_holds_the_lock_shared_and_is_not_waited_for(self):
        with open(rootscripts.PUSH_LOCK, 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_SH)
            try:
                result = self.ask('scripts.publishBuildCache', self.work('out-1a2b3c4d'), REF)
            finally:
                fcntl.flock(lock, fcntl.LOCK_UN)
        self.assertEqual(result.returncode, 0, result.stderr)


class RefusalTest(HostRunsTest):
    def test_a_mount_source_the_run_may_not_see_is_refused_before_the_daemon_is_asked(self):
        refused = [
            ('docker.nodeBuild', self.build(mounts=[('/app', '/etc')])),
            ('docker.nodeBuild', self.build(read_only=[('/app', CA)])),
            ('docker.checkLiteSpeedSerial', ['0', '/etc/shadow']),
            ('build-cache.buildKit', self.build_kit(read_only=[('/src', self.checkout)])),
            ('build-cache.railpackPrepare',
             ['panelalpha-build', '4g', '4096', 'acme/a:1', self.checkout, self.work('plan')]),
            ('build-cache.copyImageToLayout',
             [REGISTRY, REGISTRY + '/acme/a:1', self.work('src'), '1']),
            ('scripts.publishBuildCache', [self.work('src'), REF]),
        ]
        for action, values in refused:
            with self.subTest(action=action):
                result = self.pa_root(action, *values)
                self.assertEqual(result.returncode, 77, result.stderr)
                self.assertIn(b'refused', result.stderr)
        self.assertEqual(self.asked, [])


if __name__ == '__main__':
    unittest.main()
