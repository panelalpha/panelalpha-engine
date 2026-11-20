<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\TelemetryFields;
use App\System;
use App\System\Privileged;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** The checkout facts a report carries: read by git run as the account in its own project directory, and nothing without the account's ids. */
class TelemetryFieldsCheckoutTest extends TestCase
{
    use InMemoryDatabase;

    private string $home;

    /** @var list<array<int, string>|string> */
    public array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->home = sys_get_temp_dir().'/pa-telemetry-home-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/project/.git', 0777, true);
        $test = $this;
        $this->app->instance(System::class, new class($test, $this->home) extends RecordingSystem
        {
            public function __construct(private readonly TelemetryFieldsCheckoutTest $test, private readonly string $home) {}

            public function projectHomeDirPath(string $username): string
            {
                return $this->home;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->test->gitAnswers($cmd);
            }
        });
    }

    protected function tearDown(): void
    {
        rmdir($this->home.'/project/.git');
        rmdir($this->home.'/project');
        rmdir($this->home);
        parent::tearDown();
    }

    /** @param string|list<string> $cmd */
    public function gitAnswers(string|array $cmd): string
    {
        $this->commands[] = $cmd;
        $last = is_array($cmd) ? (string) end($cmd) : $cmd;

        return match ($last) {
            '--is-inside-work-tree' => "true\n",
            '--is-shallow-repository' => "false\n",
            default => throw new \RuntimeException('not a repository answer'),
        };
    }

    public function test_the_checkout_is_read_by_git_as_the_account(): void
    {
        $this->makeUser('alice', ['UID' => 1001, 'GID' => 1002]);
        $dir = $this->home.'/project';

        $checkout = (new TelemetryFields('alice'))->checkout();

        $this->assertTrue($checkout['present']);
        $this->assertFalse($checkout['shallow']);
        $this->assertNull($checkout['commit']);
        $this->assertSame(
            Privileged::account()->gitOnAccountCheckout(1001, 1002, $dir, ['rev-parse', '--is-inside-work-tree']),
            $this->commands[0]
        );
    }

    public function test_an_account_without_ids_runs_no_git_and_reports_no_checkout(): void
    {
        $this->makeUser('alice');

        $this->assertSame(['present' => false], (new TelemetryFields('alice'))->checkout());
        $this->assertSame([], $this->commands);
    }

    public function test_the_project_directory_is_the_accounts_home_project(): void
    {
        $this->assertSame($this->home.'/project', (new TelemetryFields('alice'))->projectDir());
    }
}
