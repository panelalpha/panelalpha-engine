<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Dind\DindAccountStorage;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use PHPUnit\Framework\TestCase;

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
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('storage')->willReturn(new DindAccountStorage());
        $dind = $this->createStub(Dind::class);
        $dind->method('engine')->willReturn($engine);
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);

        $argvs = array_map(static fn (array $a): string => implode(' ', $a), (new StorageReclaim($inner))->whileRunningArgvs());

        // `image prune -a` takes only images no container uses, stopped ones included.
        $this->assertSame(['docker builder prune -af', 'docker buildx prune -af', 'docker image prune -af'], $argvs);
    }
}
