<?php

namespace Tests\Integration\Deploy\Dind;

use App\System;
use App\System\Privileged;
use App\System\Privileged\BuildContainer;
use App\System\Privileged\RootAccountContainer;
use App\System\Privileged\RootCompose;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class ShellStepTagTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const COMPOSE = '/opt/panelalpha/shared-hosting/users/acme/docker-compose.yml';

    public function test_a_dind_exec_step_carries_the_tag_in_its_environment(): void
    {
        $cmd = $this->container()->exec(['docker', 'compose', 'up'], environment: ['DOCKER_CONFIG' => '/home/acme/.panelalpha-docker']);

        $this->assertSame(
            [
                'pa-root', 'account-container.exec', '--', 'acme', '', '',
                '2', 'PANELALPHA_STEP', 'abc', 'DOCKER_CONFIG', '/home/acme/.panelalpha-docker',
                'docker', 'compose', 'up',
            ],
            LocalRoot::plain($this->shell()->tagStep($cmd, 'abc'))
        );
    }

    public function test_another_accounts_exec_is_left_alone(): void
    {
        $cmd = Privileged::accountContainer('bob')->exec(['docker', 'compose', 'up']);

        $this->assertSame($cmd, $this->shell()->tagStep($cmd, 'abc'));
    }

    public function test_a_host_build_container_carries_the_tag_as_a_label(): void
    {
        $tagged = $this->shell()->tagStep($this->hostBuild('npm ci'), 'abc');
        $build = LocalRoot::build($tagged);

        $this->assertSame('docker.nodeBuild', LocalRoot::action($tagged));
        $this->assertSame('abc', $build['labels']['panelalpha.step'] ?? null);
        $this->assertSame('node:22', $build['image']);
        $this->assertSame('npm ci', $build['script']);
    }

    public function test_any_other_command_is_left_alone(): void
    {
        $cmd = $this->compose()->cpuPercent('dind');

        $this->assertSame($cmd, $this->shell()->tagStep($cmd, 'abc'));
    }

    public function test_the_step_label_is_the_command_inside_the_wrappers(): void
    {
        $this->assertSame(
            'npm run build',
            ShellOperations::stepLabel($this->container()->exec(['su', '-s', '/bin/bash', 'acme', '-c', 'npm run build']))
        );
        $this->assertSame(
            'docker compose up -d --build',
            ShellOperations::stepLabel($this->container()->exec(['docker', 'compose', 'up', '-d', '--build'], environment: ['DOCKER_CONFIG' => '/home/acme/.panelalpha-docker']))
        );
        $this->assertSame('host build: npm ci', ShellOperations::stepLabel($this->hostBuild('npm ci')));
    }

    /** Under sysbox `docker stats` misses BuildKit's CPU, so the account's cgroup is read. */
    public function test_a_silent_dind_step_is_busy_by_its_whole_cgroup(): void
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->with(Mockery::on(static fn (array $argv): bool => LocalRoot::accountEnvironment($argv) === ['DOCKER_CONFIG' => '/home/acme/.panelalpha-docker']
                && str_contains(end($argv), '/sys/fs/cgroup/cpu.stat')), [], 30)
            ->once()
            ->andReturn("1680.10 1000000000\n1682.11 1002492000\n");
        $system->shouldNotReceive('exec')->with(Mockery::on(static fn (array $argv): bool => LocalRoot::action($argv) === 'compose.cpuPercent'), Mockery::any(), Mockery::any());

        $cmd = $this->container()->exec(['docker', 'compose', 'up', '--build']);

        $this->assertTrue($this->shell($system)->stepIsBusy($cmd, 'abc'));
    }

    public function test_an_unreadable_cgroup_falls_back_to_docker_stats(): void
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->with(Mockery::on(static fn (array $argv): bool => str_contains(end($argv), '/sys/fs/cgroup/cpu.stat')), [], 30)
            ->andThrow(new \RuntimeException('exec failed'));
        $system->shouldReceive('exec')
            ->with(Mockery::on(static fn (array $argv): bool => LocalRoot::action($argv) === 'compose.cpuPercent'
                && LocalRoot::values($argv) === [self::COMPOSE, 'dind']), [], 30)
            ->once()
            ->andReturn("0.42%\n");

        $cmd = $this->container()->exec(['docker', 'compose', 'up', '--build']);

        $this->assertFalse($this->shell($system)->stepIsBusy($cmd, 'abc'));
    }

    private function compose(): RootCompose
    {
        return Privileged::compose(self::COMPOSE);
    }

    private function container(): RootAccountContainer
    {
        return Privileged::accountContainer('acme');
    }

    /** @return list<string> */
    private function hostBuild(string $script): array
    {
        return Privileged::docker()->nodeBuild(
            new BuildContainer('1001:1001', '2g', null, ['/app' => '/home/acme/project'], [], '/app', [], 'node:22'),
            $script
        );
    }

    private function shell(?System $system = null): ShellOperations
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('composeFilePath')->andReturn(self::COMPOSE);
        $dind->shouldReceive('homeDirPath')->andReturn('/home/acme');
        $dind->shouldReceive('username')->andReturn('acme');
        if ($system !== null) {
            $dind->shouldReceive('system')->andReturn($system);
        }

        return new ShellOperations($dind);
    }
}
