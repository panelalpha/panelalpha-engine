<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\WpCliUsersTest::test_list_wp_users
 */
class WpCliUsersDeleteTest extends TestCase
{
    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    #[UnsetsCache('wp_user')]
    public function test_delete_wp_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $wpUsername = $this->getCacheAsString('wp_user.username');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, [
            'user', 'delete',
            $wpUsername,
            '--yes',
            "--path={$path}",
        ]);
        $this->assertEquals(0, $response->json('exit_code'),
            'WP user delete failed: ' . (string)($response->json('stdout') ?? '')
        );

        $this->unsetCache('wp_user');
    }
}
