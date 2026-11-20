<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\SidecarDialects;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use PHPUnit\Framework\TestCase;

/**
 * SidecarEngine hands the dialect catalogue, image families and build ownership through from the classes that own them.
 */
class SidecarEngineFacadeTest extends TestCase
{
    public function test_the_dialect_catalogue_is_the_shipped_one_and_reloads_after_forget(): void
    {
        $dialects = SidecarEngine::dialects();

        $this->assertSame(SidecarDialects::all(), $dialects);
        $this->assertArrayHasKey('postgres', $dialects);

        SidecarEngine::forgetDialects();

        $this->assertSame($dialects, SidecarEngine::dialects());
    }

    public function test_an_alias_resolves_to_the_engine_it_names(): void
    {
        $this->assertSame('redis', SidecarEngine::canonical(' Valkey '));
        $this->assertSame('postgres', SidecarEngine::canonical('postgres'));
        $this->assertNull(SidecarEngine::canonical('  '));
    }

    public function test_an_image_family_drops_registry_owner_tag_and_digest(): void
    {
        $this->assertSame('postgres', SidecarEngine::imageFamily('docker.io/library/Postgres:16@sha256:abc'));
        $this->assertSame('redis', SidecarEngine::imageFamily('bitnami/redis:7.2'));
    }

    public function test_an_image_is_a_build_of_the_project_only_by_owner_and_name(): void
    {
        $this->assertTrue(SidecarEngine::imageIsBuildOf('ghcr.io/acme/shop:latest', 'github.com/acme/shop'));
        $this->assertFalse(SidecarEngine::imageIsBuildOf('ghcr.io/other/shop:latest', 'github.com/acme/shop'));
        $this->assertFalse(SidecarEngine::imageIsBuildOf('acme/shop', null));
    }
}
