<?php

namespace Tests\Integration\Backup;

use App\Console\Commands\Backup\Container\EnsureDefaultCommand;
use App\Models\Backup;
use App\Models\BackupContainer;
use App\Models\BackupSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A host with no backup store gets the local one, and it is the default, so a
 * panel can back a project up without being configured first.
 */
class DefaultBackupContainerTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_a_host_without_a_store_gets_the_local_one_as_the_default(): void
    {
        $this->artisan('backup:container:ensure-default')
            ->expectsOutputToContain('Default backup container created')
            ->assertExitCode(0);

        $container = BackupContainer::query()->sole();
        $this->assertSame(EnsureDefaultCommand::NAME, $container->name);
        $this->assertSame('local', $container->driver);
        $this->assertSame(EnsureDefaultCommand::LOCATION, $container->location);
        $this->assertNull($container->credentials);
        $this->assertTrue($container->is_default);
    }

    public function test_a_store_the_operator_made_is_kept_and_nothing_is_created(): void
    {
        $mine = $this->container('s3-main', 's3', 'my-bucket');

        $this->artisan('backup:container:ensure-default')
            ->expectsOutputToContain("A backup container already exists (s3-main); nothing created.")
            ->assertExitCode(0);

        $this->assertSame(1, BackupContainer::query()->count());
        $mine->refresh();
        $this->assertSame('s3-main', $mine->name);
        $this->assertSame('my-bucket', $mine->location);
        $this->assertTrue($mine->is_default);
    }

    public function test_a_second_run_creates_no_second_store(): void
    {
        $this->artisan('backup:container:ensure-default')->assertExitCode(0);
        $first = BackupContainer::query()->sole();

        $this->artisan('backup:container:ensure-default')->assertExitCode(0);

        $this->assertSame(1, BackupContainer::query()->count());
        $this->assertSame($first->id, BackupContainer::query()->sole()->id);
    }

    public function test_a_host_whose_stores_lost_the_default_gets_one_back(): void
    {
        $oldest = $this->container('one');
        $this->container('two');
        DB::table('backup_containers')->update(['is_default' => false]);

        $this->artisan('backup:container:ensure-default')->assertExitCode(0);

        $this->assertSame(2, BackupContainer::query()->count());
        $this->assertTrue($oldest->fresh()->is_default);
    }

    public function test_a_store_claims_the_flag_only_while_the_host_has_no_default(): void
    {
        $first = $this->container('made-by-hand');
        $first->claimDefaultWhenNone();
        $second = $this->container('later');
        $second->claimDefaultWhenNone();

        $this->assertTrue($first->fresh()->is_default);
        $this->assertSame('made-by-hand', BackupContainer::defaultContainer()?->name);
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_deleting_the_default_hands_the_flag_to_the_oldest_store_left(): void
    {
        $first = $this->container('first');
        $second = $this->container('second');
        $third = $this->container('third');
        $second->makeDefault();

        $second->deleteWithEmptyLocation();

        $this->assertSame($first->id, BackupContainer::defaultContainer()?->id);
        $this->assertNull($second->fresh());
        $this->assertFalse($third->fresh()->is_default);
    }

    public function test_deleting_a_store_that_is_not_the_default_leaves_it_alone(): void
    {
        $first = $this->container('first');
        $second = $this->container('second');
        $first->makeDefault();

        $second->deleteWithEmptyLocation();

        $this->assertSame($first->id, BackupContainer::defaultContainer()?->id);
    }

    public function test_a_delete_the_database_refuses_keeps_the_schedules_and_the_default(): void
    {
        $other = $this->container('other');
        $store = $this->container('store');
        $store->makeDefault();
        $user = $this->makeUser('alice', ['template' => 'default']);
        BackupSchedule::query()->create([
            'user_id' => $user->id,
            'container_id' => $store->id,
            'cron' => '0 3 * * *',
            'keep_last' => 7,
            'files' => true,
            'databases' => true,
            'enabled' => false,
        ]);
        Backup::prepare($user, $store, 'artisan');

        try {
            $store->deleteWithEmptyLocation();
            $this->fail('The backup row should hold the store.');
        } catch (QueryException) {
        }

        $this->assertNotNull($store->fresh());
        $this->assertSame(1, BackupSchedule::query()->where('container_id', $store->id)->count());
        $this->assertSame($store->id, BackupContainer::defaultContainer()?->id);
        $this->assertFalse($other->fresh()->is_default);
    }

    private function container(string $name, string $driver = 'local', ?string $location = null): BackupContainer
    {
        $container = BackupContainer::create([
            'name' => $name,
            'driver' => $driver,
            'location' => $location ?? "/opt/panelalpha/backups/{$name}",
            'credentials' => null,
        ]);

        return $container;
    }
}
