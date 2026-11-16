<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Dind\DindAccountStorage;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * A reclaim before a heavy build is an optimisation, not the deploy. A prune
 * that times out at its 300 s limit or meets a daemon error used to fail the
 * whole deploy, although the build itself was never tried and the next prunes
 * never ran. Each prune is best-effort: logged, and the rest still run.
 */
class StorageReclaimBestEffortTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    public function test_a_failing_prune_is_logged_and_the_next_one_still_runs(): void
    {
        $ran = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$ran): string {
            $action = LocalRoot::action((array) $cmd);
            // The first prune meets a daemon error; the rest must still run.
            if ($action === 'account-docker.pruneSystem') {
                throw new \RuntimeException('context deadline exceeded');
            }
            $ran[] = (string) $action;

            return '';
        });

        $reclaim = $this->reclaim($system);

        $reclaim->reclaim(false);

        $this->assertSame(['account-docker.pruneBuildCache'], $ran, 'the build cache prune after the failing one ran');
        $this->assertSame(['Could not reclaim inner Docker disk (unused data): context deadline exceeded'], $this->warnings);
    }

    /** Through dockerd, which keeps running; volumes are the account's data and stay. */
    public function test_the_reclaim_prunes_images_and_the_build_cache_but_no_volumes(): void
    {
        $asked = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$asked): string {
            $asked[] = [LocalRoot::action((array) $cmd), LocalRoot::values((array) $cmd)];

            return '';
        });

        $this->reclaim($system)->reclaim(false);

        $this->assertSame([
            ['account-docker.pruneSystem', ['reclaim-test', '']],
            ['account-docker.pruneBuildCache', ['reclaim-test']],
        ], $asked);
        $this->assertSame([], $this->warnings);
    }

    public function test_a_failing_free_space_probe_is_logged_and_the_build_goes_on(): void
    {
        $ran = [];
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$ran): string {
            $line = implode(' ', is_array($cmd) ? $cmd : [$cmd]);
            if (str_contains($line, 'df -Pk')) {
                throw new \RuntimeException('The process exceeded the timeout of 60 seconds.');
            }
            $ran[] = $line;

            return '';
        });

        $this->reclaim($system)->reclaimIfNeeded();

        $this->assertSame([], array_values(array_filter($ran, fn (string $line): bool => str_contains($line, 'prune'))));
        $this->assertSame(['Could not check inner Docker disk before the build: The process exceeded the timeout of 60 seconds.'], $this->warnings);
    }

    /** `-a`: a stopped container still owns layers a prune would take. */
    public function test_only_an_account_holding_no_container_at_all_is_pruned_aggressively(): void
    {
        $asked = [];
        $answer = '[]';
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use (&$asked, &$answer): string {
            $asked[] = [LocalRoot::action((array) $cmd), LocalRoot::values((array) $cmd)];

            return $answer;
        });
        $reclaim = new StorageReclaim($this->inner($this->dind($system)));

        $this->assertTrue($reclaim->canAggressivelyReclaim());
        $this->assertSame([['account-docker.listContainers', ['reclaim-test', 'all', '0']]], $asked);

        $answer = '[{"Id":"' . str_repeat('a', 64) . '"}]';
        $this->assertFalse($reclaim->canAggressivelyReclaim());
    }

    /**
     * A daemon that cannot say what it holds, or an account that is not running, never reads as holding nothing:
     * pruning everything there could take what a container still needs.
     */
    public function test_a_daemon_that_cannot_list_its_containers_is_not_read_as_empty(): void
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willThrowException(new DockerErrorException('container reclaim-test is not running'));
        $reclaim = new StorageReclaim($this->inner($this->dind($system)));

        $this->expectException(DockerErrorException::class);

        $reclaim->canAggressivelyReclaim();
    }

    private function reclaim(System $system): StorageReclaim
    {
        $inner = $this->inner($this->dind($system));

        return new class ($inner) extends StorageReclaim {
            public function canAggressivelyReclaim(): bool
            {
                return true;
            }
        };
    }

    private function dind(System $system): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('reclaim-test');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('storage')->willReturn(new DindAccountStorage());
        $dind->method('engine')->willReturn($engine);

        return $dind;
    }

    private function inner(Dind $dind): InnerDocker
    {
        $host = $this->createStub(HostCommands::class);
        $host->method('logWarn')->willReturnCallback(function (string $message): void {
            $this->warnings[] = $message;
        });
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('dind')->willReturn($dind);
        $inner->method('host')->willReturn($host);

        return $inner;
    }
}
