<?php

namespace Tests\Unit\Console;

use App\Jobs\CreateBackup;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `pae project:staging` refuses a busy source in CLI words, never the sentence the HTTP API answers with. */
class ProjectStagingCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('source', ['template' => 'dind']);
    }

    public function test_a_busy_source_is_refused_with_the_plain_message(): void
    {
        $backup = Task::start(jobType: CreateBackup::class, queue: 'default', username: 'source');

        $this->artisan('project:staging', ['project' => 'source', '--new-username' => 'stgfresh'])
            ->expectsOutput("Project 'source' is busy: backup task {$backup->id} is queued. Cancel task {$backup->id} or wait for it to finish.")
            ->doesntExpectOutputToContain('POST /tasks')
            ->assertExitCode(1);

        $this->assertNull(User::findByUsername('stgfresh'));
    }

    public function test_the_help_names_container_and_classic_projects(): void
    {
        $description = Artisan::all()['project:staging']->getDescription();

        $this->assertStringContainsString('container or classic hosting', $description);
        $this->assertStringNotContainsString('dind', $description);
    }
}
