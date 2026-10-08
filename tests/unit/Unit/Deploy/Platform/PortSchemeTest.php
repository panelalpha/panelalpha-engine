<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Models\User;
use Tests\TestCase;

/**
 * `port_scheme: https` lets a recipe say its app port speaks only TLS, so the
 * account's generated :80/:443 rules proxy to it over https.
 */
class PortSchemeTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function test_a_manifest_reads_port_scheme_and_refuses_anything_else(): void
    {
        $this->assertSame('https', $this->manifest(['port' => 8443, 'port_scheme' => 'https'])->portScheme);
        $this->assertNull($this->manifest(['port' => 8443])->portScheme);

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("'port_scheme' must be one of http, https");
        $this->manifest(['port_scheme' => 'tls']);
    }

    public function test_a_compose_recipe_declares_it_without_becoming_a_manifest(): void
    {
        $config = AppConfig::fromYaml("description: unifi\nport_scheme: https\ncompose: |\n  services: {}\n");

        $this->assertNotNull($config);
        $this->assertSame('https', $config->portScheme());
        $this->assertNull($config->manifest());
        $this->assertNull(AppConfig::fromYaml("description: plain\ncompose: |\n  services: {}\n")?->portScheme());
    }

    public function test_an_app_config_refuses_an_unknown_scheme(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("'port_scheme' must be one of http, https");
        AppConfig::fromYaml("port_scheme: HTTPS\n");
    }

    public function test_detection_carries_the_scheme_into_the_decision(): void
    {
        $this->dir = sys_get_temp_dir() . '/port-scheme-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/.panelalpha', 0777, true);
        file_put_contents($this->dir . '/Dockerfile', "FROM nginx:alpine\nEXPOSE 8443\n");
        file_put_contents($this->dir . '/.panelalpha/panelalpha.yaml', "extends: dockerfile\nport: 8443\nport_scheme: https\n");

        $decision = DetectProjectStrategy::detect($this->dir);

        $this->assertSame('https', $decision['port_scheme'] ?? null);
    }

    public function test_the_project_keeps_only_https_as_a_scheme(): void
    {
        $user = new User();
        $this->assertNull($user->getAppPortScheme());

        $user->setAppPortScheme('https');
        $this->assertSame('https', $user->getAppPortScheme());

        $user->setAppPortScheme('http');
        $this->assertNull($user->getAppPortScheme());
    }

    /** @param array<string, mixed> $keys */
    private function manifest(array $keys): PlatformManifest
    {
        return PlatformManifest::fromArray(['id' => 'sample', 'label' => 'Sample', 'priority' => 1] + $keys, '<test>', false);
    }
}
