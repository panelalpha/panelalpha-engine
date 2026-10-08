<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\WordpressTest::test_install_wordress_on_main_domain
 */
class WpCliPluginsTest extends TestCase
{
    private const PLUGIN_SLUG = 'hello-dolly';

    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    /** @return list<array<string, mixed>> */
    private function getPluginList(string $username, string $path): array
    {
        $response = $this->wpCli($username, ['plugin', 'list', "--path={$path}", '--format=json']);
        /** @var list<array<string, mixed>> $plugins */
        $plugins = json_decode((string)($response->json('stdout') ?? '[]'), true) ?: [];
        return $plugins;
    }

    public function test_install_plugin(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['plugin', 'install', self::PLUGIN_SLUG, "--path={$path}"]);
        $this->assertContains($response->json('exit_code'), [0, 1],
            "Plugin install exited with unexpected code: " . (string)($response->json('stdout') ?? '')
        );
    }

    public function test_activate_plugin(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['plugin', 'activate', self::PLUGIN_SLUG, "--path={$path}"]);
        $this->assertEquals(0, $response->json('exit_code'),
            'Plugin activate failed: ' . (string)($response->json('stdout') ?? '')
        );

        $plugins = $this->getPluginList($username, $path);
        $found = false;
        foreach ($plugins as $plugin) {
            if (isset($plugin['name']) && $plugin['name'] === self::PLUGIN_SLUG) {
                $found = true;
                $this->assertEquals('active', $plugin['status'] ?? '',
                    "Plugin '" . self::PLUGIN_SLUG . "' is not active"
                );
            }
        }
        $this->assertTrue($found, "Plugin '" . self::PLUGIN_SLUG . "' not found in plugin list");
    }

    public function test_deactivate_plugin(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['plugin', 'deactivate', self::PLUGIN_SLUG, "--path={$path}"]);
        $this->assertEquals(0, $response->json('exit_code'),
            'Plugin deactivate failed: ' . (string)($response->json('stdout') ?? '')
        );

        $plugins = $this->getPluginList($username, $path);
        foreach ($plugins as $plugin) {
            if (isset($plugin['name']) && $plugin['name'] === self::PLUGIN_SLUG) {
                $this->assertNotEquals('active', $plugin['status'] ?? '',
                    "Plugin '" . self::PLUGIN_SLUG . "' is still active after deactivation"
                );
            }
        }
    }

    public function test_delete_plugin(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['plugin', 'delete', self::PLUGIN_SLUG, "--path={$path}"]);
        $this->assertEquals(0, $response->json('exit_code'),
            'Plugin delete failed: ' . (string)($response->json('stdout') ?? '')
        );

        $plugins = $this->getPluginList($username, $path);
        foreach ($plugins as $plugin) {
            if (isset($plugin['name'])) {
                $this->assertNotEquals(self::PLUGIN_SLUG, $plugin['name'],
                    "Deleted plugin '" . self::PLUGIN_SLUG . "' still appears in plugin list"
                );
            }
        }
    }
}
