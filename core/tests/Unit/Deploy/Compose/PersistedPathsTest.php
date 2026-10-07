<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The `persist-paths` setting: named volumes on the app service of every
 * compose file the engine generates, so uploads survive a rebuild.
 */
class PersistedPathsTest extends TestCase
{
    public function test_no_setting_leaves_the_file_byte_for_byte(): void
    {
        $yaml = DeployCompose::framework(['runtime' => 'node', 'strategy' => 'express'], 3000);

        $this->assertSame($yaml, GeneratedCompose::withPersistedPaths($yaml, []));
    }

    public function test_each_path_is_a_named_volume_over_the_project_bind(): void
    {
        $yaml = DeployCompose::framework(['runtime' => 'php', 'strategy' => 'laravel'], 8000);

        $compose = Yaml::parse(GeneratedCompose::withPersistedPaths($yaml, ['/app/storage', '/app/public/uploads']));

        $mounts = $compose['services']['app']['volumes'];
        $this->assertContains('data-app-storage:/app/storage', $mounts);
        $this->assertContains('data-app-public-uploads:/app/public/uploads', $mounts);
        $this->assertSame(['data-app-storage' => null, 'data-app-public-uploads' => null], $compose['volumes']);
    }

    public function test_a_path_already_mounted_is_left_alone(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 8080, ['dockerfile_volumes' => ['/data']]);

        $out = GeneratedCompose::withPersistedPaths($yaml, ['/data']);

        $this->assertSame($yaml, $out);
    }

    public function test_the_whole_app_bind_is_never_hidden_behind_a_volume(): void
    {
        $yaml = DeployCompose::framework(['runtime' => 'php', 'strategy' => 'laravel'], 8000);

        $this->assertSame($yaml, GeneratedCompose::withPersistedPaths($yaml, ['/app']));
    }

    public function test_a_long_form_mount_counts_as_taken(): void
    {
        $yaml = GeneratedCompose::render([
            'image' => 'node:22',
            'volumes' => [['type' => 'volume', 'source' => 'cache', 'target' => '/app/cache']],
        ], ['volumes' => ['cache' => null]]);

        $compose = Yaml::parse(GeneratedCompose::withPersistedPaths($yaml, ['/app/cache', '/app/uploads']));

        $this->assertCount(2, $compose['services']['app']['volumes']);
        $this->assertSame('data-app-uploads:/app/uploads', $compose['services']['app']['volumes'][1]);
        $this->assertSame(['cache' => null, 'data-app-uploads' => null], $compose['volumes']);
    }

    public function test_railpack_and_dockerfile_composes_get_them_too(): void
    {
        foreach ([DeployCompose::railpack('project-app', 3000), DeployCompose::dockerfile('Dockerfile', 8080)] as $yaml) {
            $compose = Yaml::parse(GeneratedCompose::withPersistedPaths($yaml, ['/app/uploads']));

            $this->assertSame(['data-app-uploads:/app/uploads'], $compose['services']['app']['volumes']);
        }
    }
}
