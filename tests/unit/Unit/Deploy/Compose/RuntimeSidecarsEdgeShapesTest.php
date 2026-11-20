<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RuntimeSidecars;
use PHPUnit\Framework\TestCase;

/**
 * Compose shapes at the edges of what RuntimeSidecars keeps: mounts it drops or rewrites, a sidecar
 * renamed clear of `app` twice over, and the names the generated app may answer to.
 */
class RuntimeSidecarsEdgeShapesTest extends TestCase
{
    public function test_a_sidecar_whose_only_mount_is_installed_dependencies_loses_its_volumes(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  web:\n    build: .\n  redis:\n    image: redis:7\n    volumes:\n      - ./node_modules/cache:/cache\n"
        );

        $this->assertArrayNotHasKey('volumes', $result['services']['redis']);
        $this->assertSame(['redis: ./node_modules/cache:/cache'], $result['dropped_mounts']);
    }

    public function test_a_long_form_volume_of_a_datastore_is_kept_as_written(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  web:\n    build: .\n  db:\n    image: postgres:16\n    volumes:\n"
            ."      - type: volume\n        source: pgconf\n        target: /etc/postgresql\n"
            ."      - ./data:/var/lib/postgresql/data\nvolumes:\n  pgconf:\n"
        );

        $this->assertSame(
            [
                ['type' => 'volume', 'source' => 'pgconf', 'target' => '/etc/postgresql'],
                'db-data:/var/lib/postgresql/data',
            ],
            $result['services']['db']['volumes']
        );
        $this->assertSame(['db: ./data:/var/lib/postgresql/data'], $result['replaced_mounts']);
        $this->assertSame(['pgconf', 'db-data'], array_keys($result['volumes']));
    }

    public function test_a_sidecar_named_app_moves_past_a_name_already_taken(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  app:\n    image: redis:7\n  app-sidecar:\n    image: postgres:16\n"
        );

        $this->assertSame(['app-sidecar-2', 'app-sidecar'], array_keys($result['services']));
        $this->assertSame('redis:7', $result['services']['app-sidecar-2']['image']);
        $this->assertSame('postgres:16', $result['services']['app-sidecar']['image']);
    }

    public function test_a_kept_container_name_is_not_an_alias_of_the_app(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  api:\n    build: .\n    container_name: shop\n"
            ."  db:\n    image: postgres:16\n    container_name: shop\n"
            ."  redis:\n    image: redis:7\n    container_name: cache\n"
        );

        $this->assertSame(['db', 'redis'], array_keys($result['services']));
        $this->assertSame('shop', $result['services']['db']['container_name']);
        $this->assertSame(['api'], $result['app_aliases']);
    }

    public function test_an_imageless_service_running_the_checkout_is_dropped(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  tool:\n    volumes:\n      - .:/app\n  db:\n    image: postgres:16\n"
        );

        $this->assertSame(['db'], array_keys($result['services']));
    }

    public function test_only_declared_named_volumes_with_a_target_become_app_mounts(): void
    {
        $result = RuntimeSidecars::fromYaml(
            "services:\n  app:\n    build: .\n    image: acme/shop\n    volumes:\n"
            ."      - appdata\n      - appdata:/data\n      - .:/app\n  db:\n    image: postgres:16\nvolumes:\n  appdata:\n",
            false,
            null,
            'github.com/acme/shop'
        );

        $this->assertSame(['appdata:/data'], $result['app_mounts']);
        $this->assertSame(['db'], array_keys($result['services']));
    }
}
