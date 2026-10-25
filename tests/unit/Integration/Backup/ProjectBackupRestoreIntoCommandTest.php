<?php

namespace Tests\Integration\Backup;

use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectRestore;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class ProjectBackupRestoreIntoCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_it_restores_a_backup_into_the_named_project(): void
    {
        $source = $this->user('alice');
        $backup = $this->backup($source);
        $restore = $this->fakeRestore();
        app()->instance(ProjectRestore::class, $restore);

        $this->artisan('project:backup:restore-into', [
            'project' => 'alice',
            'backup' => $backup->id,
            '--as' => 'bob',
            '--domain' => 'bob.example.test',
            '--force' => true,
        ])->assertExitCode(0)
            ->expectsOutput("Backup {$backup->id} restored into project 'bob' (bob.example.test).");

        $this->assertCount(1, $restore->calls);
        $this->assertSame($backup->id, $restore->calls[0]['backup']);
        $this->assertSame('bob', $restore->calls[0]['params']['username']);
        $this->assertSame('bob.example.test', $restore->calls[0]['params']['domain']);
        $this->assertSame('dind', $restore->calls[0]['params']['template']);
    }

    public function test_it_refuses_an_unknown_project(): void
    {
        $this->artisan('project:backup:restore-into', ['project' => 'nobody', 'backup' => 1, '--as' => 'bob'])
            ->expectsOutput("Project 'nobody' not found.")
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_backup_of_another_project(): void
    {
        $owner = $this->user('alice');
        $this->user('bob');
        $backup = $this->backup($owner);

        $this->artisan('project:backup:restore-into', ['project' => 'bob', 'backup' => $backup->id, '--as' => 'bobby'])
            ->expectsOutput("Backup '{$backup->id}' not found for project 'bob'.")
            ->assertExitCode(1);
    }

    public function test_it_needs_the_new_project_name(): void
    {
        $source = $this->user('alice');
        $backup = $this->backup($source);

        $this->artisan('project:backup:restore-into', ['project' => 'alice', 'backup' => $backup->id])
            ->expectsOutput('Pass the new project name with --as.')
            ->assertExitCode(1);
    }

    public function test_it_refuses_a_php_hosting_backup(): void
    {
        $source = $this->makeUser('alice', ['template' => 'default', 'mysql_prefix' => 'alice_']);
        $backup = $this->backup($source);

        $this->artisan('project:backup:restore-into', [
            'project' => 'alice',
            'backup' => $backup->id,
            '--as' => 'bob',
            '--force' => true,
        ])->expectsOutput('Only backups of container projects can be restored into a new project.')
            ->assertExitCode(1);
    }

    public function test_it_refuses_an_incomplete_backup_before_creating_the_project(): void
    {
        $source = $this->user('alice');
        $backup = $this->backup($source);

        $this->artisan('project:backup:restore-into', [
            'project' => 'alice',
            'backup' => $backup->id,
            '--as' => 'bob',
            '--force' => true,
        ])->expectsOutput('Backup is not complete')
            ->assertExitCode(1);

        $this->assertNull(User::findByUsername('bob'));
    }

    public function test_it_refuses_a_name_outside_the_project_name_grammar(): void
    {
        $source = $this->user('alice');
        $backup = $this->backup($source);
        $restore = $this->fakeRestore();
        app()->instance(ProjectRestore::class, $restore);

        $this->artisan('project:backup:restore-into', [
            'project' => 'alice',
            'backup' => $backup->id,
            '--as' => 'no',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame([], $restore->calls);
    }

    public function test_a_cancelled_restore_touches_nothing(): void
    {
        $source = $this->user('alice');
        $backup = $this->backup($source);
        $restore = $this->fakeRestore();
        app()->instance(ProjectRestore::class, $restore);

        $this->artisan('project:backup:restore-into', ['project' => 'alice', 'backup' => $backup->id, '--as' => 'bob'])
            ->expectsConfirmation("Restore backup {$backup->id} into a new project 'bob'?", 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame([], $restore->calls);
    }

    public function test_a_failed_restore_exits_one(): void
    {
        $source = $this->user('alice');
        $backup = $this->backup($source);
        app()->instance(ProjectRestore::class, $this->fakeRestore(new \RuntimeException('restore blew up')));

        $this->artisan('project:backup:restore-into', [
            'project' => 'alice',
            'backup' => $backup->id,
            '--as' => 'bob',
            '--force' => true,
        ])->expectsOutput('restore blew up')
            ->assertExitCode(1);
    }

    private function fakeRestore(?\RuntimeException $failure = null): ProjectRestore
    {
        return new class ($failure) extends ProjectRestore
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private ?\RuntimeException $failure = null) {}

            public function restoreNow(User $source, BackupRecord $record, NewProjectInput $input): User
            {
                $this->calls[] = [
                    'backup' => $record->id,
                    'source' => $source->username,
                    'params' => $input->params,
                ];

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new User([
                    'username' => $input->params['username'],
                    'domain' => $input->params['domain'] ?? null,
                ]);
            }
        };
    }

    private function user(string $username): User
    {
        return $this->makeUser($username, ['template' => 'dind', 'mysql_prefix' => $username.'_']);
    }

    private function backup(User $source): BackupRecord
    {
        $container = new BackupContainer();
        $container->name = 'store-'.bin2hex(random_bytes(4));
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = null;
        $container->save();

        $backup = BackupRecord::prepare($source, $container, 'api');
        $backup->setBackupStatus('completed');
        $backup->save();

        return $backup;
    }
}
