<?php

namespace Tests\Integration\Backup;

use App\Jobs\RestoreBackupInto;
use App\Lib\Project\ProjectRestore;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class RestoreBackupIntoJobTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Queue::fake();
    }

    public function test_the_job_restores_the_backup_into_the_named_project(): void
    {
        $source = $this->user('alice');
        $target = $this->user('bob');
        $backup = $this->backup($source);
        $restore = $this->fakeRestore();
        app()->instance(ProjectRestore::class, $restore);

        (new RestoreBackupInto($backup->id, 'bob'))->handle();

        $this->assertSame(
            [['backup' => $backup->id, 'source' => $source->username, 'target' => 'bob']],
            $restore->restored
        );
        $this->assertSame($target->id, $target->fresh()->id);
    }

    public function test_a_backup_that_no_longer_exists_fails_the_job(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The backup no longer exists.');

        (new RestoreBackupInto(999, 'bob'))->handle();
    }

    public function test_a_project_that_no_longer_exists_fails_the_job(): void
    {
        $backup = $this->backup($this->user('alice'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Project 'bob' no longer exists.");

        (new RestoreBackupInto($backup->id, 'bob'))->handle();
    }

    public function test_a_restore_that_failed_is_recorded_on_the_new_project(): void
    {
        $source = $this->user('alice');
        $target = $this->user('bob');
        $backup = $this->backup($source);
        $restore = $this->fakeRestore(new RuntimeException('restore blew up'));
        app()->instance(ProjectRestore::class, $restore);
        $job = new RestoreBackupInto($backup->id, 'bob');

        try {
            $job->handle();
            $this->fail('Expected the restore failure to reach the queue.');
        } catch (RuntimeException $e) {
            $this->assertSame('restore blew up', $e->getMessage());
        }

        $job->failed(new RuntimeException('restore blew up'));

        $target->refresh();
        $this->assertSame('failed', $target->asyncStatus()['restore']);
        $this->assertSame('restore blew up', $target->getDetails()['error']);
    }

    private function fakeRestore(?RuntimeException $failure = null): ProjectRestore
    {
        return new class ($failure) extends ProjectRestore
        {
            /** @var list<array<string, mixed>> */
            public array $restored = [];

            public function __construct(private ?RuntimeException $failure = null) {}

            public function restore(User $source, BackupRecord $record, User $target): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $this->restored[] = [
                    'backup' => $record->id,
                    'source' => $source->username,
                    'target' => $target->username,
                ];
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
