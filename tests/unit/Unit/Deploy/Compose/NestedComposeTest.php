<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\NestedCompose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A compose file under docker/ run from the project root keeps meaning what
 * it meant where it was written.
 */
class NestedComposeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function paths(): array
    {
        return [
            'the parent is the root' => ['..', '.'],
            'a sibling of the directory' => ['../app', './app'],
            'the directory itself' => ['.', './docker'],
            'inside the directory' => ['./data', './docker/data'],
            'bare relative' => ['conf/nginx.conf', './docker/conf/nginx.conf'],
            'absolute' => ['/path/to/config', '/path/to/config'],
            'home' => ['~/data', '~/data'],
            'interpolated' => ['${DATA_DIR}', '${DATA_DIR}'],
            'a git context' => ['https://github.com/acme/app.git', 'https://github.com/acme/app.git'],
            'above the root stays above it' => ['../../elsewhere', '../elsewhere'],
        ];
    }

    #[DataProvider('paths')]
    public function test_a_path_means_the_same_from_the_root(string $written, string $fromRoot): void
    {
        $this->assertSame($fromRoot, NestedCompose::path($written, 'docker'));
    }

    /** WikiDocs' docker/compose.yml, trimmed. */
    public function test_wikidocs_builds_from_the_root_with_its_own_dockerfile(): void
    {
        $rebased = NestedCompose::rebase([
            'services' => [
                'wikidocs' => [
                    'build' => ['context' => '..', 'dockerfile' => 'docker/dockerfile'],
                    'volumes' => ['datasets:/var/lib/wikidocs/datasets'],
                ],
            ],
            'volumes' => ['datasets' => null],
        ], 'docker');

        $this->assertSame(['context' => '.', 'dockerfile' => 'docker/dockerfile'], $rebased['services']['wikidocs']['build']);
        $this->assertSame(['datasets:/var/lib/wikidocs/datasets'], $rebased['services']['wikidocs']['volumes']);
    }

    public function test_binds_env_files_and_short_builds_move_with_the_file(): void
    {
        $rebased = NestedCompose::rebase([
            'services' => [
                'app' => [
                    'build' => '.',
                    'env_file' => ['.env', ['path' => 'extra.env', 'required' => false]],
                    'volumes' => [
                        './config:/config:ro',
                        '/var/run/docker.sock:/var/run/docker.sock',
                        ['type' => 'bind', 'source' => '../uploads', 'target' => '/uploads'],
                        ['type' => 'volume', 'source' => 'data', 'target' => '/data'],
                    ],
                ],
                'web' => ['image' => 'nginx', 'env_file' => '.env'],
            ],
            'configs' => ['nginx' => ['file' => './nginx.conf']],
        ], 'deploy');

        $app = $rebased['services']['app'];
        $this->assertSame('./deploy', $app['build']);
        $this->assertSame(['./deploy/.env', ['path' => './deploy/extra.env', 'required' => false]], $app['env_file']);
        $this->assertSame('./deploy/config:/config:ro', $app['volumes'][0]);
        $this->assertSame('/var/run/docker.sock:/var/run/docker.sock', $app['volumes'][1]);
        $this->assertSame('./uploads', $app['volumes'][2]['source']);
        $this->assertSame('data', $app['volumes'][3]['source']);
        $this->assertSame('./deploy/.env', $rebased['services']['web']['env_file']);
        $this->assertSame('./deploy/nginx.conf', $rebased['configs']['nginx']['file']);
    }

    public function test_a_root_file_has_no_nested_directory(): void
    {
        $this->assertNull(NestedCompose::relativeDir('/home/a/project/docker-compose.yml', '/home/a/project'));
        $this->assertNull(NestedCompose::relativeDir('/elsewhere/docker-compose.yml', '/home/a/project'));
        $this->assertSame('docker', NestedCompose::relativeDir('/home/a/project/docker/compose.yml', '/home/a/project/'));
    }
}
