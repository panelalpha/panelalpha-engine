<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\WordpressTest::test_install_wordress_on_main_domain
 */
class WpCliThemesTest extends TestCase
{
    private const THEME_SLUG = 'twentytwentythree';
    private const FALLBACK_THEME = 'twentytwentyfour';

    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    /** @return list<array<string, mixed>> */
    private function getThemeList(string $username, string $path): array
    {
        $response = $this->wpCli($username, ['theme', 'list', "--path={$path}", '--format=json']);
        /** @var list<array<string, mixed>> $themes */
        $themes = json_decode((string)($response->json('stdout') ?? '[]'), true) ?: [];
        return $themes;
    }

    public function test_install_theme(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['theme', 'install', self::THEME_SLUG, "--path={$path}"]);
        $this->assertContains($response->json('exit_code'), [0, 1],
            'Theme install failed: ' . (string)($response->json('stdout') ?? '')
        );
    }

    public function test_activate_theme(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['theme', 'activate', self::THEME_SLUG, "--path={$path}"]);
        $this->assertEquals(0, $response->json('exit_code'),
            'Theme activate failed: ' . (string)($response->json('stdout') ?? '')
        );

        $themes = $this->getThemeList($username, $path);
        $found = false;
        foreach ($themes as $theme) {
            if (isset($theme['name']) && $theme['name'] === self::THEME_SLUG) {
                $found = true;
                $this->assertEquals('active', $theme['status'] ?? '',
                    "Theme '" . self::THEME_SLUG . "' is not active"
                );
            }
        }
        $this->assertTrue($found, "Theme '" . self::THEME_SLUG . "' not found in theme list after activation");
    }

    public function test_delete_theme(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        // Switch to a different theme before deleting
        $this->wpCli($username, ['theme', 'activate', self::FALLBACK_THEME, "--path={$path}"]);

        $response = $this->wpCli($username, ['theme', 'delete', self::THEME_SLUG, "--path={$path}"]);
        $this->assertEquals(0, $response->json('exit_code'),
            'Theme delete failed: ' . (string)($response->json('stdout') ?? '')
        );

        $themes = $this->getThemeList($username, $path);
        foreach ($themes as $theme) {
            if (isset($theme['name'])) {
                $this->assertNotEquals(self::THEME_SLUG, $theme['name'],
                    "Deleted theme '" . self::THEME_SLUG . "' still appears in theme list"
                );
            }
        }
    }
}
