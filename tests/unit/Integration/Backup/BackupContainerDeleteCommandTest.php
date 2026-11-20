<?php

namespace Tests\Integration\Backup;

use App\Models\BackupContainer;
use App\Models\BackupSchedule;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * backup:container:delete refuses a store a schedule still points at, as the
 * REST route does, instead of letting the database reject the row.
 */
class BackupContainerDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_a_container_a_schedule_uses_is_refused(): void
    {
        $box = $this->container('box');
        $user = $this->makeUser('alice', ['template' => 'dind']);
        BackupSchedule::create([
            'user_id' => $user->id,
            'container_id' => $box->id,
            'cron' => '0 3 * * *',
            'keep_last' => 3,
        ]);

        $this->artisan("backup:container:delete --no-interaction {$box->id} --force")
            ->expectsOutputToContain("Cannot delete backup container while a project's automatic backup schedule uses it: alice. Disable or delete those schedules first.")
            ->assertExitCode(1);

        $this->assertNotNull($box->fresh());
        $this->assertSame(1, BackupSchedule::query()->count());
    }

    public function test_a_disabled_schedule_does_not_keep_the_container(): void
    {
        $box = $this->container('box');
        $user = $this->makeUser('alice', ['template' => 'dind']);
        BackupSchedule::create([
            'user_id' => $user->id,
            'container_id' => $box->id,
            'cron' => '0 3 * * *',
            'keep_last' => 3,
            'enabled' => false,
        ]);

        $this->artisan("backup:container:delete --no-interaction {$box->id} --force")
            ->expectsOutputToContain('Backup container deleted successfully.')
            ->assertExitCode(0);

        $this->assertNull($box->fresh());
        $this->assertSame(0, BackupSchedule::query()->count());
    }

    public function test_a_container_no_schedule_uses_is_deleted(): void
    {
        $box = $this->container('box');

        $this->artisan("backup:container:delete --no-interaction {$box->id} --force")
            ->expectsOutputToContain('Backup container deleted successfully.')
            ->assertExitCode(0);

        $this->assertNull($box->fresh());
    }

    private function container(string $name): BackupContainer
    {
        return BackupContainer::create([
            'name' => $name,
            'driver' => 'local',
            'location' => "/opt/panelalpha/backups/{$name}",
            'credentials' => null,
        ]);
    }
}
