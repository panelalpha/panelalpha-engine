<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\WpCliPostsTest::test_list_wp_posts
 */
class WpCliPostsDeleteTest extends TestCase
{
    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    #[UnsetsCache('wp_post')]
    public function test_delete_wp_post(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $postId = $this->getCacheAsString('wp_post.id');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, [
            'post', 'delete',
            $postId,
            '--force',
            "--path={$path}",
        ]);
        $this->assertEquals(0, $response->json('exit_code'),
            'WP post delete failed: ' . (string)($response->json('stdout') ?? '')
        );

        $this->unsetCache('wp_post');
    }
}
