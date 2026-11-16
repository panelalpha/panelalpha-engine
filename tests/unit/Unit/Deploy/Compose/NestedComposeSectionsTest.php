<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\NestedCompose;
use PHPUnit\Framework\TestCase;

class NestedComposeSectionsTest extends TestCase
{
    public function test_every_section_holding_a_path_is_rebased(): void
    {
        $compose = [
            'services' => [
                'app' => [
                    'build' => ['context' => '..', 'dockerfile' => 'Dockerfile', 'additional_contexts' => ['assets' => './assets', 'img' => 'docker-image://alpine', 'n' => 5]],
                    'extends' => ['file' => './base.yml', 'service' => 'base'],
                    'env_file' => ['./a.env', ['path' => './b.env', 'required' => false], 7],
                    'volumes' => ['./data:/data', 'named:/x', ['type' => 'bind', 'source' => './conf', 'target' => '/c'], ['type' => 'volume', 'source' => './v', 'target' => '/v']],
                ],
                'web' => ['build' => '.', 'env_file' => './web.env'],
                'raw' => 'not-a-service',
            ],
            'configs' => ['c1' => ['file' => './nginx.conf'], 'c2' => ['external' => true], 'c3' => 'x'],
            'secrets' => ['s1' => ['file' => '../secret.txt']],
            'include' => ['./inc.yml', ['path' => 'other/inc.yml', 'project_directory' => '.'], 5, ['nopath' => true]],
        ];

        $this->assertSame([
            'services' => [
                'app' => [
                    'build' => ['context' => '.', 'dockerfile' => 'Dockerfile', 'additional_contexts' => ['assets' => './docker/assets', 'img' => 'docker-image://alpine', 'n' => 5]],
                    'extends' => ['file' => './docker/base.yml', 'service' => 'base'],
                    'env_file' => ['./docker/a.env', ['path' => './docker/b.env', 'required' => false], 7],
                    'volumes' => ['./docker/data:/data', 'named:/x', ['type' => 'bind', 'source' => './docker/conf', 'target' => '/c'], ['type' => 'volume', 'source' => './v', 'target' => '/v']],
                ],
                'web' => ['build' => './docker', 'env_file' => './docker/web.env'],
                'raw' => 'not-a-service',
            ],
            'configs' => ['c1' => ['file' => './docker/nginx.conf'], 'c2' => ['external' => true], 'c3' => 'x'],
            'secrets' => ['s1' => ['file' => './secret.txt']],
            'include' => ['./docker/inc.yml', ['path' => './docker/other/inc.yml', 'project_directory' => '.'], 5, ['nopath' => true]],
        ], NestedCompose::rebase($compose, '/docker/'));
    }
}
