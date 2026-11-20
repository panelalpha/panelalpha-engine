<?php

namespace Tests\Support;

use App\System\Privileged;

trait RootAccountDockerFixtures
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const IMAGE_ID = 'sha256:5ec23b7c622b00547753bc6ffd78085dd868a68aeb41c8e0a1418f033e13d96d';

    /** @return iterable<string, array{\Closure(): list<string>, list<string>}> */
    public static function requests(): iterable
    {
        yield 'ping' => [fn () => Privileged::accountDocker('alice')->ping(), self::asked('ping')];
        yield 'containers' => [
            fn () => Privileged::accountDocker('alice')->containers('project-app-1', '68e65fd5f2bf'),
            self::asked('containers', 'project-app-1', '68e65fd5f2bf'),
        ];
        yield 'listContainers' => [
            fn () => Privileged::accountDocker('alice')->listContainers(true, [
                'label' => ['com.docker.compose.project=project', 'com.docker.compose.service=app'],
                'publish' => ['8080'],
            ]),
            self::asked('listContainers', 'all', '3', 'label', 'com.docker.compose.project=project',
                'label', 'com.docker.compose.service=app', 'publish', '8080'),
        ];
        yield 'listContainers running only' => [
            fn () => Privileged::accountDocker('alice')->listContainers(false, []),
            self::asked('listContainers', '', '0'),
        ];
        yield 'images' => [
            fn () => Privileged::accountDocker('alice')->images('node:22-bookworm-slim', self::IMAGE_ID),
            self::asked('images', 'node:22-bookworm-slim', self::IMAGE_ID),
        ];
        yield 'hasImage' => [
            fn () => Privileged::accountDocker('alice')->hasImage('panelalpha-cache-registry:5000/node:22'),
            self::asked('hasImage', 'panelalpha-cache-registry:5000/node:22'),
        ];
        yield 'listImages' => [fn () => Privileged::accountDocker('alice')->listImages(), self::asked('listImages')];
        yield 'tag' => [
            fn () => Privileged::accountDocker('alice')->tag(self::IMAGE_ID, 'panelalpha-serving:5ec23b7c622b'),
            self::asked('tag', self::IMAGE_ID, 'panelalpha-serving:5ec23b7c622b'),
        ];
        yield 'removeImages' => [
            fn () => Privileged::accountDocker('alice')->removeImages(false, 'a:1', 'b:2'),
            self::asked('removeImages', '', 'a:1', 'b:2'),
        ];
        yield 'removeImages with force' => [
            fn () => Privileged::accountDocker('alice')->removeImages(true, 'project-app:pre-push'),
            self::asked('removeImages', 'force', 'project-app:pre-push'),
        ];
        yield 'pull' => [
            fn () => Privileged::accountDocker('alice')->pull('busybox:1.37@sha256:'.str_repeat('a', 64)),
            self::asked('pull', 'busybox:1.37@sha256:'.str_repeat('a', 64), ''),
        ];
        yield 'pull with a login' => [
            fn () => Privileged::accountDocker('alice')->pull('ghcr.io/acme/app:1', '/home/alice/.panelalpha-registry-0123456789abcdef'),
            self::asked('pull', 'ghcr.io/acme/app:1', '/home/alice/.panelalpha-registry-0123456789abcdef'),
        ];
        yield 'exec' => [
            fn () => Privileged::accountDocker('alice')->exec('app', ['php', '-v'], '33:33', '/var/www/html', ['HOME' => '/tmp']),
            self::asked('exec', 'app', '33:33', '/var/www/html', '1', 'HOME', '/tmp', 'php', '-v'),
        ];
        yield 'exec as the default user' => [
            fn () => Privileged::accountDocker('alice')->exec('app', ['sh', '-c', 'echo "$1"', 'sh', 'a b']),
            self::asked('exec', 'app', '', '', '0', 'sh', '-c', 'echo "$1"', 'sh', 'a b'),
        ];
        yield 'run' => [
            fn () => Privileged::accountDocker('alice')->run('busybox:1.37', [['project_db', '/data', 'ro'], ['/home/alice/.scratch', '/out', 'rw']], ['tar', '-czf', '/out/a.tgz', '.']),
            self::asked('run', 'busybox:1.37', '2', 'project_db', '/data', 'ro', '/home/alice/.scratch', '/out', 'rw', 'tar', '-czf', '/out/a.tgz', '.'),
        ];
        yield 'putEngineFile' => [
            fn () => Privileged::accountDocker('alice')->putEngineFile('app', 'wp-cli', '/tmp/panelalpha-wp-cli.phar.1'),
            self::asked('putEngineFile', 'app', 'wp-cli', '/tmp/panelalpha-wp-cli.phar.1'),
        ];
        yield 'readImageFile' => [
            fn () => Privileged::accountDocker('alice')->readImageFile('wordpress:6', '/etc/passwd'),
            self::asked('readImageFile', 'wordpress:6', '/etc/passwd'),
        ];
        yield 'listeningSockets' => [fn () => Privileged::accountDocker('alice')->listeningSockets('app'), self::asked('listeningSockets', 'app')];
        yield 'removeContainers' => [
            fn () => Privileged::accountDocker('alice')->removeContainers(true, true, 'a', 'b'),
            self::asked('removeContainers', 'force', 'volumes', 'a', 'b'),
        ];
        yield 'createVolume' => [fn () => Privileged::accountDocker('alice')->createVolume('project_db'), self::asked('createVolume', 'project_db')];
        yield 'volumes' => [fn () => Privileged::accountDocker('alice')->volumes('project_db'), self::asked('volumes', 'project_db')];
        yield 'listVolumes' => [fn () => Privileged::accountDocker('alice')->listVolumes(), self::asked('listVolumes')];
        yield 'pruneAnonymousVolumes' => [fn () => Privileged::accountDocker('alice')->pruneAnonymousVolumes(), self::asked('pruneAnonymousVolumes')];
        yield 'pruneImages' => [fn () => Privileged::accountDocker('alice')->pruneImages(), self::asked('pruneImages')];
        yield 'pruneBuildCache' => [fn () => Privileged::accountDocker('alice')->pruneBuildCache(), self::asked('pruneBuildCache')];
        yield 'pruneSystem' => [fn () => Privileged::accountDocker('alice')->pruneSystem(true), self::asked('pruneSystem', 'volumes')];
        yield 'buildCacheBytes' => [fn () => Privileged::accountDocker('alice')->buildCacheBytes(), self::asked('buildCacheBytes')];
        yield 'logTail' => [
            fn () => Privileged::accountDocker('alice')->logTail('project-app-1', 50),
            self::asked('logTail', 'project-app-1', '50', ''),
        ];
        yield 'logTail with timestamps' => [
            fn () => Privileged::accountDocker('alice')->logTail('project-app-1', 50, true),
            self::asked('logTail', 'project-app-1', '50', 'timestamps'),
        ];
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function refused(): iterable
    {
        yield 'username that is a path' => [fn () => Privileged::accountDocker('../bob')->ping()];
        yield 'username with a capital' => [fn () => Privileged::accountDocker('Alice')->ping()];
        yield 'container that is an option' => [fn () => Privileged::accountDocker('alice')->containers('-a')];
        yield 'image with a space' => [fn () => Privileged::accountDocker('alice')->images('node 22')];
        yield 'filter it does not send' => [fn () => Privileged::accountDocker('alice')->listContainers(true, ['before' => ['x']])];
        yield 'filter value that is an option' => [fn () => Privileged::accountDocker('alice')->listContainers(true, ['label' => ['-x']])];
        yield 'no log lines' => [fn () => Privileged::accountDocker('alice')->logTail('app', 0)];
        yield 'exec with nothing to run' => [fn () => Privileged::accountDocker('alice')->exec('app', [])];
        yield 'exec as a user that is an option' => [fn () => Privileged::accountDocker('alice')->exec('app', ['id'], '-u0')];
        yield 'run with a relative target' => [fn () => Privileged::accountDocker('alice')->run('busybox', [['v', 'data', 'rw']], ['true'])];
        yield 'run with a target holding a colon' => [fn () => Privileged::accountDocker('alice')->run('busybox', [['v', '/data:ro', 'rw']], ['true'])];
        yield 'run with a mode it does not know' => [fn () => Privileged::accountDocker('alice')->run('busybox', [['v', '/data', 'z']], ['true'])];
        yield 'environment name with a space' => [fn () => Privileged::accountDocker('alice')->exec('app', ['id'], '', '', ['A B' => 'x'])];
    }

    /** @return list<string> */
    private static function asked(string $action, string ...$values): array
    {
        return [self::CLIENT, 'account-docker.'.$action, '--', 'alice', ...$values];
    }
}
