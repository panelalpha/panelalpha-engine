<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\BuildContainer;
use Tests\Support\TestNetwork;

/** Broker requests for the cases in docker-cases.json, which the broker's own tests plan. */
final class DockerBrokerCases
{
    public const FILE = '/dockerfiles/root-broker/tests/docker-cases.json';

    private const ENGINE = '/opt/panelalpha/shared-hosting';

    private const ENGINE_FILE = self::ENGINE.'/docker-compose.yml';

    private const ACCOUNT_FILE = self::ENGINE.'/users/alice/docker-compose.yml';

    private const STEP = '0a1b2c3d4e5f';

    /**
     * @return list<array{name: string, host: bool, broker: list<string>}>
     */
    public static function all(): array
    {
        $cases = [];
        foreach (self::calls() as $name => [$call, $host]) {
            $cases[] = ['name' => $name, 'host' => $host, 'broker' => $host ? Privileged::onHost($call()) : $call()];
        }

        return $cases;
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function calls(): iterable
    {
        yield from self::docker();
        yield from self::builds();
        yield from self::compose();
        yield from self::engineServices();
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function docker(): iterable
    {
        $d = static fn () => Privileged::docker();
        $label = 'com.docker.compose.project.working_dir';
        $ids = ['0a1b2c3d4e5f', 'f5e4d3c2b1a0'];

        yield 'info' => [fn () => $d()->info(), false];
        yield 'registryMirrors' => [fn () => $d()->registryMirrors(), false];
        yield 'runningContainerNames' => [fn () => $d()->runningContainerNames(), false];
        yield 'containerImages' => [fn () => $d()->containerImages(), false];
        yield 'containerNamed' => [fn () => $d()->containerNamed('alice.1'), false];
        yield 'containerNamedWithLabel' => [fn () => $d()->containerNamedWithLabel('alice.1', $label, self::ENGINE.'/users/alice.1'), false];
        yield 'runningContainerIdsLabelled' => [fn () => $d()->runningContainerIdsLabelled($label), false];
        yield 'runningContainerIdsWithLabel' => [fn () => $d()->runningContainerIdsWithLabel('panelalpha.step', self::STEP), false];
        yield 'stoppedContainerIdsLabelled' => [fn () => $d()->stoppedContainerIdsLabelled($label), false];
        yield 'containersLabelled' => [fn () => $d()->containersLabelled($label), false];
        yield 'inspect' => [fn () => $d()->inspect(...$ids), false];
        yield 'isRunning' => [fn () => $d()->isRunning('panelalpha-cache-registry-writer'), false];
        yield 'startedAt' => [fn () => $d()->startedAt('alice'), false];
        yield 'nanoCpus' => [fn () => $d()->nanoCpus('alice'), false];
        yield 'containerNetworks' => [fn () => $d()->containerNetworks('alice'), false];
        yield 'cpuPercents' => [fn () => $d()->cpuPercents(...$ids), false];
        yield 'runningEnvironment' => [fn () => $d()->runningEnvironment('panelalpha-registry-proxy'), false];
        yield 'limitMemory' => [fn () => $d()->limitMemory('alice', 1536), false];
        yield 'limitCpu' => [fn () => $d()->limitCpu('alice', 1.5), false];
        yield 'restart' => [fn () => $d()->restart('panelalpha-registry-proxy'), false];
        yield 'forceRemoveContainer' => [fn () => $d()->forceRemoveContainer('alice'), false];
        yield 'forceRemoveContainers' => [fn () => $d()->forceRemoveContainers(...$ids), false];
        yield 'networkSubnetsAndGateways' => [fn () => $d()->networkSubnetsAndGateways('pash-tenants'), false];
        yield 'networkId' => [fn () => $d()->networkId('panelalpha-build'), false];
        yield 'createBridgeNetwork' => [fn () => $d()->createBridgeNetwork('panelalpha-build', 'br-pa-build', 'build'), false];
        yield 'connectNetwork' => [fn () => $d()->connectNetwork('pash-tenants', 'alice'), false];
        yield 'disconnectNetwork' => [fn () => $d()->disconnectNetwork('pash-default-network', 'alice'), false];
        yield 'pull' => [fn () => $d()->pull('ghcr.io/panelalpha/engine-user-dind:v2.2.0-20261028'), false];
        yield 'inspectManifest' => [fn () => $d()->inspectManifest('node:22-bookworm-slim'), false];
        yield 'inspectImage' => [fn () => $d()->inspectImage('ghcr.io/panelalpha/engine-user-dind:v2.2.0-20261028'), false];
        yield 'imageId' => [fn () => $d()->imageId(TestNetwork::LOOPBACK.':5000/panelalpha/php:8.3-cli-bookworm-pa1234abcd'), false];
        yield 'imageExposedPorts' => [fn () => $d()->imageExposedPorts('nginx:1.27'), false];
        yield 'imagesLastTagTime' => [fn () => $d()->imagesLastTagTime('panelalpha/php:8.3-cli', 'composer:2'), false];
        yield 'imageNames' => [fn () => $d()->imageNames(), false];
        yield 'imageNamesWithSizes' => [fn () => $d()->imageNamesWithSizes(), false];
        yield 'removeImages' => [fn () => $d()->removeImages('sha256:'.str_repeat('0a', 32)), false];
        yield 'forceRemoveImages' => [fn () => $d()->forceRemoveImages('panelalpha/php:8.2-cli', 'node:20'), false];
        yield 'buildFromStdin' => [fn () => $d()->buildFromStdin('acme/tool:1', ['VERSION' => '1.2', 'NOTE' => "it's\nlong"], true), false];
        yield 'buildFromStdin, no arguments' => [fn () => $d()->buildFromStdin('acme/tool:1', [], false), false];
        yield 'pruneBuildCache' => [fn () => $d()->pruneBuildCache(), false];
        yield 'pruneBuildCacheUnusedFor' => [fn () => $d()->pruneBuildCacheUnusedFor(3600), false];
        yield 'pruneBuildCacheAbove' => [fn () => $d()->pruneBuildCacheAbove(10737418240), false];
        yield 'pruneBuildCacheUntilFree' => [fn () => $d()->pruneBuildCacheUntilFree(2147483648), false];
        yield 'readFileFromImage' => [fn () => $d()->readFileFromImage('panelalpha/php:8.3-cli-bookworm-pa1234abcd', '/etc/passwd'), false];
        yield 'checkLiteSpeedSerial' => [fn () => $d()->checkLiteSpeedSerial(self::ENGINE.'/webserver-config/litespeed/serial.no.tmp'), false];
        yield 'registryGarbageCollect' => [
            fn () => $d()->registryGarbageCollect('panelalpha-cache-registry-writer', '/etc/distribution/config.yml'),
            false,
        ];
        yield 'registryTagLinks' => [
            fn () => $d()->registryTagLinks('panelalpha-cache-registry-writer', '/var/lib/registry/docker/registry/v2/repositories'),
            false,
        ];
        yield 'removeRegistryRepository' => [
            fn () => $d()->removeRegistryRepository('panelalpha-cache-registry-writer', '/var/lib/registry/docker/registry/v2/repositories', 'panelalpha/php'),
            false,
        ];
        yield 'registryStorageKilobytes' => [fn () => $d()->registryStorageKilobytes('panelalpha-cache-registry-writer'), false];
        yield 'wipeRegistryStorage' => [fn () => $d()->wipeRegistryStorage('panelalpha-registry-proxy'), false];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function builds(): iterable
    {
        $d = static fn () => Privileged::docker();
        $node = new BuildContainer(
            '1001:1001',
            '2048m',
            'panelalpha-build',
            [
                '/app' => '/home/alice/project',
                '/var/cache/pa-js' => '/var/cache/panelalpha/projects/alice',
                '/app/node_modules' => '/var/cache/panelalpha/projects/alice/node_modules',
            ],
            ['/etc/ssl/certs/ca-certificates.crt' => '/etc/ssl/certs/ca-certificates.crt'],
            '/app',
            ['SSL_CERT_FILE' => '/etc/ssl/certs/ca-certificates.crt', 'CI' => 'true', 'NODE_OPTIONS' => '--max-old-space-size=1536'],
            'node:22-bookworm-slim',
        );
        $php = new BuildContainer(
            '1001:1002',
            '2g',
            null,
            ['/app' => '/home/alice/project', '/var/cache/pa-composer' => '/var/cache/panelalpha/projects/alice/composer'],
            [],
            '/app/web',
            ['COMPOSER_HOME' => '/var/cache/pa-composer', 'COMPOSER_ALLOW_SUPERUSER' => '1'],
            'panelalpha/php:8.3-apache-bookworm-pa1234abcd',
        );
        $composer = new BuildContainer('1001:1002', '2048m', 'panelalpha-build', ['/app' => '/home/alice/project'], [], '/app', [], 'composer:2');
        $script = "trap 'echo oom' EXIT; npm ci && npm run build";

        yield 'nodeBuild' => [fn () => $d()->nodeBuild($node, $script), false];
        yield 'nodeBuild, labelled as a step' => [fn () => $d()->withRunLabel($d()->nodeBuild($node, $script), 'panelalpha.step', self::STEP), false];
        yield 'phpBuild' => [fn () => $d()->phpBuild($php, 'composer install --no-dev'), false];
        yield 'composerInstall' => [fn () => $d()->composerInstall($composer, 'composer install --no-scripts'), false];
        yield 'readFileFromImage, labelled as a step' => [
            fn () => $d()->withRunLabel($d()->readFileFromImage('panelalpha/php:8.3-cli', '/etc/group'), 'panelalpha.step', self::STEP),
            false,
        ];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function compose(): iterable
    {
        $account = static fn () => Privileged::compose(self::ACCOUNT_FILE);
        $engine = static fn () => Privileged::compose(self::ENGINE_FILE);

        yield 'up' => [fn () => $account()->up(), false];
        yield 'up, recreating' => [fn () => $account()->up(true), false];
        yield 'down' => [fn () => $account()->down(), false];
        yield 'downWithVolumes' => [fn () => $account()->downWithVolumes(), false];
        yield 'runningServices' => [fn () => $account()->runningServices(), false];
        yield 'build' => [fn () => $account()->build(), false];
        yield 'restart' => [fn () => $account()->restart('dind'), false];
        yield 'restart, sites-http from the host' => [fn () => $engine()->restart('sites-http'), true];
        yield 'serviceContainers' => [fn () => $engine()->serviceContainers('sites-http'), false];
        yield 'cpuPercent' => [fn () => $account()->cpuPercent('dind'), false];
        yield 'execWithTty' => [fn () => $account()->execWithTty('dind', ['bash'], user: '1001:1001', workDir: '/home/alice/project'), false];
        yield 'engineUpWithoutDependencies' => [fn () => Privileged::engineCompose(self::ENGINE)->upWithoutDependencies('registry-proxy'), false];
        yield 'sitesDbCacheSizes' => [fn () => Privileged::engineCompose(self::ENGINE)->sitesDbCacheSizes(), false];
    }

    /** @return iterable<string, array{\Closure(): list<string>, bool}> */
    private static function engineServices(): iterable
    {
        $s = static fn () => Privileged::engineServices(self::ENGINE_FILE);
        $testDir = self::ENGINE.'/config/modsecurity/.custom-rules-test-0a1b2c3d4e5f';
        $logins = ['alice', 'bob_2'];

        yield 'engineRunningServices' => [fn () => $s()->runningServices(), false];
        yield 'nginxConfigTest' => [fn () => $s()->nginxConfigTest(), true];
        yield 'apacheConfigTest' => [fn () => $s()->apacheConfigTest(), true];
        yield 'openTcpSockets' => [fn () => $s()->openTcpSockets(), false];
        yield 'nginxReload' => [fn () => $s()->nginxReload(), false];
        yield 'modsecurityNginxCheck' => [fn () => $s()->modsecurityNginxCheck($testDir), true];
        yield 'modsecurityApacheCheck' => [fn () => $s()->modsecurityApacheCheck($testDir), true];
        yield 'apacheGraceful' => [fn () => $s()->apacheGraceful(), false];
        yield 'apacheRestart' => [fn () => $s()->apacheRestart(), false];
        yield 'litespeedControl' => [fn () => $s()->litespeedControl('condrestart'), false];
        yield 'litespeedVersion' => [fn () => $s()->litespeedVersion(), false];
        yield 'litespeedConfigTest' => [fn () => $s()->litespeedConfigTest(), false];
        yield 'litespeedResetAdminPassword' => [fn () => $s()->litespeedResetAdminPassword(), false];
        yield 'eximUpdateConfig' => [fn () => $s()->eximUpdateConfig(), false];
        yield 'eximRestart' => [fn () => $s()->eximRestart(), false];
        yield 'eximSendTestEmail' => [fn () => $s()->eximSendTestEmail("o'brien+test@example.com"), false];
        yield 'sftpSyncLogins' => [fn () => $s()->sftpSyncLogins(), false];
        yield 'sftpKillSessions' => [fn () => $s()->sftpKillSessions($logins), false];
        yield 'ftpTouchPasswordFile' => [fn () => $s()->ftpTouchPasswordFile(), false];
        yield 'ftpMakeDatabase' => [fn () => $s()->ftpMakeDatabase(), false];
        yield 'ftpShowUser' => [fn () => $s()->ftpShowUser('web@example.com'), false];
        yield 'ftpSetQuota' => [fn () => $s()->ftpSetQuota('web@example.com', '512'), false];
        yield 'ftpSetQuota, cleared' => [fn () => $s()->ftpSetQuota('web@example.com', ''), false];
        yield 'ftpDeleteUser' => [fn () => $s()->ftpDeleteUser('web@example.com'), false];
        yield 'ftpAddUser' => [fn () => $s()->ftpAddUser("it's@example.com", 1001, 1002, '/home/ftpuser/alice/public_html', '512'), false];
        yield 'ftpAddUser, no quota' => [fn () => $s()->ftpAddUser('web@example.com', 1001, 1001, '/home/ftpuser/alice', ''), false];
        yield 'ftpSetPassword' => [fn () => $s()->ftpSetPassword('web@example.com'), false];
        yield 'lighthouseAudit' => [
            fn () => $s()->lighthouseAudit('https://alice.example.com/', true, 'MAP alice.example.com '.TestNetwork::public(7).', MAP localhost ~NOTFOUND, MAP 10.* ~NOTFOUND'),
            false,
        ];
        yield 'lighthouseAudit, mobile' => [fn () => $s()->lighthouseAudit('https://alice.example.com/a?b=1', false, 'MAP *.internal ~NOTFOUND'), false];
    }
}
