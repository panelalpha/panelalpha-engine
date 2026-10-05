<?php

namespace Tests\Unit\Deploy\Dind;

use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use App\System;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ShellStepTagTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const COMPOSE = '/home/acme/docker-compose.yml';

    public function test_a_dind_exec_step_carries_the_tag_in_its_environment(): void
    {
        $cmd = ['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind', 'docker', 'compose', 'up'];

        $this->assertSame(
            ['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-e', 'PANELALPHA_STEP=abc', '-T', 'dind', 'docker', 'compose', 'up'],
            $this->shell()->tagStep($cmd, 'abc')
        );
    }

    public function test_a_host_build_container_carries_the_tag_as_a_label(): void
    {
        $this->assertSame(
            ['sudo', 'docker', 'run', '--label', 'panelalpha.step=abc', '--rm', 'node:22', 'sh', '-c', 'npm ci'],
            $this->shell()->tagStep(['sudo', 'docker', 'run', '--rm', 'node:22', 'sh', '-c', 'npm ci'], 'abc')
        );
    }

    public function test_any_other_command_is_left_alone(): void
    {
        $cmd = ['sudo', 'sh', '-c', 'docker load < image.tar'];

        $this->assertSame($cmd, $this->shell()->tagStep($cmd, 'abc'));
    }

    public function test_the_step_label_is_the_command_inside_the_wrappers(): void
    {
        $this->assertSame(
            'npm run build',
            ShellOperations::stepLabel(['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind', 'su', '-s', '/bin/bash', 'acme', '-c', 'npm run build'])
        );
        $this->assertSame(
            'docker compose up -d --build',
            ShellOperations::stepLabel(['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind', 'docker', 'compose', 'up', '-d', '--build'])
        );
        $this->assertSame('host build: npm ci', ShellOperations::stepLabel(['sudo', 'docker', 'run', '--rm', 'node:22', 'sh', '-c', 'npm ci']));
    }

    /** #153: under sysbox `docker stats` misses BuildKit's CPU, so the account's cgroup is read. */
    public function test_a_silent_dind_step_is_busy_by_its_whole_cgroup(): void
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->with(Mockery::on(static fn (array $argv): bool => array_slice($argv, 0, 8) === ['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind']
                && str_contains(end($argv), '/sys/fs/cgroup/cpu.stat')), [], 30)
            ->once()
            ->andReturn("1680.10 1000000000\n1682.11 1002492000\n");
        $system->shouldNotReceive('exec')->with(Mockery::on(static fn (array $argv): bool => in_array('stats', $argv, true)), Mockery::any(), Mockery::any());

        $cmd = ['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind', 'docker', 'compose', 'up', '--build'];

        $this->assertTrue($this->shell($system)->stepIsBusy($cmd, 'abc'));
    }

    public function test_an_unreadable_cgroup_falls_back_to_docker_stats(): void
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->with(Mockery::on(static fn (array $argv): bool => str_contains(end($argv), '/sys/fs/cgroup/cpu.stat')), [], 30)
            ->andThrow(new \RuntimeException('exec failed'));
        $system->shouldReceive('exec')
            ->with(['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'stats', '--no-stream', '--format', '{{.CPUPerc}}', 'dind'], [], 30)
            ->once()
            ->andReturn("0.42%\n");

        $cmd = ['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind', 'docker', 'compose', 'up', '--build'];

        $this->assertFalse($this->shell($system)->stepIsBusy($cmd, 'abc'));
    }

    private function shell(?System $system = null): ShellOperations
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('composeFilePath')->andReturn(self::COMPOSE);
        if ($system !== null) {
            $dind->shouldReceive('system')->andReturn($system);
        }

        return new ShellOperations($dind);
    }
}
