<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * After a step is stopped for disk, the account gets back what
 * the step took. Measured: the killed build's 1.2 GB layer stayed leased and
 * unprunable until the inner daemon restarted.
 */
class StorageReclaimAfterDiskLimitTest extends TestCase
{
    private function reclaim(bool $idle): StorageReclaim
    {
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($this->createStub(Dind::class));

        return new class ($inner, $idle) extends StorageReclaim {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(InnerDocker $inner, private bool $idle)
            {
                parent::__construct($inner);
            }

            public function canAggressivelyReclaim(): bool
            {
                return $this->idle;
            }

            public function reclaim(bool $emergency): void
            {
                $this->calls[] = 'prune everything' . ($emergency ? ' (emergency)' : '');
            }

            protected function restartEngine(): void
            {
                $this->calls[] = 'restart dockerd';
            }
        };
    }

    public function test_an_idle_account_restarts_its_daemon_before_pruning_everything(): void
    {
        $reclaim = $this->reclaim(true);

        $reclaim->reclaimAfterDiskLimit();

        $this->assertSame(['restart dockerd', 'prune everything (emergency)'], $reclaim->calls);
    }

    public function test_a_running_app_keeps_its_daemon_and_its_images(): void
    {
        $asked = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$asked): string {
            $asked[] = (string) LocalRoot::action((array) $cmd);

            return '';
        });
        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('disk-limit-test');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);

        $reclaim = new class ($inner) extends StorageReclaim {
            public bool $restarted = false;

            public function canAggressivelyReclaim(): bool
            {
                return false;
            }

            protected function restartEngine(): void
            {
                $this->restarted = true;
            }
        };

        $reclaim->reclaimAfterDiskLimit();

        $this->assertSame(['account-docker.pruneBuildCache', 'account-docker.pruneImages'], $asked);
        $this->assertFalse($reclaim->restarted);
    }
}
