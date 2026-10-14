<?php

namespace Tests\Unit\Backup;

use App\Models\BackupContainer;
use App\Models\BackupItem;
use Tests\TestCase;

class BackupModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_container_credentials_are_encrypted_at_rest(): void
    {
        $row = new BackupContainer();
        $row->name = 's3-main';
        $row->driver = 's3';
        $row->location = 'bucket';
        $row->credentials = ['secret_access_key' => 'super-secret'];

        $raw = (string) $row->getAttributes()['credentials'];
        $this->assertStringNotContainsString('super-secret', $raw);
        json_decode($raw);
        $this->assertNotSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'encrypted credentials are ciphertext, so the column must not be JSON',
        );
        $this->assertSame('super-secret', $row->credentials['secret_access_key']);
    }

    public function test_credentials_column_is_declared_as_long_text(): void
    {
        $create = file_get_contents(base_path('database/migrations/2026_09_04_120000_create_backup_containers_table.php'));
        $this->assertNotFalse($create);
        $this->assertStringContainsString("longText('credentials')", $create);
        $this->assertStringNotContainsString("json('credentials')", $create);

        $alters = glob(base_path('database/migrations/*change_backup_container_credentials_to_longtext.php'));
        $this->assertNotSame([], $alters, 'existing installs need an ALTER that drops JSON_VALID');
        $alter = file_get_contents($alters[0]);
        $this->assertNotFalse($alter);
        $this->assertStringContainsString('MODIFY credentials LONGTEXT', $alter);
        $this->assertStringNotContainsString('CHECK (json_valid', $alter);
    }

    public function test_backup_item_details_round_trip(): void
    {
        $item = new BackupItem();
        $item->details = [
            'type' => 'volume',
            'name' => 'dbdata',
            'sha256' => str_repeat('a', 64),
            'docker_name' => 'proj_dbdata',
        ];
        $this->assertSame('volume', $item->details['type']);
        $this->assertSame('proj_dbdata', $item->details['docker_name']);
    }
}
