<?php

namespace Tests\Integration\Console\Projects;

use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** project:push, project:staging and project:php-directives:set refuse what they cannot do before anything is copied or written. */
class ProjectCopyAndDirectivesRefusalsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $live = $this->makeUser('live', ['template' => 'dind']);
        $staging = $this->makeUser('live_stg', ['template' => 'dind']);
        $staging->staging = $live->id;
        $staging->save();
        $this->makeUser('classic');
    }

    public function test_push_refuses_unknown_projects(): void
    {
        $this->artisan('project:push nobody live')->expectsOutput("No such project: 'nobody'")->assertExitCode(1);
        $this->artisan('project:push live nobody')->expectsOutput("No such project: 'nobody'")->assertExitCode(1);
    }

    public function test_push_refuses_projects_that_are_not_a_staging_pair(): void
    {
        $this->artisan('project:push live classic')
            ->expectsOutputToContain("Projects 'live' (dind) and 'classic' (")
            ->assertExitCode(1);
    }

    public function test_push_refuses_a_pair_while_one_side_is_busy(): void
    {
        $live = User::findByUsername('live');
        $live->mergeAsyncStatus(['staging' => 'running']);
        $live->save();

        $this->artisan('project:push live_stg live')
            ->expectsOutput("Project 'live' is busy: a staging copy is running. Wait for it to finish.")
            ->assertExitCode(1);

        $this->assertNotSame('running', User::findByUsername('live')->asyncStatus()['push'] ?? null);
    }

    public function test_staging_refuses_an_unknown_source_or_a_taken_name(): void
    {
        $this->artisan('project:staging nobody')->expectsOutput("No such project: 'nobody'")->assertExitCode(1);
        $this->artisan('project:staging live --new-username=CLASSIC')
            ->expectsOutput("A project named 'classic' already exists. Choose another name.")
            ->assertExitCode(1);

        $this->assertSame(3, User::query()->count());
    }

    public function test_php_directives_set_refuses_bad_input(): void
    {
        $this->artisan('project:php-directives:set', ['username' => ' ', 'version' => '8.3', '--clear' => true])
            ->expectsOutput('Username and PHP version are required.')
            ->assertExitCode(1);
        $this->artisan('project:php-directives:set classic 8.3 --clear --settings={}')
            ->expectsOutput('Pass either --settings or --clear, not both.')
            ->assertExitCode(1);
        $this->artisan('project:php-directives:set classic 8.3')
            ->expectsOutput('Pass --settings or --clear.')
            ->assertExitCode(1);
        $this->artisan('project:php-directives:set', ['username' => 'classic', 'version' => '8.3', '--settings' => '["a"]'])
            ->expectsOutput('--settings must be a JSON object.')
            ->assertExitCode(1);
        $this->artisan('project:php-directives:set', ['username' => 'classic', 'version' => '8.3', '--settings' => '{"memory_limit":256}'])
            ->expectsOutput('--settings values must be strings.')
            ->assertExitCode(1);
    }
}
