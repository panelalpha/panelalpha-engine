<?php

namespace Tests\Unit\Backup;

use App\Http\Resources\BackupContainerResource;
use App\Models\BackupContainer;
use Tests\TestCase;

class BackupContainerResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_resource_hides_credentials_and_exposes_has_credentials(): void
    {
        $withCredentials = new BackupContainer();
        $withCredentials->id = 1;
        $withCredentials->name = 's3-main';
        $withCredentials->driver = 's3';
        $withCredentials->location = 'my-bucket';
        $withCredentials->credentials = ['secret_access_key' => 'super-secret'];
        $withCredentials->created_at = now();
        $withCredentials->updated_at = now();

        $array = (new BackupContainerResource($withCredentials))->toArray(request());

        $this->assertTrue($array['has_credentials']);
        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertStringNotContainsString('super-secret', json_encode($array));

        $withoutCredentials = new BackupContainer();
        $withoutCredentials->id = 2;
        $withoutCredentials->name = 'local-main';
        $withoutCredentials->driver = 'local';
        $withoutCredentials->location = '/var/backups';
        $withoutCredentials->credentials = null;
        $withoutCredentials->created_at = now();
        $withoutCredentials->updated_at = now();

        $localArray = (new BackupContainerResource($withoutCredentials))->toArray(request());

        $this->assertFalse($localArray['has_credentials']);
        $this->assertArrayNotHasKey('credentials', $localArray);
    }
}
