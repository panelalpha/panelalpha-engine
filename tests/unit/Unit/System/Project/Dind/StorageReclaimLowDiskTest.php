<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Deploy\Dind\DindAccountStorage;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Services\ServiceManager;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * An account low on disk before a heavy build has its cache cleared first; one with room keeps it. After a
 * step stopped for disk an idle account's dockerd restarts, so the killed build's layer is let go, then all goes.
 */
class StorageReclaimLowDiskTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    private string $df = '';

    private string $buildCache = '';

    public function test_an_idle_account_low_on_disk_is_cleared_before_the_build(): void
    {
        $this->df = "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 104857600 103809024 1048576 99% /home/u\n";

        $this->reclaim()->reclaimIfNeeded();

        $this->assertSame([
            'account-docker.listContainers',
            'df',
            'account-docker.buildCacheBytes',
            'info Clearing inner Docker cache before heavy build',
            'account-docker.pruneSystem',
            'account-docker.pruneBuildCache',
        ], $this->events);
    }

    public function test_an_account_with_room_keeps_its_cache(): void
    {
        $this->df = "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 104857600 1048576 103809024 1% /home/u\n";
        $this->buildCache = '1024';

        $this->reclaim()->reclaimIfNeeded();

        $this->assertSame(['account-docker.listContainers', 'df', 'account-docker.buildCacheBytes'], $this->events);
    }

    public function test_an_idle_account_restarts_dockerd_then_prunes_everything_after_the_disk_limit(): void
    {
        $this->reclaim()->reclaimAfterDiskLimit();

        $this->assertSame([
            'account-docker.listContainers',
            'process stop docker',
            'process start docker',
            'await 10 2',
            'info Clearing inner Docker cache after disk-full build failure',
            'account-docker.pruneSystem',
            'account-docker.pruneBuildCache',
        ], $this->events);
    }

    private function reclaim(): StorageReclaim
    {
        $test = $this;
        $system = new class($test) extends System
        {
            public function __construct(private readonly StorageReclaimLowDiskTest $test) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->answer((array) $cmd);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->test->process((array) $cmd);
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                throw new \LogicException('No process may start: ' . implode(' ', (array) $cmd));
            }
        };

        $services = $this->createStub(ServiceManager::class);
        $services->method('stopArgv')->willReturnCallback(static fn (string $service): array => ['stop', $service]);
        $services->method('startArgv')->willReturnCallback(static fn (string $service): array => ['start', $service]);
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('storage')->willReturn(new DindAccountStorage());

        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('low-disk-test');
        $dind->method('homeDirPath')->willReturn('/home/u');
        $dind->method('engine')->willReturn($engine);
        $dind->method('services')->willReturn($services);
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('awaitReady')->willReturnCallback(function (int $tries, int $interval): void {
            $this->events[] = "await {$tries} {$interval}";
        });

        $host = $this->createStub(HostCommands::class);
        $host->method('logInfo')->willReturnCallback(function (string $message): void {
            $this->events[] = "info {$message}";
        });
        $host->method('logWarn')->willReturnCallback(function (string $message): void {
            $this->events[] = "warn {$message}";
        });
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);
        $inner->method('host')->willReturn($host);

        return new StorageReclaim($inner);
    }

    /** @param list<string> $cmd */
    public function answer(array $cmd): string
    {
        if (str_contains(implode(' ', $cmd), 'df -Pk')) {
            $this->events[] = 'df';

            return $this->df;
        }
        $action = (string) LocalRoot::action($cmd);
        $this->events[] = $action;

        return match ($action) {
            'account-docker.listContainers' => '[]',
            'account-docker.buildCacheBytes' => $this->buildCache,
            default => '',
        };
    }

    /** @param list<string> $cmd */
    public function process(array $cmd): Process
    {
        $this->events[] = 'process ' . implode(' ', array_slice($cmd, -2));

        return $this->createStub(Process::class);
    }
}
