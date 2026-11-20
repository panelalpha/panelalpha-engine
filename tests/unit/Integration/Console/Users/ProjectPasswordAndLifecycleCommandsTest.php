<?php

namespace Tests\Integration\Console\Users;

use App\Exceptions\ProblemException;
use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Site passwords, attach, engine-artifact migration and delete, up to where the host is touched. */
class ProjectPasswordAndLifecycleCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->makeUser('alice');
        $this->makeUser('locked', ['site_password_enabled' => true, 'site_password_hash' => password_hash('secret-pass', PASSWORD_BCRYPT)]);
    }

    public function test_set_password_refuses_missing_projects_and_bad_prompts(): void
    {
        $this->artisan('project:set-password')->expectsOutput('--project is required.')->assertExitCode(1);
        $this->artisan('project:set-password --project=nobody')->expectsOutput("Project 'nobody' not found.")->assertExitCode(1);

        $this->artisan('project:set-password --project=alice')
            ->expectsQuestion('Site password', '')
            ->expectsOutput('Password must not be empty.')
            ->assertExitCode(1);
        $this->artisan('project:set-password --project=alice')
            ->expectsQuestion('Site password', 'first-pass')
            ->expectsQuestion('Confirm password', 'other-pass')
            ->expectsOutput('Passwords do not match.')
            ->assertExitCode(1);
    }

    public function test_set_password_answered_no_changes_nothing(): void
    {
        $this->artisan('project:set-password --project=alice --password=open-sesame')
            ->expectsConfirmation("Set site password for project 'alice'?", 'no')
            ->expectsOutput('Aborted.')
            ->assertExitCode(0);

        $this->assertArrayNotHasKey('site_password_hash', User::findByUsername('alice')->getDetails());
    }

    public function test_unset_password_refuses_and_reports_a_project_without_one(): void
    {
        $this->artisan('project:unset-password')->expectsOutput('--project is required.')->assertExitCode(1);
        $this->artisan('project:unset-password --project=nobody')->expectsOutput("Project 'nobody' not found.")->assertExitCode(1);
        $this->artisan('project:unset-password --project=alice')
            ->expectsOutput("Project 'alice' has no site password set.")
            ->assertExitCode(0);
    }

    public function test_unset_password_answered_no_keeps_it(): void
    {
        $this->artisan('project:unset-password --project=locked')
            ->expectsConfirmation("Remove site password for project 'locked'?", 'no')
            ->expectsOutput('Aborted.')
            ->assertExitCode(0);

        $this->assertNotEmpty(User::findByUsername('locked')->getDetails()['site_password_hash'] ?? null);
    }

    public function test_attach_refuses_an_unknown_project_or_one_without_a_compose_file(): void
    {
        $this->artisan('project:attach nobody')->expectsOutput('Project `nobody` not found in database.')->assertExitCode(1);
        $this->artisan('project:attach alice')
            ->expectsOutputToContain('Compose file not found: ')
            ->assertExitCode(1);
    }

    public function test_migrate_engine_artifacts_needs_a_target_and_skips_classic_projects(): void
    {
        $this->artisan('project:migrate-engine-artifacts')
            ->expectsOutput('One of the following is required: a username, or `--all`.')
            ->assertExitCode(1);
        $this->artisan('project:migrate-engine-artifacts nobody')->expectsOutput('Invalid username')->assertExitCode(1);
        $this->artisan('project:migrate-engine-artifacts alice')
            ->doesntExpectOutputToContain('Migrating')
            ->assertExitCode(0);
        $this->artisan('project:migrate-engine-artifacts --all')
            ->doesntExpectOutputToContain('Migrating')
            ->assertExitCode(0);
    }

    private function deleter(): Mockery\MockInterface
    {
        $deleter = Mockery::mock(ProjectDeleter::class);
        $this->app->instance(ProjectDeleter::class, $deleter);

        return $deleter;
    }

    public function test_delete_all_asks_once_and_a_no_deletes_nothing(): void
    {
        $this->deleter()->shouldNotReceive('delete');

        $this->artisan('project:delete --all')
            ->expectsConfirmation('All 2 projects will be completely deleted from this server, are you sure?', 'no')
            ->assertExitCode(0);
    }

    public function test_delete_all_with_force_deletes_every_project(): void
    {
        $deleted = [];
        $this->deleter()->shouldReceive('delete')->twice()->andReturnUsing(function (User $user) use (&$deleted): void {
            $deleted[] = $user->username;
        });

        $this->artisan('project:delete --all --force')
            ->expectsOutput('Project `alice` deleted.')
            ->expectsOutput('Project `locked` deleted.')
            ->assertExitCode(0);

        $this->assertSame(['alice', 'locked'], $deleted);
    }

    public function test_delete_refuses_names_with_all_and_nothing_named(): void
    {
        $this->deleter()->shouldNotReceive('delete');

        $this->artisan('project:delete alice --all --force')->assertExitCode(1);
        $this->artisan('project:delete --force')->assertExitCode(1);
    }

    public function test_delete_all_on_an_empty_engine_says_so(): void
    {
        User::query()->delete();

        $this->artisan('project:delete --all --force')
            ->expectsOutput('No projects on this engine.')
            ->assertExitCode(0);
    }

    public function test_delete_reports_a_refusal_from_the_deleter(): void
    {
        $this->deleter()->shouldReceive('delete')->once()->andThrow(ProblemException::one('project', 'protected', 'Project alice is protected.'));

        $this->artisan('project:delete alice --force')->assertExitCode(1);

        $this->assertNotNull(User::findByUsername('alice'));
    }
}
