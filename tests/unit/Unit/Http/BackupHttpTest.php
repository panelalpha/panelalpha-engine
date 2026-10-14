<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Jobs\CreateBackup;
use App\Jobs\DeleteBackup;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\BackupSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class BackupHttpTest extends TestCase
{
    use InMemoryDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        Queue::fake();

        $this->storageRoot = sys_get_temp_dir() . '/pa-backup-http-' . bin2hex(random_bytes(4));
        mkdir($this->storageRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storageRoot));
        parent::tearDown();
    }

    public function test_a_git_project_on_a_non_dind_template_is_a_dind_runtime(): void
    {
        $user = $this->makeUser('gitproject', ['template' => 'default', 'git_repo' => 'https://example.test/app.git']);

        $this->assertTrue($user->project()->runsInDind());
    }

    public function test_creating_a_backup_refuses_an_empty_selection(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();

        $response = $this->postJson("/api/projects/{$user->username}/backups", [
            'container' => (string) $container->id,
            'files' => false,
            'databases' => false,
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('problems.0.code', 'nothing_to_back_up');
    }

    public function test_creating_a_classic_backup_records_kind_and_contents_and_queues_the_job(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();

        $response = $this->postJson("/api/projects/{$user->username}/backups", [
            'container' => (string) $container->id,
            'files' => false,
            'note' => 'before the release',
        ]);

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.kind', 'manual');
        $response->assertJsonPath('data.note', 'before the release');
        $response->assertJsonPath('data.contents.files', false);
        $response->assertJsonPath('data.contents.databases', true);

        $backupId = (int) $response->json('data.id');
        Queue::assertPushed(CreateBackup::class, static fn (CreateBackup $job): bool => $job->backupId === $backupId);

        $record = BackupRecord::query()->findOrFail($backupId);
        $this->assertSame('manual', $record->kind);
        $this->assertSame(['files' => false, 'databases' => true], $record->contents);
    }

    public function test_listing_filters_by_kind(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();
        $this->completedBackup($user, $container, 'manual');
        $this->completedBackup($user, $container, 'scheduled');

        $response = $this->getJson("/api/projects/{$user->username}/backups?kind=scheduled");

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('scheduled', $response->json('data.0.kind'));
    }

    public function test_restoring_volumes_on_classic_is_refused(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();
        $backup = $this->completedBackup($user, $container);

        $response = $this->postJson("/api/projects/{$user->username}/backups/{$backup->id}/restore", [
            'confirm' => true,
            'only' => ['volumes' => ['dbdata']],
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('problems.0.code', 'volumes_not_applicable');
    }

    public function test_restoring_an_incomplete_backup_is_a_409(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();

        $backup = new BackupRecord();
        $backup->user_id = $user->id;
        $backup->username = $user->username;
        $backup->container_id = $container->id;
        $backup->async_status = ['backup' => 'pending'];
        $backup->save();

        $response = $this->postJson("/api/projects/{$user->username}/backups/{$backup->id}/restore", [
            'confirm' => true,
        ]);

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('code', 'backup_not_completed');
    }

    public function test_downloading_a_local_item_streams_the_exact_bytes(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();
        $backup = $this->completedBackup($user, $container);

        $response = $this->get("/api/projects/{$user->username}/backups/{$backup->id}/items/{$backup->items->first()->id}/download");

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('stub-home', $response->streamedContent());
        $this->assertStringContainsString('application/gzip', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            $user->username . '-backup-' . $backup->id . '-home.tar.gz',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_downloading_another_projects_item_is_a_404(): void
    {
        $owner = $this->classicUser();
        $other = $this->classicUser();
        $container = $this->localContainer();
        $backup = $this->completedBackup($owner, $container);

        $response = $this->get("/api/projects/{$other->username}/backups/{$backup->id}/items/{$backup->items->first()->id}/download");

        $this->assertSame(404, $response->getStatusCode(), (string) $response->getContent());
    }

    public function test_schedule_is_null_until_set_then_returns_next_run(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();

        $this->getJson("/api/projects/{$user->username}/backup-schedule")
            ->assertStatus(200)
            ->assertJsonPath('data', null);

        $response = $this->putJson("/api/projects/{$user->username}/backup-schedule", [
            'container' => (string) $container->id,
            'cron' => '0 3 * * *',
            'keep_last' => 3,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('data.cron', '0 3 * * *');
        $response->assertJsonPath('data.keep_last', 3);
        $this->assertSame('03:00', \Illuminate\Support\Carbon::parse($response->json('data.next_run_at'))->setTimezone('UTC')->format('H:i'));
    }

    public function test_a_cron_more_frequent_than_hourly_is_refused(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();

        $response = $this->putJson("/api/projects/{$user->username}/backup-schedule", [
            'container' => (string) $container->id,
            'cron' => '* * * * *',
        ]);

        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('problems.0.code', 'cron_too_frequent');
    }

    public function test_an_invalid_cron_and_an_unknown_container_are_refused(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();

        $invalidCron = $this->putJson("/api/projects/{$user->username}/backup-schedule", [
            'container' => (string) $container->id,
            'cron' => 'every day',
        ]);
        $this->assertSame(422, $invalidCron->getStatusCode(), (string) $invalidCron->getContent());
        $invalidCron->assertJsonPath('problems.0.code', 'invalid_cron');

        $unknownContainer = $this->putJson("/api/projects/{$user->username}/backup-schedule", [
            'container' => 'no-such-store',
            'cron' => '0 3 * * *',
        ]);
        $this->assertSame(422, $unknownContainer->getStatusCode(), (string) $unknownContainer->getContent());
        $unknownContainer->assertJsonPath('problems.0.code', 'container_not_found');
    }

    public function test_deleting_a_schedule_is_idempotent(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();
        $this->putJson("/api/projects/{$user->username}/backup-schedule", [
            'container' => (string) $container->id,
            'cron' => '0 3 * * *',
        ])->assertStatus(200);

        $this->deleteJson("/api/projects/{$user->username}/backup-schedule")->assertStatus(204);
        $this->deleteJson("/api/projects/{$user->username}/backup-schedule")->assertStatus(204);
        $this->assertSame(0, BackupSchedule::query()->count());
    }

    public function test_deleting_a_container_used_by_a_schedule_is_refused(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();
        $this->putJson("/api/projects/{$user->username}/backup-schedule", [
            'container' => (string) $container->id,
            'cron' => '0 3 * * *',
        ])->assertStatus(200);

        $response = $this->deleteJson("/api/backup-containers/{$container->id}");

        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $response->assertJsonPath('code', 'container_in_use');
    }

    public function test_backup_retention_never_deletes_a_manual_backup(): void
    {
        $user = $this->classicUser();
        $container = $this->localContainer();
        $schedule = new BackupSchedule();
        $schedule->user_id = $user->id;
        $schedule->container_id = $container->id;
        $schedule->cron = '0 3 * * *';
        $schedule->keep_last = 2;
        $schedule->save();

        $manual = $this->completedBackup($user, $container, 'manual');
        for ($i = 0; $i < 3; $i++) {
            $this->completedBackup($user, $container, 'scheduled');
        }

        app(\App\Lib\Project\BackupRetention::class)->prune(BackupRecord::query()->where('kind', 'scheduled')->firstOrFail());

        Queue::assertPushed(DeleteBackup::class, 1);
        $this->assertNotNull($manual->fresh());
    }

    private function classicUser(): User
    {
        return $this->makeUser('cl' . bin2hex(random_bytes(4)), ['template' => 'default']);
    }

    private function localContainer(): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'store-' . bin2hex(random_bytes(4));
        $container->driver = 'local';
        $container->location = $this->storageRoot;
        $container->credentials = null;
        $container->save();

        return $container;
    }

    private function completedBackup(User $user, BackupContainer $container, string $kind = 'manual'): BackupRecord
    {
        $backup = BackupRecord::prepare($user, $container, $kind === 'scheduled' ? 'schedule' : 'api', kind: $kind);
        $backup->setBackupStatus('completed');
        $backup->save();

        $key = $user->username . '/' . $backup->id . '/home.tar.gz';
        $path = $this->storageRoot . '/' . $key;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'stub-home');

        $item = new BackupItem();
        $item->backup_id = $backup->id;
        $item->remote_path = $key;
        $item->size_bytes = strlen('stub-home');
        $item->details = ['type' => 'files', 'name' => 'home', 'scope' => 'home', 'sha256' => hash('sha256', 'stub-home')];
        $item->save();

        return $backup->fresh(['container', 'items']);
    }
}
