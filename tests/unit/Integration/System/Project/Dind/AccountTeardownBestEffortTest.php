<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Dind\DindAccountCleanup;
use App\Lib\Deploy\Engine\AccountStorage;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Deleting an account goes through every step whatever fails on the way, and leaves a warning for each one
 * that did; a sibling scan that fails keeps every host image.
 */
class AccountTeardownBestEffortTest extends TestCase
{
    private const USERNAME = 'teardown';

    /** @var list<list<string>> */
    private array $execs = [];

    /** @var list<list<string>> */
    private array $processes = [];

    private bool $scanFails = true;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public function test_every_failing_step_is_logged_and_the_rest_still_run(): void
    {
        (new AccountTeardown($this->dind()))->delete();

        $this->assertNotSame([], $this->processes, 'the stack was asked to go down');
        $this->assertContains(['purge', 'host-cache'], $this->execs);
        $this->assertWarned('Could not scan sibling compose files before deleting '.self::USERNAME.': scan refused');
        $this->assertWarned('Dind delete step failed for '.self::USERNAME.': compose refused');
        $this->assertWarned('Dind delete step failed for '.self::USERNAME.': daemon refused');
        $this->assertWarned('Could not wipe inner Docker data-root for '.self::USERNAME.': dockerd gone');
        $this->assertWarned('Could not purge host Docker cache for '.self::USERNAME.': purge refused');
    }

    public function test_host_containers_that_cannot_be_listed_count_as_none(): void
    {
        $this->scanFails = false;

        (new AccountTeardown($this->dind()))->delete();

        $this->assertContains(Privileged::docker()->containerImages(), $this->execs);
        $this->assertContains(['purge', 'host-cache'], $this->execs);
        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/^Could not scan sibling compose files/')]);
    }

    private function assertWarned(string $message): void
    {
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $logged): bool => $logged === $message)->atLeast()->once();
    }

    private function dind(): Dind
    {
        $scan = DindAccountCleanup::otherAccountComposeImagesArgv('/home', self::USERNAME);
        $containerImages = Privileged::docker()->containerImages();
        $system = $this->createStub(System::class);
        $system->method('homesDirPath')->willReturn('/home');
        $system->method('filesystem')->willReturn($this->createStub(SystemFilesystem::class));
        $system->method('exec')->willReturnCallback(function (string|array $cmd) use ($scan, $containerImages): string {
            $argv = array_values((array) $cmd);
            $this->execs[] = $argv;

            return match (true) {
                str_starts_with((string) LocalRoot::action($argv), 'account-docker.') => throw new \RuntimeException('daemon refused'),
                $argv === $scan && $this->scanFails => throw new \RuntimeException('scan refused'),
                $argv === $scan => '',
                $argv === $containerImages => throw new \RuntimeException('docker refused'),
                $argv === ['purge', 'host-cache'] => throw new \RuntimeException('purge refused'),
                default => '',
            };
        });
        $system->method('runProcess')->willReturnCallback(function (string|array $cmd): Process {
            $this->processes[] = array_values((array) $cmd);

            throw new \RuntimeException('compose refused');
        });

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('wipeDataRoot')->willThrowException(new \RuntimeException('dockerd gone'));

        $storage = $this->createStub(AccountStorage::class);
        $storage->method('hostCleanupArgvs')->willReturn([['purge', 'host-cache']]);
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('storage')->willReturn($storage);

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn(self::USERNAME);
        $dind->method('system')->willReturn($system);
        $dind->method('isRunning')->willReturn(true);
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount(self::USERNAME, '/home/'.self::USERNAME));
        $dind->method('userAppComposeFilePath')->willReturn('/home/'.self::USERNAME.'/project/docker-compose.panelalpha.yml');
        $dind->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => ['docker', 'compose', ...$rest]);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }
}
