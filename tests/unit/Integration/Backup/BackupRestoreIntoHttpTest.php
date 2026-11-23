<?php

namespace Tests\Integration\Backup;

use App\Http\Middleware\Authenticate;
use App\Jobs\RestoreBackupInto;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\Task;
use App\Models\User;
use App\System;
use App\System\Project\Dind\AppDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class BackupRestoreIntoHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $storageRoot;

    /** Created here when the environment has no engine templates installed; removed again. */
    private ?string $createdTemplateDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->provideDindTemplate();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();
        LocalRoot::answerLocally();

        $this->storageRoot = sys_get_temp_dir().'/pa-backup-into-http-'.bin2hex(random_bytes(4));
        mkdir($this->storageRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf '.escapeshellarg($this->storageRoot));
        if ($this->createdTemplateDir !== null) {
            @rmdir($this->createdTemplateDir);
            @rmdir(dirname($this->createdTemplateDir));
            @rmdir(dirname($this->createdTemplateDir, 2));
        }
        parent::tearDown();
    }

    /**
     * A create refuses a template that is not installed, and the create path
     * reads the engine's own template directory rather than a bound one.
     */
    private function provideDindTemplate(): void
    {
        $dir = (new System)->projectFilesTemplateDirPath('dind');
        if (is_dir($dir)) {
            return;
        }

        if (! @mkdir($dir, 0777, true)) {
            $this->markTestSkipped('The dind project template is not installed in this environment.');
        }

        $this->createdTemplateDir = $dir;
    }

    public function test_a_container_backup_restores_into_a_new_pending_project(): void
    {
        $source = $this->dindUser();
        $backup = $this->completedBackup($source, $this->localContainer());

        $response = $this->postJson($this->uri($source, $backup), [
            'new_username' => 'copyproj',
            'domain' => 'copyproj.test',
        ]);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.username', 'copyproj');
        $response->assertJsonPath('data.domain', 'copyproj.test');
        $response->assertJsonPath('data.status', 'pending');
        $this->assertIsInt($response->json('task_id'));

        $target = User::findByUsername('copyproj');
        $this->assertNotNull($target);
        $this->assertSame('pending', $target->asyncStatus()['restore']);
        $this->assertSame('dind', $target->getDetails()['template']);
        $this->assertSame($source->getDeployStrategy(), $target->getDeployStrategy());

        Queue::assertPushed(RestoreBackupInto::class, static fn (RestoreBackupInto $job): bool => $job->backupId === $backup->id
            && $job->targetUsername === 'copyproj');

        $backup->refresh();
        $this->assertNull($backup->restore_details);
        $this->assertSame('completed', $backup->backupStatus());
    }

    public function test_the_backup_is_recorded_on_the_new_projects_task(): void
    {
        $source = $this->dindUser();
        $backup = $this->completedBackup($source, $this->localContainer());

        $response = $this->postJson($this->uri($source, $backup), ['new_username' => 'copyproj', 'domain' => 'copyproj.test']);

        $task = Task::query()->findOrFail((int) $response->json('task_id'));
        $this->assertSame('copyproj', $task->username);
        $this->assertSame('restore_into', $task->details['action']);
        $this->assertSame($backup->id, $task->details['backup_id']);
        $this->assertSame($source->username, $task->details['source']);
    }

    public function test_a_php_hosting_backup_is_refused(): void
    {
        $source = $this->classicUser();
        $backup = $this->completedBackup($source, $this->localContainer());

        $response = $this->postJson($this->uri($source, $backup), ['new_username' => 'copyproj', 'domain' => 'copyproj.test']);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('problems.0.code', 'restore_into_unsupported');
        $this->assertNull(User::findByUsername('copyproj'));
        Queue::assertNothingPushed();
    }

    /** Another database's login stays the source's in every file that names it, so the copy would use the source's data. */
    public function test_a_backup_holding_another_database_is_refused(): void
    {
        $source = $this->dindUser();
        $backup = $this->completedBackup($source, $this->localContainer());
        $this->addDatabaseItem($backup, AppDatabase::nameFor($source));
        $this->addDatabaseItem($backup, $source->getMysqlPrefix().'shop');

        $response = $this->postJson($this->uri($source, $backup), ['new_username' => 'copyproj', 'domain' => 'copyproj.test']);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('problems.0.code', 'restore_into_other_databases');
        $this->assertStringContainsString($source->getMysqlPrefix().'shop', (string) $response->json('problems.0.message'));
        $this->assertNull(User::findByUsername('copyproj'));
        Queue::assertNothingPushed();
    }

    public function test_a_backup_holding_only_the_application_database_is_restored(): void
    {
        $source = $this->dindUser();
        $backup = $this->completedBackup($source, $this->localContainer());
        $this->addDatabaseItem($backup, AppDatabase::nameFor($source));

        $response = $this->postJson($this->uri($source, $backup), ['new_username' => 'copyproj', 'domain' => 'copyproj.test']);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        Queue::assertPushed(RestoreBackupInto::class);
    }

    public function test_an_incomplete_backup_is_a_409(): void
    {
        $source = $this->dindUser();
        $container = $this->localContainer();
        $backup = new BackupRecord();
        $backup->user_id = $source->id;
        $backup->username = $source->username;
        $backup->container_id = $container->id;
        $backup->async_status = ['backup' => 'pending'];
        $backup->save();

        $response = $this->postJson($this->uri($source, $backup), ['new_username' => 'copyproj', 'domain' => 'copyproj.test']);

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('code', 'backup_not_completed');
        Queue::assertNothingPushed();
    }

    public function test_another_projects_backup_is_a_404(): void
    {
        $owner = $this->dindUser();
        $other = $this->dindUser();
        $backup = $this->completedBackup($owner, $this->localContainer());

        $response = $this->postJson($this->uri($other, $backup), ['new_username' => 'copyproj', 'domain' => 'copyproj.test']);

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        Queue::assertNothingPushed();
    }

    public function test_the_new_project_needs_a_name(): void
    {
        $source = $this->dindUser();
        $backup = $this->completedBackup($source, $this->localContainer());

        $response = $this->postJson($this->uri($source, $backup), ['domain' => 'copyproj.test']);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonValidationErrors('new_username');
        Queue::assertNothingPushed();
    }

    public function test_a_taken_name_is_refused(): void
    {
        $source = $this->dindUser();
        $taken = $this->dindUser();
        $backup = $this->completedBackup($source, $this->localContainer());

        $response = $this->postJson($this->uri($source, $backup), [
            'new_username' => $taken->username,
            'domain' => 'copyproj.test',
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('problems.0.code', 'name_taken');
        Queue::assertNothingPushed();
    }

    private function uri(User $source, BackupRecord $backup): string
    {
        return "/api/projects/{$source->username}/backups/{$backup->id}/restore-into";
    }

    private function dindUser(): User
    {
        return $this->makeUser('d'.bin2hex(random_bytes(4)), [
            'template' => 'dind',
            'deploy_strategy' => 'compose',
            'deploy_label' => 'Compose',
            'mysql_prefix' => 'd',
        ]);
    }

    private function classicUser(): User
    {
        return $this->makeUser('c'.bin2hex(random_bytes(4)), ['template' => 'default', 'mysql_prefix' => 'c']);
    }

    private function localContainer(): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'store-'.bin2hex(random_bytes(4));
        $container->driver = 'local';
        $container->location = $this->storageRoot;
        $container->credentials = null;
        $container->save();

        return $container;
    }

    private function completedBackup(User $user, BackupContainer $container): BackupRecord
    {
        $backup = BackupRecord::prepare($user, $container, 'api');
        $backup->setBackupStatus('completed');
        $backup->save();

        $key = $user->username.'/'.$backup->id.'/files.tar.gz';
        $path = $this->storageRoot.'/'.$key;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'stub-files');

        $item = new BackupItem();
        $item->backup_id = $backup->id;
        $item->remote_path = $key;
        $item->size_bytes = strlen('stub-files');
        $item->details = ['type' => 'files', 'name' => 'files', 'sha256' => hash('sha256', 'stub-files')];
        $item->save();

        return $backup->fresh(['container', 'items']);
    }

    private function addDatabaseItem(BackupRecord $backup, string $name): void
    {
        $item = new BackupItem();
        $item->backup_id = $backup->id;
        $item->remote_path = $backup->username.'/'.$backup->id.'/'.$name.'.sql.gz';
        $item->size_bytes = 1;
        $item->details = ['type' => 'database', 'name' => $name, 'sha256' => hash('sha256', $name)];
        $item->save();
        $backup->unsetRelation('items');
    }
}
