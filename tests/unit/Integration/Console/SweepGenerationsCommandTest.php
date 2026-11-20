<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Project\Dind\Generation\GenerationState;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `pae deploy:generations:sweep` with nothing a redeploy left behind to settle. */
class SweepGenerationsCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $storage;

    private string $originalStorage;

    private RecordingSystem $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->originalStorage = $this->app->storagePath();
        $this->storage = sys_get_temp_dir() . '/pa-gensweep-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs', 0777, true);
        $this->app->useStoragePath($this->storage);
        $this->host = new class () extends RecordingSystem {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }
        };
        $this->app->instance(System::class, $this->host);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    public function test_nothing_to_settle_is_zero_projects(): void
    {
        $code = Artisan::call('deploy:generations:sweep');

        $this->assertSame(0, $code);
        $this->assertSame("Settled 0 project(s).\n", Artisan::output());
        $this->assertCount(1, $this->host->commands);
    }

    public function test_a_state_left_for_an_account_that_is_gone_is_dropped(): void
    {
        $state = GenerationState::path('ghost');
        mkdir(dirname($state), 0777, true);
        file_put_contents($state, '{}');

        $code = Artisan::call('deploy:generations:sweep');

        $this->assertSame(0, $code);
        $this->assertSame("Settled 0 project(s).\n", Artisan::output());
        $this->assertFileDoesNotExist($state);
    }
}
