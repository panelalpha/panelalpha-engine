<?php

namespace Tests\Integration\Backup;

use App\Models\BackupContainer;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The store the host backs up into is visible and changeable from the CLI, as
 * it is over the API.
 */
class BackupContainerDefaultCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_the_first_store_a_host_made_from_the_cli_is_the_default(): void
    {
        $this->artisan('backup:container:create --no-interaction --force --name=box --driver=local --location=/opt/panelalpha/backups/box')
            ->expectsOutputToContain('It is the default backup container.')
            ->assertExitCode(0);

        $this->assertTrue(BackupContainer::query()->where('name', 'box')->sole()->is_default);
    }

    public function test_the_default_moves_to_the_store_the_operator_names(): void
    {
        $this->artisan('backup:container:create --no-interaction --force --name=local --driver=local --location=/opt/panelalpha/backups/local')->assertExitCode(0);
        $this->artisan('backup:container:create --no-interaction --force --name=s3-main --driver=s3 --location=bucket --s3-access-key-id=K --s3-secret-access-key=S')->assertExitCode(0);

        $this->artisan('backup:container:update --no-interaction --force s3-main --default')
            ->expectsOutputToContain('Default: yes')
            ->assertExitCode(0);

        $this->assertSame('s3-main', BackupContainer::defaultContainer()?->name);
        $this->assertFalse(BackupContainer::query()->where('name', 'local')->sole()->is_default);
    }

    public function test_the_list_marks_the_default_and_the_show_says_it(): void
    {
        $this->artisan('backup:container:create --no-interaction --force --name=local --driver=local --location=/opt/panelalpha/backups/local')->assertExitCode(0);

        $this->artisan('backup:container:list')
            ->expectsOutputToContain('Default')
            ->expectsOutputToContain('yes')
            ->assertExitCode(0);

        $this->artisan('backup:container:show local')
            ->expectsOutputToContain('Default: yes')
            ->assertExitCode(0);
    }

    public function test_an_update_with_nothing_to_change_is_refused(): void
    {
        BackupContainer::create([
            'name' => 'box',
            'driver' => 'local',
            'location' => '/opt/panelalpha/backups/box',
            'credentials' => null,
        ]);

        $this->artisan('backup:container:update --no-interaction --force box')
            ->expectsOutputToContain('No updates provided.')
            ->assertExitCode(1);
    }
}
