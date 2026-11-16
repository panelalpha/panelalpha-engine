"""The image actions on a fake host daemon: the request each one makes and what it prints.

Every action here is one Engine API call, so the fake daemon is keyed by (method, path) and each test reads back what
the docker CLI printed for the same thing.
"""

import base64
import json
import os
import shutil
import tempfile
import unittest
import urllib.parse

import support
from rootbroker import hostdaemon, protocol
from rootbroker.actions import registry
from test_account_docker import Chunks, serve

IMAGE_ID = 'sha256:' + 'e' * 64
IMAGE = {
    'Id': IMAGE_ID,
    'RepoTags': ['debian:bookworm-slim'],
    'Config': {'ExposedPorts': {'5432/tcp': {}, '80/tcp': {}}, 'Labels': {'org.opencontainers.image.version': '12.5'}},
    'Metadata': {'LastTagTime': '2026-10-26T03:29:36.726987797Z'},
    'Size': 558000000,
}
INDENTED = (json.dumps([IMAGE], indent=4) + '\n').encode()
NO_SUCH_IMAGE = b'Error response from daemon: No such image: nosuch/gt:1\n'
PULLED = b'docker.io/library/debian:bookworm-slim\n'
PULL_EVENTS = [
    {'status': 'Pulling from library/debian', 'id': 'bookworm-slim'},
    {'status': 'Downloading', 'id': 'a1b2c3d4e5f6', 'progressDetail': {'current': 1024, 'total': 4096}},
    {'status': 'Pulling fs layer', 'id': 'a1b2c3d4e5f6', 'progressDetail': {}},
    {'status': 'Pull complete', 'id': 'a1b2c3d4e5f6', 'progressDetail': {}},
    {'status': 'Digest: sha256:734a72b3d6142a71993b4bc580ca01d8ca687f77a2c422d96a961d3376b179bc'},
    {'status': 'Status: Image is up to date for debian:bookworm-slim'},
]
PULLED_OUTPUT = (
    b'bookworm-slim: Pulling from library/debian\n'
    b'a1b2c3d4e5f6: Pulling fs layer\n'
    b'a1b2c3d4e5f6: Pull complete\n'
    b'Digest: sha256:734a72b3d6142a71993b4bc580ca01d8ca687f77a2c422d96a961d3376b179bc\n'
    b'Status: Image is up to date for debian:bookworm-slim\n'
    + PULLED
)


def chunks(events):
    return Chunks([(json.dumps(event) + '\r\n').encode() for event in events])


def image_url(ref):
    return '/images/' + urllib.parse.quote(ref, safe='')


def image_path(ref, suffix='json'):
    return f'{image_url(ref)}/{suffix}'


class ImageActionsTest(support.Serving):
    actions = registry()

    def setUp(self):
        self.scratch = tempfile.mkdtemp(prefix='broker-images-')
        self.daemon_socket = os.path.join(self.scratch, 'docker.sock')
        self.asked = []
        self.sent = []
        self.routes = {}
        self.daemon = serve(self.daemon_socket, self.answer, self.asked, self.sent)
        self.original = hostdaemon.SOCKET
        hostdaemon.SOCKET = self.daemon_socket
        super().setUp()

    def tearDown(self):
        super().tearDown()
        hostdaemon.SOCKET = self.original
        self.daemon.shutdown()
        self.daemon.server_close()
        shutil.rmtree(self.scratch, ignore_errors=True)

    def serve(self, server):
        server.broker.enter_host = self.entered
        super().serve(server)

    def entered(self, namespaces):
        with open(os.path.join(self.base, 'entered'), 'w') as f:
            f.write(str(namespaces))

    def answer(self, method, path):
        route = urllib.parse.urlsplit(path).path
        found = self.routes.get((method, route), (404, {'message': f'No such image: {route}'}))
        return found(path) if callable(found) else found

    def missing(self, ref):
        self.routes[('GET', image_path(ref))] = (404, {'message': f'No such image: {ref}'})
        self.routes[('DELETE', image_url(ref))] = (404, {'message': f'No such image: {ref}'})

    def test_an_inspect_prints_the_image_as_the_cli_indents_it(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        result = self.pa_root('docker.inspectImage', 'debian:bookworm-slim')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, INDENTED, b''))
        self.assertEqual(self.asked, [('GET', image_path('debian:bookworm-slim'))])

    def test_an_inspect_of_a_missing_image_prints_no_array_beside_the_error(self):
        self.missing('nosuch/gt:1')
        result = self.pa_root('docker.inspectImage', 'nosuch/gt:1')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (1, b'[]\n', NO_SUCH_IMAGE))

    def test_a_daemon_error_is_told_and_fails_as_the_cli_did(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (500, {'message': 'runtime   is broken'})
        result = self.pa_root('docker.inspectImage', 'debian:bookworm-slim')
        self.assertEqual((result.returncode, result.stdout, result.stderr),
                         (1, b'', b'Error response from daemon: runtime is broken\n'))

    def test_a_line_inspects_the_same_image_the_same_way(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        result = self.pa_root('lines.inspectImage', 'debian:bookworm-slim')
        self.assertEqual((result.returncode, result.stdout), (0, INDENTED), result.stderr)
        self.assertEqual(self.asked, [('GET', image_path('debian:bookworm-slim'))])

    def test_the_host_image_probe_runs_in_the_hosts_namespaces(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        result = self.pa_root('probes.hostImage', 'debian:bookworm-slim', host=True)
        self.assertEqual((result.returncode, result.stdout), (0, INDENTED), result.stderr)
        with open(os.path.join(self.base, 'entered')) as f:
            self.assertEqual(f.read(), 'None')

    def test_the_probe_refuses_a_request_outside_the_hosts_namespaces(self):
        result = self.pa_root('probes.hostImage', 'debian:bookworm-slim')
        self.assertEqual(result.returncode, protocol.EXIT_REFUSED)

    def test_an_image_id_is_printed_alone_or_its_absence_fails(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        found = self.pa_root('docker.imageId', 'debian:bookworm-slim')
        self.assertEqual((found.returncode, found.stdout), (0, IMAGE_ID.encode() + b'\n'), found.stderr)
        self.missing('nosuch/gt:1')
        missing = self.pa_root('docker.imageId', 'nosuch/gt:1')
        self.assertEqual((missing.returncode, missing.stdout, missing.stderr), (1, b'', NO_SUCH_IMAGE))

    def test_a_label_the_image_does_not_carry_is_an_empty_line(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        found = self.pa_root('lines.imageLabel', 'debian:bookworm-slim', 'org.opencontainers.image.version')
        self.assertEqual((found.returncode, found.stdout), (0, b'12.5\n'), found.stderr)
        absent = self.pa_root('lines.imageLabel', 'debian:bookworm-slim', 'org.opencontainers.image.revision')
        self.assertEqual((absent.returncode, absent.stdout), (0, b'\n'), absent.stderr)

    def test_the_declared_ports_are_json_and_null_without_any(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        found = self.pa_root('docker.imageExposedPorts', 'debian:bookworm-slim')
        self.assertEqual((found.returncode, found.stdout), (0, b'{"5432/tcp":{},"80/tcp":{}}\n'), found.stderr)
        self.routes[('GET', image_path('nginx:1.27'))] = (200, {'Id': IMAGE_ID, 'Config': {'ExposedPorts': None}})
        self.assertEqual(self.pa_root('docker.imageExposedPorts', 'nginx:1.27').stdout, b'null\n')

    def test_the_last_tag_time_is_one_json_line_per_image(self):
        self.routes[('GET', image_path('debian:bookworm-slim'))] = (200, IMAGE)
        self.routes[('GET', image_path('composer:2'))] = (200, {'Id': IMAGE_ID, 'Metadata': {}})
        result = self.pa_root('docker.imagesLastTagTime', 'debian:bookworm-slim', 'composer:2')
        self.assertEqual((result.returncode, result.stdout),
                         (0, b'"2026-10-26T03:29:36.726987797Z"\nnull\n'), result.stderr)
        self.missing('nosuch/gt:1')
        partial = self.pa_root('docker.imagesLastTagTime', 'debian:bookworm-slim', 'nosuch/gt:1')
        self.assertEqual((partial.returncode, partial.stdout, partial.stderr),
                         (1, b'"2026-10-26T03:29:36.726987797Z"\n', NO_SUCH_IMAGE))

    def test_the_listing_is_one_line_per_repository_tag_and_leaves_an_untagged_image_out(self):
        self.routes[('GET', '/images/json')] = (200, [
            {'Id': IMAGE_ID, 'RepoTags': ['debian:bookworm-slim', 'debian:bookworm'], 'Size': 558000000},
            {'Id': IMAGE_ID, 'RepoTags': None, 'Size': 0},
        ])
        result = self.pa_root('docker.imageNames')
        self.assertEqual((result.returncode, result.stdout),
                         (0, b'debian:bookworm-slim\ndebian:bookworm\n'), result.stderr)
        sized = self.pa_root('docker.imageNamesWithSizes')
        self.assertEqual(sized.stdout, b'debian:bookworm-slim\t558MB\ndebian:bookworm\t558MB\n')
        self.assertEqual(self.asked, [('GET', '/images/json'), ('GET', '/images/json')])

    def test_a_removal_prints_untagged_and_deleted_as_the_daemon_answers(self):
        self.routes[('DELETE', image_url('gt/tagged:1'))] = (
            200, [{'Untagged': 'gt/tagged:1'}, {'Deleted': 'sha256:' + '0a' * 32}],
        )
        result = self.pa_root('docker.removeImages', 'gt/tagged:1')
        self.assertEqual((result.returncode, result.stdout, result.stderr),
                         (0, b'Untagged: gt/tagged:1\nDeleted: sha256:' + b'0a' * 32 + b'\n', b''))
        self.assertEqual(self.asked_last(), ('DELETE', image_url('gt/tagged:1') + '?force=0'))

    def test_a_missing_image_fails_a_removal_but_not_a_forced_one(self):
        self.missing('nosuch/gt:1')
        removed = self.pa_root('docker.removeImages', 'nosuch/gt:1')
        self.assertEqual((removed.returncode, removed.stdout, removed.stderr), (1, b'', NO_SUCH_IMAGE))
        forced = self.pa_root('docker.forceRemoveImages', 'nosuch/gt:1')
        self.assertEqual((forced.returncode, forced.stdout, forced.stderr), (0, b'', NO_SUCH_IMAGE))

    def test_every_image_is_tried_and_a_refusal_fails_the_whole(self):
        self.routes[('DELETE', image_url('gt/busy:1'))] = (409, {'message': 'image is being used by running container'})
        self.routes[('DELETE', image_url('gt/tagged:1'))] = (200, [{'Untagged': 'gt/tagged:1'}])
        self.missing('nosuch/gt:1')
        for action in ('docker.removeImages', 'docker.forceRemoveImages'):
            with self.subTest(action=action):
                result = self.pa_root(action, 'gt/busy:1', 'nosuch/gt:1', 'gt/tagged:1')
                self.assertEqual((result.returncode, result.stdout), (1, b'Untagged: gt/tagged:1\n'))
                self.assertEqual(result.stderr, b'Error response from daemon: image is being used by running container\n'
                                 + NO_SUCH_IMAGE)

    def test_a_line_removes_one_image(self):
        self.routes[('DELETE', image_url('gt/tagged:1'))] = (200, [{'Untagged': 'gt/tagged:1'}])
        result = self.pa_root('lines.removeImage', 'gt/tagged:1')
        self.assertEqual((result.returncode, result.stdout), (0, b'Untagged: gt/tagged:1\n'), result.stderr)
        self.assertEqual(self.asked_last(), ('DELETE', image_url('gt/tagged:1') + '?force=0'))

    def test_a_tag_is_asked_of_the_image_and_prints_nothing(self):
        self.routes[('POST', image_path('debian:bookworm-slim', 'tag'))] = (201, b'')
        result = self.pa_root('lines.tag', 'debian:bookworm-slim', 'gt/tagged:1')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(urllib.parse.urlsplit(self.asked_last()[1]).query, 'repo=gt%2Ftagged&tag=1')
        self.routes[('POST', image_path('nosuch/gt:1', 'tag'))] = (404, {'message': 'No such image: nosuch/gt:1'})
        missing = self.pa_root('lines.tag', 'nosuch/gt:1', 'gt/tagged:1')
        self.assertEqual((missing.returncode, missing.stderr), (1, NO_SUCH_IMAGE))

    def test_a_pull_prints_the_daemons_progress_and_the_reference_the_cli_names(self):
        self.routes[('POST', '/images/create')] = (200, chunks(PULL_EVENTS))
        result = self.pa_root('docker.pull', 'debian:bookworm-slim')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, PULLED_OUTPUT, b''))
        self.assertEqual(self.asked_last(), ('POST', '/images/create?fromImage=debian&tag=bookworm-slim'))

    def test_a_quiet_pull_prints_only_the_reference(self):
        self.routes[('POST', '/images/create')] = (200, chunks(PULL_EVENTS))
        result = self.pa_root('lines.pullQuietly', 'debian:bookworm-slim')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, PULLED, b''))

    def test_a_pull_that_fails_says_so_in_the_daemons_words(self):
        failure = 'failed to resolve reference "docker.io/gt/does-not-exist:1": not found'
        self.routes[('POST', '/images/create')] = (200, chunks([{'errorDetail': {'message': failure}, 'error': failure}]))
        result = self.pa_root('lines.pullQuietly', 'gt/does-not-exist:1')
        self.assertEqual((result.returncode, result.stdout, result.stderr),
                         (1, b'', f'Error response from daemon: {failure}\n'.encode()))

    def test_a_push_carries_the_empty_login_of_the_cache_registry_and_prints_the_reference(self):
        self.routes[('POST', image_path('127.0.0.1:5000/panelalpha/php', 'push'))] = (
            200, chunks([{'status': 'Pushing', 'id': 'a1b2'}, {'status': 'digest: sha256:abab size: 1'}]),
        )
        result = self.pa_root('lines.pushQuietly', '127.0.0.1:5000/panelalpha/php:8.3-cli-bookworm-pa1234abcd')
        self.assertEqual((result.returncode, result.stdout, result.stderr),
                         (0, b'127.0.0.1:5000/panelalpha/php:8.3-cli-bookworm-pa1234abcd\n', b''))
        self.assertEqual(urllib.parse.urlsplit(self.asked_last()[1]).query, 'tag=8.3-cli-bookworm-pa1234abcd')
        self.assertEqual(self.sent[-1][3].get('X-Registry-Auth'), base64.urlsafe_b64encode(b'{}').decode())

    def test_a_build_cache_prune_prints_what_it_reclaimed(self):
        self.routes[('POST', '/build/prune')] = (200, {'CachesDeleted': [], 'SpaceReclaimed': 0})
        result = self.pa_root('docker.pruneBuildCache')
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'Total:\t0B\n', b''))
        self.assertEqual(self.asked, [('POST', '/build/prune?all=1')])

    def test_the_prune_asks_for_the_window_and_the_space_the_cli_asked_for(self):
        self.routes[('POST', '/build/prune')] = (200, {'SpaceReclaimed': 1073741824})
        found = self.pa_root('docker.pruneBuildCacheUnusedFor', '3600')
        self.assertEqual(found.stdout, b'Total:\t1.074GB\n')
        self.assertEqual(self.asked_last()[1], '/build/prune?all=1&filters=%7B%22until%22%3A%5B%223600s%22%5D%7D')
        self.pa_root('docker.pruneBuildCacheAbove', '10737418240')
        self.assertEqual(self.asked_last()[1], '/build/prune?all=1&max-used-space=10737418240')
        self.pa_root('docker.pruneBuildCacheUntilFree', '2147483648')
        self.assertEqual(self.asked_last()[1], '/build/prune?all=1&min-free-space=2147483648')

    def test_an_own_image_the_host_has_is_not_pulled_again(self):
        self.routes[('GET', image_path('panelalpha/php:8.3'))] = (200, IMAGE)
        result = self.pa_root('host.ensureOwnImage', 'panelalpha/php:8.3', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual([method for method, _ in self.asked], ['GET'])

    def test_an_own_image_the_host_lacks_is_pulled_from_the_cache_registry_and_tagged(self):
        self.running_registries()
        self.routes[('POST', '/images/create')] = (200, chunks([{'status': 'Pulled'}]))
        self.routes[('POST', image_path('127.0.0.1:5000/panelalpha/php:8.3', 'tag'))] = (201, b'')
        result = self.pa_root('host.ensureOwnImage', 'panelalpha/php:8.3', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(self.asked, [
            ('GET', image_path('panelalpha/php:8.3')),
            ('GET', '/containers/panelalpha-cache-registry/json'),
            ('GET', '/containers/panelalpha-cache-registry-writer/json'),
            ('POST', '/images/create?fromImage=127.0.0.1%3A5000%2Fpanelalpha%2Fphp&tag=8.3'),
            ('POST', image_path('127.0.0.1:5000/panelalpha/php:8.3', 'tag') + '?repo=panelalpha%2Fphp&tag=8.3'),
        ])

    def test_a_registry_that_is_not_running_keeps_the_step_from_pulling(self):
        self.routes[('GET', '/containers/panelalpha-cache-registry/json')] = (
            200, {'Id': 'c' * 64, 'State': {'Running': False}},
        )
        self.routes[('GET', '/containers/panelalpha-cache-registry-writer/json')] = (200, {'State': {'Running': True}})
        result = self.pa_root('host.ensureOwnImage', 'panelalpha/php:8.3', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(self.asked, [
            ('GET', image_path('panelalpha/php:8.3')),
            ('GET', '/containers/panelalpha-cache-registry/json'),
        ])

    def test_a_step_the_cli_never_failed_answers_nothing_even_when_the_daemon_does(self):
        self.routes[('POST', '/images/create')] = (500, {'message': 'pull access denied'})
        result = self.pa_root('host.refreshPublicImage', 'node:22-bookworm-slim', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(self.asked_last(), ('POST', '/images/create?fromImage=node&tag=22-bookworm-slim'))

    def test_a_public_image_is_pulled_through_the_host_and_nothing_is_printed(self):
        self.routes[('POST', '/images/create')] = (200, chunks([{'status': 'Downloaded newer image for node:22'}]))
        result = self.pa_root('host.refreshPublicImage', 'node:22-bookworm-slim', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))

    def test_the_accounts_volume_and_images_are_removed_each_failure_ignored(self):
        self.routes[('DELETE', '/volumes/pa-nm-alice')] = (200, {'Name': 'pa-nm-alice'})
        self.routes[('DELETE', image_url('mysql:8.4'))] = (
            200, [{'Untagged': 'mysql:8.4'}, {'Deleted': 'sha256:' + '0a' * 32}],
        )
        result = self.pa_root('host.removeAccountDockerStore', 'pa-nm-alice', '1', 'mysql:8.4', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(self.asked, [
            ('DELETE', '/volumes/pa-nm-alice?force=1'),
            ('DELETE', image_url('mysql:8.4')),
        ])

    def test_a_removal_of_something_no_longer_there_is_no_failure(self):
        self.routes[('DELETE', '/volumes/pa-nm-alice')] = (404, {'message': 'no such volume'})
        self.routes[('DELETE', image_url('mysql:8.4'))] = (409, {'message': 'conflict: image is being used'})
        result = self.pa_root('host.removeAccountDockerStore', 'pa-nm-alice', '2', 'mysql:8.4', 'redis:7', host=True)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'', b''))
        self.assertEqual(self.asked, [
            ('DELETE', '/volumes/pa-nm-alice?force=1'),
            ('DELETE', image_url('mysql:8.4')),
            ('DELETE', image_url('redis:7')),
        ])

    def asked_last(self):
        return self.asked[-1]

    def running_registries(self):
        for container in ('panelalpha-cache-registry', 'panelalpha-cache-registry-writer'):
            self.routes[('GET', f'/containers/{container}/json')] = (200, {'Id': 'c' * 64, 'State': {'Running': True}})


if __name__ == '__main__':
    unittest.main()
