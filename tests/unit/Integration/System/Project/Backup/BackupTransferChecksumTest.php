<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Support\JobDeadline;
use App\System\Project\Backup\BackupTransfer;
use Tests\TestCase;

/**
 * Items are keyed under the container's prefix, and an item downloaded without a recorded checksum is never restored.
 */
class BackupTransferChecksumTest extends TestCase
{
    private string $staging;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->staging = sys_get_temp_dir().'/pa-backup-transfer-'.bin2hex(random_bytes(5)).'/';
        mkdir($this->staging, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->staging.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->staging);
        parent::tearDown();
    }

    public function test_the_key_prefix_is_the_containers_own_under_which_each_backup_has_its_folder(): void
    {
        $this->assertSame('engine/backups/alice/12/', BackupTransfer::prefix($this->container(['prefix' => 'engine/backups/']), 'alice', 12));
        $this->assertSame('alice/12/', BackupTransfer::prefix($this->container(['prefix' => '']), 'alice', 12));
        $this->assertSame('alice/12/', BackupTransfer::prefix($this->container(['prefix' => ['not', 'text']]), 'alice', 12));
        $this->assertSame('alice/12/', BackupTransfer::prefix($this->container(['bucket' => 'b']), 'alice', 12));
    }

    public function test_an_item_without_a_recorded_checksum_is_refused_after_download(): void
    {
        $storage = new FakeStorage();
        $storage->objects['alice/12/files.tar.gz'] = 'tar';
        $item = new BackupItem;
        $item->id = 3;
        $item->remote_path = 'alice/12/files.tar.gz';
        $item->details = ['type' => 'files', 'name' => 'files'];

        try {
            $this->transfer()->download(collect([$item]), $storage, $this->staging);
            $this->fail('an item without a checksum must not be restored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Backup item is missing sha256 checksum.', $e->getMessage());
        }

        $this->assertSame(['readStream:alice/12/files.tar.gz'], $storage->calls);
    }

    public function test_an_item_whose_checksum_matches_is_staged(): void
    {
        $storage = new FakeStorage();
        $storage->objects['alice/12/files.tar.gz'] = 'tar';
        $item = new BackupItem;
        $item->id = 3;
        $item->remote_path = 'alice/12/files.tar.gz';
        $item->details = ['type' => 'files', 'name' => 'files', 'sha256' => hash('sha256', 'tar')];

        $staged = $this->transfer()->download(collect([$item]), $storage, $this->staging);

        $this->assertSame([3 => $this->staging.'files.tar.gz'], $staged);
        $this->assertSame('tar', file_get_contents($this->staging.'files.tar.gz'));
    }

    /** @param  array<string, mixed>  $credentials */
    private function container(array $credentials): BackupContainer
    {
        $container = new BackupContainer;
        $container->credentials = $credentials;

        return $container;
    }

    private function transfer(): BackupTransfer
    {
        $record = new BackupRecord;
        $record->id = 12;
        $record->username = 'alice';

        return new BackupTransfer($record, JobDeadline::startingNow());
    }
}
