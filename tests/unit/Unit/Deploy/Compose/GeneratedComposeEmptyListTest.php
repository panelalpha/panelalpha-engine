<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A service kept from a repository's compose reaches the generated file almost
 * verbatim, and an empty list is one of the things it may carry. A plain
 * `Yaml::dump()` writes an empty PHP array as `{}`, which Compose rejects with
 * `services.redis.entrypoint must be a array`, and the deploy fails on a file
 * the engine wrote itself. `[]` has to survive.
 */
class GeneratedComposeEmptyListTest extends TestCase
{
    /**
     * The generated file for a framework recipe whose decision keeps a sidecar
     * with an empty list, as a repository's own compose does.
     */
    private function generatedWithEmptyListSidecar(): string
    {
        return DeployCompose::framework([
            'runtime' => 'node',
            'strategy' => 'express',
            'image' => 'node:22-bookworm-slim',
            'env' => ['PORT' => '3000'],
            'sidecars' => [
                'redis' => ['image' => 'redis:7', 'entrypoint' => []],
            ],
        ], 3000);
    }

    public function test_an_empty_entrypoint_is_a_sequence(): void
    {
        $yaml = $this->generatedWithEmptyListSidecar();

        $this->assertStringContainsString('entrypoint: []', $yaml);
        $this->assertStringNotContainsString('entrypoint: {}', $yaml);
        $this->assertSame([], Yaml::parse($yaml)['services']['redis']['entrypoint']);
    }

    /** A kept sidecar's `db_data: {}` is a volume definition, which Compose rejects as `[]`. */
    public function test_an_empty_volume_definition_is_not_a_sequence(): void
    {
        $yaml = DeployCompose::framework([
            'runtime' => 'node',
            'strategy' => 'express',
            'image' => 'node:22-bookworm-slim',
            'env' => ['PORT' => '3000'],
            'sidecars' => [
                'db' => ['image' => 'postgres:16', 'volumes' => ['db_data:/var/lib/postgresql/data']],
            ],
            'volumes' => ['db_data' => []],
        ], 3000);

        $this->assertStringNotContainsString('db_data: []', $yaml);
        $this->assertArrayHasKey('db_data', Yaml::parse($yaml)['volumes']);
        $this->assertNull(Yaml::parse($yaml)['volumes']['db_data']);
    }
}
