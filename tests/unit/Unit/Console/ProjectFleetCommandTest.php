<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The selection preamble and the per-project loop that eleven commands used to
 * write out for themselves.
 */
class ProjectFleetCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();

        SpyFleetCommand::$touched = [];
        SpyFleetCommand::$afterAllRuns = 0;
        SpyFleetCommand::$failOn = [];
        SpyFleetCommand::$beforeAllThrows = false;
        SpyFleetCommand::$beforeAllSawTouched = null;

        $this->app[\Illuminate\Contracts\Console\Kernel::class]
            ->registerCommand(new SpyFleetCommand());
    }

    public function test_naming_neither_a_project_nor_all_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('spy:fleet'));
        $this->assertStringContainsString(
            'One of following options is required: `--project=NAME` or `--all`',
            Artisan::output()
        );
        $this->assertSame([], SpyFleetCommand::$touched);
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('spy:fleet', ['--project' => 'nobody']));
        $this->assertStringContainsString('Invalid username', Artisan::output());
        $this->assertSame([], SpyFleetCommand::$touched);
    }

    public function test_a_named_project_is_the_only_one_touched(): void
    {
        $this->makeUser('alice');
        $this->makeUser('bob');

        $this->assertSame(0, Artisan::call('spy:fleet', ['--project' => 'alice']));
        $this->assertSame(['alice'], SpyFleetCommand::$touched);
    }

    public function test_all_touches_every_project(): void
    {
        $this->makeUser('alice');
        $this->makeUser('bob');

        $this->assertSame(0, Artisan::call('spy:fleet', ['--all' => true]));
        $this->assertSame(['alice', 'bob'], SpyFleetCommand::$touched);
    }

    /** `--username` is the old spelling and still answers. */
    public function test_the_deprecated_username_option_still_works(): void
    {
        $this->makeUser('alice');

        $this->assertSame(0, Artisan::call('spy:fleet', ['--username' => 'alice']));
        $this->assertSame(['alice'], SpyFleetCommand::$touched);
    }

    public function test_the_progress_and_finished_lines_are_printed(): void
    {
        $this->makeUser('alice');

        Artisan::call('spy:fleet', ['--project' => 'alice']);
        $output = Artisan::output();

        $this->assertStringContainsString("Touching 'alice'...", $output);
        $this->assertStringContainsString('  Finished.', $output);
    }

    /** A fleet-wide fix must not stop at the first broken account. */
    public function test_a_project_that_throws_is_reported_and_the_run_continues(): void
    {
        $this->makeUser('alice');
        $this->makeUser('bob');
        $this->makeUser('carol');
        SpyFleetCommand::$failOn = ['bob'];

        $exit = Artisan::call('spy:fleet', ['--all' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'a failed project fails the run');
        $this->assertSame(['alice', 'carol'], SpyFleetCommand::$touched);
        $this->assertStringContainsString('bob exploded', $output);
    }

    public function test_after_all_runs_once_even_when_a_project_failed(): void
    {
        $this->makeUser('alice');
        $this->makeUser('bob');
        SpyFleetCommand::$failOn = ['alice', 'bob'];

        Artisan::call('spy:fleet', ['--all' => true]);

        $this->assertSame(1, SpyFleetCommand::$afterAllRuns);
    }

    public function test_after_all_does_not_run_when_the_options_name_nothing(): void
    {
        Artisan::call('spy:fleet');

        $this->assertSame(0, SpyFleetCommand::$afterAllRuns);
    }

    /** A script checking the exit code must see a module that was not changed. */
    public function test_the_apache_commands_fail_when_a_project_failed(): void
    {
        $this->makeUser('alice');
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand(new FailingApacheModCommand());

        $this->assertSame(1, Artisan::call('spy:apache-mod', ['mod' => 'rewrite', '--all' => true]));
        $this->assertStringContainsString("rewrite exploded for alice", Artisan::output());
    }

    public function test_before_all_runs_ahead_of_every_project(): void
    {
        $this->makeUser('alice');

        $this->assertSame(0, Artisan::call('spy:fleet', ['--all' => true]));
        $this->assertSame([], SpyFleetCommand::$beforeAllSawTouched);
        $this->assertSame(['alice'], SpyFleetCommand::$touched);
    }

    /** A throw before the loop is not a per-project failure: it ends the command. */
    public function test_a_throw_in_before_all_ends_the_command_before_any_project(): void
    {
        $this->makeUser('alice');
        SpyFleetCommand::$beforeAllThrows = true;

        try {
            Artisan::call('spy:fleet', ['--all' => true]);
            $this->fail('Expected the beforeAll exception to escape');
        } catch (\RuntimeException $e) {
            $this->assertSame('before all exploded', $e->getMessage());
        }

        $this->assertSame([], SpyFleetCommand::$touched);
        $this->assertSame(0, SpyFleetCommand::$afterAllRuns);
    }

    public function test_the_apache_commands_say_enabling_and_disabling(): void
    {
        foreach ([
            [new \App\Console\Commands\Apache\EnableMod(), "Enabling mod 'rewrite' for user 'alice'...", '  Mod enabled.'],
            [new \App\Console\Commands\Apache\DisableMod(), "Disabling mod 'rewrite' for user 'alice'...", '  Mod disabled.'],
        ] as [$command, $progress, $finished]) {
            $command->setLaravel($this->app);
            $input = new \Symfony\Component\Console\Input\ArrayInput(['mod' => 'rewrite', '--project' => 'alice'], $command->getDefinition());
            (new \ReflectionProperty($command, 'input'))->setValue($command, $input);

            $user = new User();
            $user->username = 'alice';
            $call = fn (string $m) => (new \ReflectionMethod($command, $m))->invoke($command, $user);

            $this->assertSame($progress, $call('progress'));
            $this->assertSame($finished, $call('finished'));
        }
    }

    public function test_no_projects_at_all_is_a_clean_run(): void
    {
        $this->assertSame(0, Artisan::call('spy:fleet', ['--all' => true]));
        $this->assertSame([], SpyFleetCommand::$touched);
        $this->assertSame(1, SpyFleetCommand::$afterAllRuns);
    }
}

/** @internal */
class SpyFleetCommand extends ProjectFleetCommand
{
    /** @var list<string> */
    public static array $touched = [];

    public static int $afterAllRuns = 0;

    /** @var list<string> */
    public static array $failOn = [];

    public static bool $beforeAllThrows = false;

    /** @var ?list<string> what had been touched when beforeAll() ran */
    public static ?array $beforeAllSawTouched = null;

    protected $signature = 'spy:fleet' . ProjectOptions::SIGNATURE;

    protected $description = 'Test double for the fleet command base.';

    protected function progress(User $user): string
    {
        return "Touching '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        if (in_array($user->username, self::$failOn, true)) {
            throw new \RuntimeException("{$user->username} exploded");
        }

        self::$touched[] = $user->username;
    }

    protected function beforeAll(): void
    {
        self::$beforeAllSawTouched = self::$touched;
        if (self::$beforeAllThrows) {
            throw new \RuntimeException('before all exploded');
        }
    }

    protected function afterAll(): void
    {
        self::$afterAllRuns++;
    }
}

/** @internal */
class FailingApacheModCommand extends \App\Console\Commands\Apache\ApacheModCommand
{
    protected $signature = 'spy:apache-mod {mod}' . ProjectOptions::SIGNATURE;

    protected function doing(): string
    {
        return 'Enabling';
    }

    protected function done(): string
    {
        return 'enabled';
    }

    protected function applyMod(User $user, string $mod): void
    {
        throw new \RuntimeException("{$mod} exploded for {$user->username}");
    }
}
