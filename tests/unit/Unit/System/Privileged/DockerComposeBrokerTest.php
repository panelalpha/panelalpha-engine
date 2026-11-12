<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\BuildContainer;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootCompose;
use App\System\Privileged\RootDocker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DockerComposeBrokerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const ACCOUNT_FILE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml';

    public function test_the_cases_the_broker_is_checked_against_are_the_gateways_own(): void
    {
        $file = (string) file_get_contents(dirname(__DIR__, 5).DockerBrokerCases::FILE);

        $cases = json_decode($file, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_map(static fn (array $case): array => array_diff_key($case, ['sudo' => true]), $cases), DockerBrokerCases::all());
    }

    public function test_every_case_asks_the_broker_and_none_runs_sudo(): void
    {
        foreach (DockerBrokerCases::all() as $case) {
            $this->assertSame(self::CLIENT, $case['broker'][0], $case['name']);
            $this->assertNotContains('sudo', $case['broker'], $case['name']);
        }
    }

    public function test_a_cpu_limit_reaches_docker_as_a_plain_decimal(): void
    {
        $this->assertSame([self::CLIENT, 'docker.limitCpu', '--', 'alice', '3'], Privileged::docker()->limitCpu('alice', 3.0));
        $this->assertSame([self::CLIENT, 'docker.limitCpu', '--', 'alice', '1.5'], Privileged::docker()->limitCpu('alice', 1.5));
        $this->assertSame([self::CLIENT, 'docker.limitCpu', '--', 'alice', '64'], Privileged::docker()->limitCpu('alice', 64));
        $this->assertSame([self::CLIENT, 'docker.limitCpu', '--', 'alice', '0.000001'], Privileged::docker()->limitCpu('alice', 0.000001));
    }

    public function test_a_build_carries_its_container_as_counted_lists(): void
    {
        $build = new BuildContainer('1001:1001', '2g', null, ['/app' => '/home/a/project'], [], '/app', ['CI' => 'true'], 'node:22');

        $this->assertSame(
            [self::CLIENT, 'docker.nodeBuild', '--', '0', '1001:1001', '2g', '', '1', '/app', '/home/a/project', '0', '/app', '1', 'CI', 'true', 'node:22', 'npm ci'],
            Privileged::docker()->nodeBuild($build, 'npm ci')
        );
    }

    public function test_a_step_label_goes_first_among_a_runs_labels(): void
    {
        $docker = Privileged::docker();
        $run = $docker->readFileFromImage('php:8.3', '/etc/passwd');

        $this->assertTrue($docker->isRun($run));
        $this->assertSame(
            [self::CLIENT, 'docker.readFileFromImage', '--', '2', 'b', '2', 'a', '1', 'php:8.3', '/etc/passwd'],
            $docker->withRunLabel($docker->withRunLabel($run, 'a', '1'), 'b', '2')
        );
        $this->assertFalse($docker->isRun($docker->info()));
    }

    public function test_a_label_or_variable_is_added_only_to_a_run_or_an_exec(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::docker()->withRunLabel(Privileged::docker()->info(), 'a', 'b');
    }

    public function test_a_broker_request_on_the_host_asks_the_broker_to_enter_its_namespaces(): void
    {
        $restart = Privileged::compose('/opt/panelalpha/shared-hosting/docker-compose.yml')->restart('sites-http');

        $this->assertSame([self::CLIENT, '--host', ...array_slice($restart, 1)], Privileged::onHost($restart));
        $this->assertSame(Privileged::onHost($restart), Privileged::onHost(Privileged::onHost($restart)));
    }

    public function test_a_request_with_a_terminal_never_moves_into_the_hosts_namespaces(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::onHost(Privileged::compose(self::ACCOUNT_FILE)->execWithTty('php', ['bash']));
    }

    public function test_the_ftp_password_travels_in_the_environment_never_in_the_request(): void
    {
        $this->assertSame(
            [self::CLIENT, '--env', 'PA_FTP_PASSWORD', 'compose.ftpSetPassword', '--', '/e/docker-compose.yml', 'web@example.com'],
            Privileged::engineServices('/e/docker-compose.yml')->ftpSetPassword('web@example.com')
        );
    }

    public function test_the_litespeed_password_travels_in_the_environment_never_in_the_request(): void
    {
        $this->assertSame(
            [self::CLIENT, '--env', 'PA_LITESPEED_ADMIN_PASSWORD', 'compose.litespeedResetAdminPassword', '--', '/e/docker-compose.yml'],
            Privileged::engineServices('/e/docker-compose.yml')->litespeedResetAdminPassword()
        );
    }

    public function test_an_ftp_account_with_roots_id_is_refused(): void
    {
        $refused = 0;
        foreach ([[0, 1001], [1001, 0], [-1, 1001]] as [$uid, $gid]) {
            try {
                Privileged::engineServices('/e/docker-compose.yml')->ftpAddUser('web@example.com', $uid, $gid, '/home/ftpuser/a', '');
            } catch (InvalidArgument) {
                $refused++;
            }
        }

        $this->assertSame(3, $refused);
    }

    public function test_an_exec_with_a_terminal_asks_the_broker_to_relay_one(): void
    {
        $this->assertSame(
            [self::CLIENT, '--pty', 'compose.execWithTty', '--', self::ACCOUNT_FILE, '1', 'ADMIN_PASS', "s3 cr'3t", '', '', 'sites-http', 'bash', '-c', 'x'],
            Privileged::compose(self::ACCOUNT_FILE)->execWithTty('sites-http', ['bash', '-c', 'x'], ['ADMIN_PASS' => "s3 cr'3t"])
        );
        $this->assertSame(
            [self::CLIENT, '--pty', 'compose.execWithTty', '--', self::ACCOUNT_FILE, '0', '', '/home/u/project', 'php', 'bash'],
            Privileged::compose(self::ACCOUNT_FILE)->execWithTty('php', ['bash'], workDir: '/home/u/project')
        );
    }

    /** @return iterable<string, array{callable(RootDocker): mixed}> */
    public static function refusedDocker(): iterable
    {
        $build = static fn (array $overrides): BuildContainer => new BuildContainer(...[
            'user' => '1001:1001', 'memory' => '2048m', 'network' => null, 'mounts' => ['/app' => '/home/alice/project'],
            'readOnlyMounts' => [], 'workdir' => '/app', 'environment' => [], 'image' => 'node:22', ...$overrides,
        ]);

        yield 'container name that is an option' => [fn (RootDocker $d) => $d->containerNamedWithLabel('-a', 'wd', '/x')];
        yield 'named container that is a pattern' => [fn (RootDocker $d) => $d->containerNamed('a|b')];
        yield 'label with a quote' => [fn (RootDocker $d) => $d->containersLabelled('wd"}}{{.ID')];
        yield 'label value with a line break' => [fn (RootDocker $d) => $d->containerNamedWithLabel('acct', 'wd', "/x\n")];
        yield 'running label that is an option' => [fn (RootDocker $d) => $d->runningContainerIdsLabelled('-q')];
        yield 'stopped label with a space' => [fn (RootDocker $d) => $d->stoppedContainerIdsLabelled('a b')];
        yield 'inspect format that is an option' => [fn (RootDocker $d) => $d->inspect('--help', 'a')];
        yield 'inspected container with a semicolon' => [fn (RootDocker $d) => $d->inspect('{{.Name}}', 'a;id')];
        yield 'running check of an option' => [fn (RootDocker $d) => $d->isRunning('--size')];
        yield 'start time of an option' => [fn (RootDocker $d) => $d->startedAt('-s')];
        yield 'environment of an option' => [fn (RootDocker $d) => $d->runningEnvironment('--type=image')];
        yield 'memory of an option' => [fn (RootDocker $d) => $d->limitMemory('--cpus=8', 512)];
        yield 'no memory' => [fn (RootDocker $d) => $d->limitMemory('acct', 0)];
        yield 'cpu of an option' => [fn (RootDocker $d) => $d->limitCpu('--cpus=8', 1.5)];
        yield 'a negative cpu' => [fn (RootDocker $d) => $d->limitCpu('acct', -1)];
        yield 'no cpu, which docker reads as no change' => [fn (RootDocker $d) => $d->limitCpu('acct', 0)];
        yield 'a cpu too small to write' => [fn (RootDocker $d) => $d->limitCpu('acct', 0.0000001)];
        yield 'an infinite cpu' => [fn (RootDocker $d) => $d->limitCpu('acct', INF)];
        yield 'build cache unused for negative seconds' => [fn (RootDocker $d) => $d->pruneBuildCacheUnusedFor(-5)];
        yield 'build cache above negative bytes' => [fn (RootDocker $d) => $d->pruneBuildCacheAbove(-1)];
        yield 'build cache until negative bytes free' => [fn (RootDocker $d) => $d->pruneBuildCacheUntilFree(-1)];
        yield 'restart of an option' => [fn (RootDocker $d) => $d->restart('-t')];
        yield 'removal of an option' => [fn (RootDocker $d) => $d->forceRemoveContainer('-v')];
        yield 'removal of containers with an option' => [fn (RootDocker $d) => $d->forceRemoveContainers('0a1b2c', '-v')];
        yield 'networks of an option' => [fn (RootDocker $d) => $d->containerNetworks('--size')];
        yield 'cpu of a name with a space' => [fn (RootDocker $d) => $d->cpuPercents('a b')];
        yield 'label value that is an option' => [fn (RootDocker $d) => $d->runningContainerIdsWithLabel('panelalpha.step', '-a')];
        yield 'label name with a space' => [fn (RootDocker $d) => $d->runningContainerIdsWithLabel('a b', 'x')];
        yield 'subnets of an option' => [fn (RootDocker $d) => $d->networkSubnetsAndGateways('--verbose')];
        yield 'label on a command that is no run' => [fn (RootDocker $d) => $d->withRunLabel(['docker', 'run', 'x'], 'panelalpha.step', 'a')];
        yield 'run label value that is an option' => [fn (RootDocker $d) => $d->withRunLabel($d->readFileFromImage('nginx:1', '/etc/x'), 'panelalpha.step', '-a')];
        yield 'network id of an option' => [fn (RootDocker $d) => $d->networkId('-f')];
        yield 'bridge name with a space' => [fn (RootDocker $d) => $d->createBridgeNetwork('pa', 'br x', 'build')];
        yield 'network role that is an option' => [fn (RootDocker $d) => $d->createBridgeNetwork('pa', 'br0', '--internal')];
        yield 'network that is an option' => [fn (RootDocker $d) => $d->createBridgeNetwork('--internal', 'br0', 'build')];
        yield 'connected container that is an option' => [fn (RootDocker $d) => $d->connectNetwork('pash', '--alias=x')];
        yield 'disconnected network with a semicolon' => [fn (RootDocker $d) => $d->disconnectNetwork('pash;id', 'acct')];
        yield 'pull of an option' => [fn (RootDocker $d) => $d->pull('--all-tags')];
        yield 'manifest of an image with a space' => [fn (RootDocker $d) => $d->inspectManifest('nginx latest')];
        yield 'image inspect of an option' => [fn (RootDocker $d) => $d->inspectImage('--all')];
        yield 'image id of an option' => [fn (RootDocker $d) => $d->imageId('-f')];
        yield 'exposed ports of uppercase' => [fn (RootDocker $d) => $d->imageExposedPorts('Nginx:1')];
        yield 'tag time of an option' => [fn (RootDocker $d) => $d->imagesLastTagTime('a:1', '--size')];
        yield 'removal of an image option' => [fn (RootDocker $d) => $d->removeImages('--force')];
        yield 'forced removal of a quote' => [fn (RootDocker $d) => $d->forceRemoveImages("a'b")];
        yield 'build tag that is an option' => [fn (RootDocker $d) => $d->buildFromStdin('--network=host', [], false)];
        yield 'build arg name with a space' => [fn (RootDocker $d) => $d->buildFromStdin('reg/x:1', ['A B' => '1'], false)];
        yield 'node build image that is an option' => [fn (RootDocker $d) => $d->nodeBuild($build(['image' => '--privileged']), 'true')];
        yield 'node build user with a space' => [fn (RootDocker $d) => $d->nodeBuild($build(['user' => 'root root']), 'true')];
        yield 'node build memory that is an option' => [fn (RootDocker $d) => $d->nodeBuild($build(['memory' => '--privileged']), 'true')];
        yield 'node build network that is an option' => [fn (RootDocker $d) => $d->nodeBuild($build(['network' => '--privileged']), 'true')];
        yield 'php build mount climbing out' => [fn (RootDocker $d) => $d->phpBuild($build(['mounts' => ['/app' => '/home/alice/../bob']]), 'true')];
        yield 'php build mount with a colon' => [fn (RootDocker $d) => $d->phpBuild($build(['mounts' => ['/app' => '/home/a:/etc']]), 'true')];
        yield 'php build relative target' => [fn (RootDocker $d) => $d->phpBuild($build(['mounts' => ['app' => '/home/alice']]), 'true')];
        yield 'composer read-only mount relative' => [fn (RootDocker $d) => $d->composerInstall($build(['readOnlyMounts' => ['/x' => 'etc']]), 'true')];
        yield 'composer workdir relative' => [fn (RootDocker $d) => $d->composerInstall($build(['workdir' => 'app']), 'true')];
        yield 'composer variable name that is an option' => [fn (RootDocker $d) => $d->composerInstall($build(['environment' => ['-e' => 'x']]), 'true')];
        yield 'read from an image option' => [fn (RootDocker $d) => $d->readFileFromImage('--privileged', '/etc/passwd')];
        yield 'read a relative path' => [fn (RootDocker $d) => $d->readFileFromImage('nginx:1', 'etc/passwd')];
        yield 'serial file with a colon' => [fn (RootDocker $d) => $d->checkLiteSpeedSerial('/opt/x:/etc/shadow')];
        yield 'garbage-collect in an option' => [fn (RootDocker $d) => $d->registryGarbageCollect('-u', '/etc/c.yml')];
        yield 'garbage-collect config relative' => [fn (RootDocker $d) => $d->registryGarbageCollect('writer', 'c.yml')];
        yield 'tag links under ..' => [fn (RootDocker $d) => $d->registryTagLinks('writer', '/var/lib/../../etc')];
        yield 'storage size in an option' => [fn (RootDocker $d) => $d->registryStorageKilobytes('--privileged')];
        yield 'wipe in a name with a semicolon' => [fn (RootDocker $d) => $d->wipeRegistryStorage('proxy;id')];
    }

    /** @param  callable(RootDocker): mixed  $build */
    #[DataProvider('refusedDocker')]
    public function test_a_docker_value_the_command_could_misread_is_refused_before_the_broker_is_asked(callable $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(Privileged::docker());
    }

    /** @return iterable<string, array{string, callable(RootCompose): mixed}> */
    public static function refusedCompose(): iterable
    {
        $file = '/opt/engine/docker-compose.yml';

        yield 'relative compose file' => ['docker-compose.yml', fn (RootCompose $c) => $c->up()];
        yield 'compose file climbing out' => ['/opt/engine/../../etc/x.yml', fn (RootCompose $c) => $c->down()];
        yield 'restart of an option' => [$file, fn (RootCompose $c) => $c->restart('--timeout=0')];
        yield 'containers of a service with a space' => [$file, fn (RootCompose $c) => $c->serviceContainers('a b')];
        yield 'cpu of a service that is an option' => [$file, fn (RootCompose $c) => $c->cpuPercent('--all')];
        yield 'tty variable name with a space' => [$file, fn (RootCompose $c) => $c->execWithTty('php', ['true'], ['A B' => 'x'])];
        yield 'tty user with a space' => [$file, fn (RootCompose $c) => $c->execWithTty('php', ['true'], user: 'root root')];
        yield 'tty workdir relative' => [$file, fn (RootCompose $c) => $c->execWithTty('php', ['true'], workDir: 'home/u')];
        yield 'tty service that is an option' => [$file, fn (RootCompose $c) => $c->execWithTty('--privileged', ['true'])];
    }

    /** @param  callable(RootCompose): mixed  $build */
    #[DataProvider('refusedCompose')]
    public function test_a_compose_value_the_command_could_misread_is_refused_before_the_broker_is_asked(string $file, callable $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(Privileged::compose($file));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedEngineCompose(): iterable
    {
        yield 'service that is an option' => ['/opt/engine', '--build'];
        yield 'relative project directory' => ['opt/engine', 'registry-proxy'];
    }

    #[DataProvider('refusedEngineCompose')]
    public function test_an_engine_compose_value_the_command_could_misread_is_refused_before_the_broker_is_asked(string $directory, string $service): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::engineCompose($directory)->upWithoutDependencies($service);
    }
}
