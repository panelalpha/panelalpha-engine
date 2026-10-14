<?php

namespace Tests\Unit\Deploy\Platform;

use App\System\Project\Dind\AppHealth;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The health checks of a repository's own manifest with an `id:` are read from
 * that manifest: the id alone is registered nowhere, so its `check:` and
 * `check_skip:` were silently dropped.
 */
class RepositoryManifestHealthChecksTest extends TestCase
{
    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/pa-own-checks-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/project/.panelalpha', 0o777, true);
        file_put_contents($this->home . '/project/composer.json', '{"name":"acme/app","require":{"php":"^8.3"}}');
        file_put_contents($this->home . '/project/.panelalpha/panelalpha.yaml', <<<'YAML'
id: acme-app
extends: php
check: command/django-allowed-hosts
YAML);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->home));
        parent::tearDown();
    }

    public function test_the_repository_manifest_detection_chose_supplies_the_checks(): void
    {
        $manifest = $this->declared(['deploy_platform' => 'acme-app', 'home_dir' => $this->home]);

        $this->assertNotNull($manifest);
        $this->assertSame('acme-app', $manifest->id);
        $this->assertContains('command/django-allowed-hosts', $manifest->checks);
    }

    public function test_a_shipped_platform_is_still_the_registry_one(): void
    {
        $this->assertSame('php', $this->declared(['deploy_platform' => 'php', 'home_dir' => $this->home])?->id);
        $this->assertNull($this->declared(['deploy_platform' => 'other-app', 'home_dir' => $this->home]));
        $this->assertNull($this->declared(['deploy_platform' => 'acme-app']));
    }

    /** @param array<string, mixed> $details */
    private function declared(array $details): ?\App\Lib\Deploy\Platform\PlatformManifest
    {
        return (new ReflectionMethod(AppHealth::class, 'declaredManifest'))->invoke(null, $details);
    }
}
