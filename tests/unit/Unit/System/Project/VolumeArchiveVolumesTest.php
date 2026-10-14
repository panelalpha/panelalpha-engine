<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;

class VolumeArchiveVolumesTest extends TestCase
{
    public function test_a_mounted_volume_string_mount_is_kept(): void
    {
        $volumes = VolumeArchive::volumesFromCompose([
            'services' => [
                'db' => ['volumes' => ['dbdata:/var/lib/postgresql/data']],
            ],
            'volumes' => [
                'dbdata' => ['driver' => 'local'],
            ],
        ]);

        $this->assertSame([['name' => 'dbdata', 'docker_name' => 'dbdata']], $volumes);
    }

    public function test_a_mounted_volume_long_form_is_kept(): void
    {
        $volumes = VolumeArchive::volumesFromCompose([
            'services' => [
                'db' => [
                    'volumes' => [
                        ['type' => 'volume', 'source' => 'dbdata', 'target' => '/var/lib/mysql'],
                    ],
                ],
            ],
            'volumes' => [
                'dbdata' => null,
            ],
        ]);

        $this->assertSame([['name' => 'dbdata', 'docker_name' => 'dbdata']], $volumes);
    }

    public function test_bind_mounts_are_dropped(): void
    {
        $volumes = VolumeArchive::volumesFromCompose([
            'services' => [
                'app' => [
                    'volumes' => [
                        './src:/app/src',
                        '.:/app',
                        '/etc/localtime:/etc/localtime:ro',
                    ],
                ],
            ],
            'volumes' => [],
        ]);

        $this->assertSame([], $volumes);
    }

    public function test_declared_but_unmounted_volumes_are_dropped(): void
    {
        $volumes = VolumeArchive::volumesFromCompose([
            'services' => [
                'app' => ['image' => 'acme/app'],
            ],
            'volumes' => [
                'dbdata' => null,
                'esdata' => null,
            ],
        ]);

        $this->assertSame([], $volumes);
    }

    public function test_resolved_compose_volume_name_is_used_as_docker_name(): void
    {
        $volumes = VolumeArchive::volumesFromCompose([
            'services' => [
                'db' => ['volumes' => ['dbdata:/var/lib/mysql']],
            ],
            'volumes' => [
                'dbdata' => ['name' => 'project_dbdata'],
            ],
        ]);

        $this->assertSame([['name' => 'dbdata', 'docker_name' => 'project_dbdata']], $volumes);
    }
}
