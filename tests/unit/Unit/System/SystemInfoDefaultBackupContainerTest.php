<?php

namespace Tests\Unit\System;

use App\Http\Controllers\SystemController;
use App\Models\BackupContainer;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The panel reads `default_backup_container` off GET /system/info to back up
 * without being configured with a store first.
 */
class SystemInfoDefaultBackupContainerTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
    }

    public function test_it_reports_null_while_the_host_has_no_store(): void
    {
        $this->assertNull(SystemController::defaultBackupContainer());
    }

    public function test_it_reports_the_name_the_driver_and_the_location_without_credentials(): void
    {
        $container = BackupContainer::create([
            'name' => 's3-main',
            'driver' => 's3',
            'location' => 'my-bucket',
            'credentials' => ['access_key_id' => 'AKIAIOSFODNN7EXAMPLE', 'secret_access_key' => 'wJalrXUtnFEMI'],
        ]);
        $container->makeDefault();

        $reported = SystemController::defaultBackupContainer();

        $this->assertSame([
            'id' => $container->id,
            'name' => 's3-main',
            'driver' => 's3',
            'location' => 'my-bucket',
        ], $reported);
        $this->assertStringNotContainsString('wJalrXUtnFEMI', (string) json_encode($reported));
    }
}
