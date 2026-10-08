<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\WordpressTest::test_install_wordress_on_main_domain
 */
class WpCliUsersTest extends TestCase
{
    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    #[SetsCache('wp_user')]
    public function test_create_wp_user(): void
    {
        $this->skipIfCached('wp_user');

        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $wpUsername = 'editor' . strtolower(Str::random(5));
        $wpEmail = $wpUsername . '@test.test';

        $response = $this->wpCli($username, [
            'user', 'create',
            $wpUsername,
            $wpEmail,
            '--role=editor',
            "--path={$path}",
        ]);

        $this->assertEquals(0, $response->json('exit_code'),
            'WP user create failed: ' . (string)($response->json('stdout') ?? '')
        );

        $this->setCache('wp_user', [
            'username' => $wpUsername,
            'email' => $wpEmail,
        ]);
    }

    public function test_list_wp_users(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $wpUsername = $this->getCacheAsString('wp_user.username');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['user', 'list', "--path={$path}", '--format=json']);
        $this->assertEquals(0, $response->json('exit_code'),
            'WP user list failed: ' . (string)($response->json('stdout') ?? '')
        );

        $users = json_decode((string)($response->json('stdout') ?? '[]'), true);
        assert(is_array($users));
        $found = false;
        foreach ($users as $user) {
            assert(is_array($user));
            if (isset($user['user_login']) && $user['user_login'] === $wpUsername) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "WP user '{$wpUsername}' not found in user list");
    }
}
