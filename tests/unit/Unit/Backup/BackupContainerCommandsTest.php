<?php

namespace Tests\Unit\Backup;

use App\Models\BackupContainer;
use Illuminate\Testing\PendingCommand;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * backup:container:create and backup:container:update, run non-interactively
 * as scripts do. Both only write the backup_containers row.
 */
class BackupContainerCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Credentials are an encrypted cast.
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    private function create(string $args): PendingCommand
    {
        return $this->artisan('backup:container:create --no-interaction '.$args);
    }

    private function update(string $args): PendingCommand
    {
        return $this->artisan('backup:container:update --no-interaction '.$args);
    }

    public function test_create_refuses_what_a_script_left_out_or_got_wrong(): void
    {
        BackupContainer::create(['name' => 'taken', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/taken', 'credentials' => null]);

        $cases = [
            ['--driver=local --location=/b', 'The --name option is required in non-interactive mode.'],
            ['--name=a --location=/b', 'The --driver option is required in non-interactive mode.'],
            ['--name=a --driver=local', 'The --location option is required in non-interactive mode.'],
            ['--name=a --driver=nfs --location=/b', 'Driver must be one of: local, s3, ftp, ftps, sftp.'],
            ['--name=a --driver=local --location='.str_repeat('x', 1025), 'Storage location must be at most 1024 characters.'],
            ['--name=a --driver=local --location=/home', 'Local backup location must not be empty, filesystem root, or /home.'],
            ['--name=a --driver=local --location=/opt/panelalpha/backups/../etc', 'Local backup location must not contain .. segments.'],
            ['--name=taken --driver=local --location=/opt/panelalpha/backups/taken', "A backup container named 'taken' already exists."],
            ['--name=a --driver=s3 --location=bucket', 'The --s3-access-key-id option is required in non-interactive mode for s3.'],
            ['--name=a --driver=s3 --location=bucket --s3-access-key-id=K', 'The --s3-secret-access-key option is required in non-interactive mode for s3.'],
            ['--name=a --driver=ftp --location=/b', 'The --ftp-host option is required in non-interactive mode for ftp/ftps.'],
            ['--name=a --driver=ftps --location=/b --ftp-host=h', 'The --ftp-username option is required in non-interactive mode for ftp/ftps.'],
            ['--name=a --driver=sftp --location=/b', 'The --sftp-host option is required in non-interactive mode for sftp.'],
            ['--name=a --driver=sftp --location=/b --sftp-host=h', 'The --sftp-username option is required in non-interactive mode for sftp.'],
        ];

        foreach ($cases as [$args, $message]) {
            $this->create($args)->expectsOutputToContain($message)->assertExitCode(1);
        }

        $this->assertSame(1, BackupContainer::query()->count());
    }

    public function test_create_stores_the_credentials_the_options_give(): void
    {
        $this->create('--force --name=s3box --driver=s3 --location=bucket --s3-access-key-id=K --s3-secret-access-key=S --s3-region=eu --s3-use-path-style --ftp-host=ignored')
            ->expectsOutputToContain('Has credentials: yes')
            ->assertExitCode(0);

        $box = BackupContainer::query()->where('name', 's3box')->firstOrFail();
        $this->assertSame([
            'access_key_id' => 'K',
            'secret_access_key' => 'S',
            'region' => 'eu',
            'use_path_style_endpoint' => true,
        ], $box->credentials);
    }

    public function test_create_stores_no_credentials_for_local_storage(): void
    {
        $this->create('--force --name=disk --driver=local --location=/opt/panelalpha/backups/disk')
            ->expectsOutputToContain('Has credentials: no')
            ->assertExitCode(0);

        $this->assertNull(BackupContainer::query()->where('name', 'disk')->firstOrFail()->credentials);
    }

    public function test_update_refuses_what_would_leave_the_container_unusable(): void
    {
        BackupContainer::create(['name' => 'disk', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/disk', 'credentials' => null]);
        BackupContainer::create(['name' => 'other', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/other', 'credentials' => null]);

        $cases = [
            ['missing', "Backup container 'missing' not found."],
            ['disk --name=other', "A backup container named 'other' already exists."],
            ['disk --driver=nfs', 'Driver must be one of: local, s3, ftp, ftps, sftp.'],
            ['disk', 'No updates provided.'],
            ['disk --driver=s3', 'Credentials are required for non-local backup storage.'],
            ['disk --driver=sftp --sftp-host=h', 'Credential username is required for this driver.'],
            ['disk --location=/var/backups/v', 'Local backup location must be under a path this engine persists'],
            ['disk --driver=local --location=/home', 'Local backup location must not be empty, filesystem root, or /home.'],
        ];

        foreach ($cases as [$args, $message]) {
            $this->update($args)->expectsOutputToContain($message)->assertExitCode(1);
        }

        $this->assertSame('/opt/panelalpha/backups/disk', BackupContainer::query()->where('name', 'disk')->firstOrFail()->location);
    }

    public function test_create_and_update_refuse_a_local_location_in_the_engine_directory(): void
    {
        BackupContainer::create(['name' => 'disk', 'driver' => 'local', 'location' => '/opt/panelalpha/backups/disk', 'credentials' => null]);
        $refusal = "Local backup location must not be inside the engine's directory (/opt/panelalpha/shared-hosting)";

        $this->create('--force --name=inside --driver=local --location=/opt/panelalpha/shared-hosting/backups')
            ->expectsOutputToContain($refusal)
            ->assertExitCode(1);
        $this->update('disk --force --location=/opt/panelalpha/shared-hosting/backups')
            ->expectsOutputToContain($refusal)
            ->assertExitCode(1);

        $this->assertSame(1, BackupContainer::query()->count());
        $this->assertSame('/opt/panelalpha/backups/disk', BackupContainer::query()->where('name', 'disk')->firstOrFail()->location);
    }

    public function test_a_failing_store_test_names_what_failed(): void
    {
        BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'my-bucket', 'credentials' => [
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI-K7MDENG',
            'endpoint' => 'http://127.0.0.1:1',
            'use_path_style_endpoint' => true,
        ]]);

        $this->artisan('backup:container:test bucket')
            ->expectsOutputToContain('Backup storage is not writable: Error executing "PutObject" on "http://127.0.0.1:1/my-bucket/')
            ->assertExitCode(1);
    }

    public function test_update_merges_new_credentials_over_the_stored_ones(): void
    {
        $box = BackupContainer::create([
            'name' => 'ftpbox',
            'driver' => 'ftp',
            'location' => '/b',
            'credentials' => ['host' => 'old.example', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->update("{$box->id} --force --ftp-host=new.example --ftp-port=2121")
            ->expectsOutputToContain('Credentials: updated')
            ->expectsOutputToContain('Backup container updated successfully.')
            ->assertExitCode(0);

        $this->assertSame(
            ['host' => 'new.example', 'username' => 'u', 'password' => 'p', 'port' => 2121],
            $box->fresh()->credentials
        );
    }

    public function test_switching_to_local_without_a_location_checks_the_kept_location(): void
    {
        BackupContainer::create(['name' => 'bucket', 'driver' => 's3', 'location' => 'my-bucket', 'credentials' => ['access_key_id' => 'a', 'secret_access_key' => 'b']]);
        BackupContainer::create(['name' => 'ftpdir', 'driver' => 'ftp', 'location' => '/opt/panelalpha/backups/ftpdir', 'credentials' => ['host' => 'h', 'username' => 'u']]);

        $this->update('bucket --force --driver=local')
            ->expectsOutputToContain('Local backup location must be under a path this engine persists')
            ->assertExitCode(1);
        $this->update('ftpdir --force --driver=local')->assertExitCode(0);

        $this->assertSame('s3', BackupContainer::query()->where('name', 'bucket')->firstOrFail()->driver);
        $this->assertSame('local', BackupContainer::query()->where('name', 'ftpdir')->firstOrFail()->driver);
    }

    public function test_switching_to_local_drops_the_credentials(): void
    {
        BackupContainer::create([
            'name' => 'ftpbox',
            'driver' => 'ftp',
            'location' => '/b',
            'credentials' => ['host' => 'h', 'username' => 'u'],
        ]);

        $this->update('ftpbox --force --driver=local --location=/opt/panelalpha/backups/ftpbox')
            ->expectsOutputToContain('Credentials: none')
            ->assertExitCode(0);

        $this->assertNull(BackupContainer::query()->where('name', 'ftpbox')->firstOrFail()->credentials);
    }
}
