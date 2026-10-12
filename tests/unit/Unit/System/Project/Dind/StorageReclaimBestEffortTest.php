<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Dind\DindAccountStorage;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * A reclaim before a heavy build is an optimisation, not the deploy. A prune
 * that times out at its 300 s limit or meets a daemon error used to fail the
 * whole deploy, although the build itself was never tried and the next prunes
 * never ran. Each prune is best-effort: logged, and the rest still run.
 */
class StorageReclaimBestEffortTest extends TestCase
{
    public function test_a_failing_prune_is_logged_and_the_next_one_still_runs(): void
    {
        $ran = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$ran): string {
            $argv = is_array($cmd) ? $cmd : [$cmd];
            $line = implode(' ', $argv);
            // The first prune meets a daemon error; the rest must still run.
            if (str_contains($line, 'system prune')) {
                throw new \RuntimeException('context deadline exceeded');
            }
            $ran[] = $line;

            return '';
        });

        $reclaim = $this->reclaim($system);

        $reclaim->reclaim(false);

        $this->assertCount(2, $ran, 'both prunes after the failing one ran');
        $this->assertStringContainsString('builder prune -af', $ran[0]);
        $this->assertStringContainsString('buildx prune -af', $ran[1]);
    }

    private function reclaim(System $system): StorageReclaim
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('reclaim-test');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('storage')->willReturn(new DindAccountStorage());
        $dind->method('engine')->willReturn($engine);

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);

        // The reclaim before a build does not check for running containers.
        return new class ($inner) extends StorageReclaim {
            public function canAggressivelyReclaim(): bool
            {
                return true;
            }
        };
    }
}
