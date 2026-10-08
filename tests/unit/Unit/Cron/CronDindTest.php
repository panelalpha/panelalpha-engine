<?php

namespace Tests\Unit\Cron;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A dind account never mounts the project crontab and has no `php` service,
 * so creating a cron job there saved a job that could not run and then
 * answered 503 `service "php" is not running` from the reload.
 */
class CronDindTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_create_and_update_on_a_dind_project_are_a_422(): void
    {
        $this->user('dind');
        $job = [
            'command' => 'echo mcp',
            'minute' => '*',
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ];

        $this->postJson('/api/projects/alice/cron-jobs', $job)
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
        $this->putJson('/api/projects/alice/cron-jobs/abc', $job)
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
    }

    public function test_reloading_cron_on_a_dind_project_runs_nothing(): void
    {
        $system = new class () extends System {
            /** @var list<string|array<int, string>> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = $cmd;

                return '';
            }
        };

        $system->project($this->user('dind'))->reloadCron();

        $this->assertSame([], $system->calls);
    }

    private function user(string $template): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => $template]);
        $user->save();

        return $user;
    }
}
