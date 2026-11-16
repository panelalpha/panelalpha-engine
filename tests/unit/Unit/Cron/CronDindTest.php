<?php

namespace Tests\Unit\Cron;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind\AccountCron;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A container project's cron job runs in one of its services, so it names
 * that service; a PHP hosting project's job has no service to name.
 */
class CronDindTest extends TestCase
{
    private const JOB = [
        'command' => 'php artisan schedule:run',
        'minute' => '*',
        'hour' => '*',
        'day_of_month' => '*',
        'month' => '*',
        'day_of_week' => '*',
    ];

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

    public function test_a_container_project_job_without_a_service_is_a_422(): void
    {
        $this->user('dind');

        $this->postJson('/api/projects/alice/cron-jobs', self::JOB)
            ->assertStatus(422)
            ->assertJsonValidationErrors('service');
        $this->putJson('/api/projects/alice/cron-jobs/abc', self::JOB)
            ->assertStatus(422)
            ->assertJsonValidationErrors('service');
    }

    public function test_a_service_that_is_not_a_compose_name_is_a_422(): void
    {
        $this->user('dind');

        $this->postJson('/api/projects/alice/cron-jobs', ['service' => 'app; rm -rf /'] + self::JOB)
            ->assertStatus(422)
            ->assertJsonValidationErrors('service');
    }

    public function test_a_command_longer_than_the_runner_takes_is_a_422(): void
    {
        $this->user('dind');
        $job = ['service' => 'app', 'command' => str_repeat('x', AccountCron::MAX_COMMAND_BYTES + 1)] + self::JOB;

        $this->postJson('/api/projects/alice/cron-jobs', $job)
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
    }

    public function test_a_php_hosting_project_job_with_a_service_is_a_422(): void
    {
        $this->user('default');

        $this->postJson('/api/projects/alice/cron-jobs', ['service' => 'app'] + self::JOB)
            ->assertStatus(422)
            ->assertJsonValidationErrors('service');
    }

    /** The list used to read the crontab and answered 500 before the deploy created the account. */
    public function test_listing_on_a_container_project_before_its_first_deploy_is_empty(): void
    {
        $this->user('dind');

        $this->getJson('/api/projects/alice/cron-jobs')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_a_job_with_a_service_before_the_first_deploy_is_the_not_deployed_conflict(): void
    {
        $this->user('dind');

        $this->postJson('/api/projects/alice/cron-jobs', ['service' => 'app'] + self::JOB)
            ->assertStatus(409)
            ->assertJsonPath('problems.0.code', 'project_not_deployed');
    }

    /** cron never reads a job in a service's command, so a backslash before a % is the job's own business. */
    public function test_a_job_in_a_service_may_hold_a_backslash_before_a_percent(): void
    {
        $this->user('dind');

        $this->postJson('/api/projects/alice/cron-jobs', ['service' => 'app', 'command' => 'printf "\\%s"'] + self::JOB)
            ->assertStatus(409)
            ->assertJsonPath('problems.0.code', 'project_not_deployed');
    }

    public function test_reloading_cron_hands_the_crontab_to_the_account(): void
    {
        $system = $this->recordingSystem();

        $system->project($this->user('dind'))->reloadCron();

        $this->assertSame(
            [AccountCron::CRONTAB_FILE, AccountCron::INSTALL_FILE, AccountCron::RUNNER_FILE],
            array_map('basename', array_keys($system->writes))
        );
        $this->assertSame([], $system->calls, 'an account that is not running is left to install it at boot');
    }

    private function user(string $template): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => $template, 'UID' => 1500, 'GID' => 1500]);
        $user->save();

        return $user;
    }

    private function recordingSystem(): System
    {
        return new class () extends System {
            /** @var list<string|array<int, string>> */
            public array $calls = [];

            /** @var array<string, string> */
            public array $writes = [];

            public function __construct()
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = $cmd;

                return '';
            }

            public function filesystem(): Filesystem
            {
                $system = $this;

                return new class ($system) extends Filesystem {
                    public function __construct(private System $recorder)
                    {
                        parent::__construct($recorder);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->recorder->writes[$path] = $contents;
                    }
                };
            }
        };
    }
}
