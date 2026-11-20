<?php

namespace Tests\Integration\Backup;

use App\Models\BackupContainer;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The backup:container:* commands run from a terminal: what they ask, what an answer
 * of no leaves, and the answers for a store that does not exist.
 */
class BackupContainerInteractiveCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private const DRIVERS = ['local', 's3', 'ftp', 'ftps', 'sftp'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-backup-container-tty-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_create_asks_for_what_the_options_left_out_and_answering_no_creates_nothing(): void
    {
        $this->artisan('backup:container:create')
            ->expectsQuestion('Container name', 'disk')
            ->expectsChoice('Storage driver', 'local', self::DRIVERS)
            ->expectsQuestion('Storage location', '/opt/panelalpha/backups/disk')
            ->expectsOutputToContain('Name: disk')
            ->expectsOutputToContain('Location: /opt/panelalpha/backups/disk')
            ->expectsOutputToContain('Has credentials: no')
            ->expectsConfirmation('Create this container?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(0, BackupContainer::query()->count());
    }

    public function test_create_refuses_an_empty_name_or_location_answer(): void
    {
        $this->artisan('backup:container:create --driver=local --location=/opt/panelalpha/backups/disk')
            ->expectsQuestion('Container name', '')
            ->expectsOutputToContain('Container name is required.')
            ->assertExitCode(1);

        $this->artisan('backup:container:create --name=disk --driver=local')
            ->expectsQuestion('Storage location', '')
            ->expectsOutputToContain('Storage location is required.')
            ->assertExitCode(1);

        $this->assertSame(0, BackupContainer::query()->count());
    }

    public function test_update_answered_no_changes_nothing(): void
    {
        $box = $this->container('box');

        $this->artisan('backup:container:update box --location=/opt/panelalpha/backups/moved')
            ->expectsOutputToContain('Location: /opt/panelalpha/backups/moved')
            ->expectsConfirmation('Apply these changes?', 'no')
            ->expectsOutputToContain('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame('/opt/panelalpha/backups/box', $box->fresh()->location);
    }

    public function test_update_renames_the_container(): void
    {
        $box = $this->container('box');

        $this->artisan('backup:container:update --no-interaction --force box --name=archive')
            ->expectsOutputToContain('Name: archive')
            ->expectsOutputToContain('Backup container updated successfully.')
            ->assertExitCode(0);

        $this->assertSame('archive', $box->fresh()->name);
    }

    public function test_list_of_a_host_without_containers_says_so(): void
    {
        $this->artisan('backup:container:list')
            ->expectsOutputToContain('No backup containers found.')
            ->assertExitCode(0);
    }

    public function test_show_and_test_refuse_an_unknown_container(): void
    {
        $this->artisan('backup:container:show missing')
            ->expectsOutputToContain("Backup container 'missing' not found.")
            ->assertExitCode(1);
        $this->artisan('backup:container:test missing')
            ->expectsOutputToContain("Backup container 'missing' not found.")
            ->assertExitCode(1);
    }

    public function test_a_writable_local_store_passes_the_test(): void
    {
        $this->container('disk', $this->root);

        $this->artisan('backup:container:test disk')
            ->expectsOutputToContain('Backup storage connection test succeeded.')
            ->assertExitCode(0);

        $this->assertDirectoryExists($this->root);
        $this->assertSame([], array_values(array_diff(scandir($this->root), ['.', '..'])));
    }

    private function container(string $name, ?string $location = null): BackupContainer
    {
        return BackupContainer::create([
            'name' => $name,
            'driver' => 'local',
            'location' => $location ?? "/opt/panelalpha/backups/{$name}",
            'credentials' => null,
        ]);
    }
}
