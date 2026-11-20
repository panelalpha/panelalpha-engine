<?php

namespace Tests\Integration\Console\Users;

use App\Console\Commands\Users\RecoverDind;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `project:dind:recover`: its refusals, a recovery the broker reports as failed, and the detect and all-stuck runs. */
class RecoverDindOutcomesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('alice', ['template' => 'dind']);
        ScriptedRecoverDind::$answer = [0, ''];
        ScriptedRecoverDind::$argv = [];
        ScriptedRecoverDind::$rebuildFails = false;
        $this->app[Kernel::class]->registerCommand(new ScriptedRecoverDind);
    }

    public function test_a_target_is_required(): void
    {
        $this->artisan('project:dind:recover')
            ->expectsOutput('One of --project=, --all-stuck, or --detect is required.')
            ->assertExitCode(1);

        $this->assertSame([], ScriptedRecoverDind::$argv);
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->artisan('project:dind:recover --project=nobody')
            ->expectsOutput('Invalid username')
            ->assertExitCode(1);
    }

    public function test_a_failed_recovery_answers_the_brokers_exit_code(): void
    {
        ScriptedRecoverDind::$answer = [3, "inner docker did not come back\n"];

        $this->artisan('project:dind:recover --project=alice')
            ->expectsOutput('Recovery failed with exit code 3')
            ->assertExitCode(3);
    }

    public function test_detect_and_all_stuck_ask_the_broker_and_rebuild_nothing(): void
    {
        $this->artisan('project:dind:recover --detect')->assertExitCode(0);
        $this->artisan('project:dind:recover --all-stuck --wipe-inner')->assertExitCode(0);

        $this->assertCount(2, ScriptedRecoverDind::$argv);
        $this->assertStringContainsString('detectStuckDind', implode(' ', ScriptedRecoverDind::$argv[0]));
        $this->assertStringContainsString('recoverStuckDind', implode(' ', ScriptedRecoverDind::$argv[1]));
    }

    public function test_a_rebuild_that_fails_after_a_recreate_is_only_a_warning(): void
    {
        ScriptedRecoverDind::$answer = [0, "[recover-sysbox-dind] done alice (D=0)\n"];
        ScriptedRecoverDind::$rebuildFails = true;

        $this->artisan('project:dind:recover --project=alice --recreate')
            ->expectsOutput('Rebuilding project `alice` config...')
            ->expectsOutput('Container recovered but rebuild failed: compose template missing')
            ->assertExitCode(0);
    }
}

/** @internal A recovery whose broker answers with a scripted exit code and output, and starts nothing. */
class ScriptedRecoverDind extends RecoverDind
{
    /** @var array{0: int, 1: string} */
    public static array $answer = [0, ''];

    /** @var list<list<string>> */
    public static array $argv = [];

    public static bool $rebuildFails = false;

    protected function recoveryProcess(array $argv): Process
    {
        self::$argv[] = $argv;
        [$code, $output] = self::$answer;

        return new class ($code, $output) extends Process {
            public function __construct(private int $code, private string $stdout)
            {
                parent::__construct(['true']);
            }

            public function run(?callable $callback = null, array $env = []): int
            {
                $callback !== null && $callback(Process::OUT, $this->stdout);

                return $this->code;
            }

            public function getExitCode(): ?int
            {
                return $this->code;
            }

            public function getOutput(): string
            {
                return $this->stdout;
            }
        };
    }

    protected function rebuild(User $user): void
    {
        if (self::$rebuildFails) {
            throw new \RuntimeException('compose template missing');
        }
    }
}
