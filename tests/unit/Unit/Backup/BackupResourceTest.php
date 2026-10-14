<?php

namespace Tests\Unit\Backup;

use App\Http\Resources\BackupResource;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use Tests\TestCase;

class BackupResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_resource_includes_size_bytes_and_hides_credentials(): void
    {
        $container = new BackupContainer();
        $container->id = 5;
        $container->name = 's3-main';
        $container->driver = 's3';
        $container->location = 'my-bucket';
        $container->credentials = ['secret_access_key' => 'super-secret'];
        $container->created_at = now();
        $container->updated_at = now();

        $backup = new BackupRecord();
        $backup->id = 1;
        $backup->username = 'alice';
        $backup->container_id = 5;
        $backup->async_status = ['backup' => 'completed', 'source' => 'api'];
        $backup->error = null;
        $backup->created_at = now();
        $backup->updated_at = now();
        $backup->setRelation('container', $container);

        $itemOne = new BackupItem();
        $itemOne->id = 10;
        $itemOne->remote_path = 'alice/1/files.tar.gz';
        $itemOne->size_bytes = 1000;
        $itemOne->details = ['type' => 'files', 'name' => 'files', 'sha256' => str_repeat('a', 64)];

        $itemTwo = new BackupItem();
        $itemTwo->id = 11;
        $itemTwo->remote_path = 'alice/1/volume-dbdata.tar.gz';
        $itemTwo->size_bytes = 2500;
        $itemTwo->details = [
            'type' => 'volume',
            'name' => 'dbdata',
            'sha256' => str_repeat('b', 64),
            'docker_name' => 'proj_dbdata',
        ];

        $backup->setRelation('items', collect([$itemOne, $itemTwo]));

        $array = (new BackupResource($backup))->toArray(request());

        $this->assertSame(3500, $array['size_bytes']);
        $this->assertArrayHasKey('items', $array);
        $this->assertCount(2, $array['items']);
        $this->assertArrayHasKey('container', $array);
        $this->assertArrayNotHasKey('credentials', $array['container']);
        $this->assertStringNotContainsString('super-secret', json_encode($array));
    }
}
