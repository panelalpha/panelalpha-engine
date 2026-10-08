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
class WpCliPostsTest extends TestCase
{
    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    #[SetsCache('wp_post')]
    public function test_create_wp_post(): void
    {
        $this->skipIfCached('wp_post');

        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $postTitle = 'Test Post ' . Str::random(6);

        $response = $this->wpCli($username, [
            'post', 'create',
            '--post_status=publish',
            "--post_title={$postTitle}",
            '--porcelain',
            "--path={$path}",
        ]);
        $this->assertEquals(0, $response->json('exit_code'),
            'WP post create failed: ' . (string)($response->json('stdout') ?? '')
        );

        $postId = trim((string)($response->json('stdout') ?? ''));
        $this->assertNotEmpty($postId);
        $this->assertIsNumeric($postId);

        $this->setCache('wp_post', [
            'id' => $postId,
            'title' => $postTitle,
        ]);
    }

    public function test_list_wp_posts(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $postId = $this->getCacheAsString('wp_post.id');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, ['post', 'list', '--format=json', "--path={$path}"]);
        $this->assertEquals(0, $response->json('exit_code'),
            'WP post list failed: ' . (string)($response->json('stdout') ?? '')
        );

        $posts = json_decode((string)($response->json('stdout') ?? '[]'), true);
        assert(is_array($posts));
        $found = false;
        foreach ($posts as $post) {
            assert(is_array($post));
            if (isset($post['ID']) && (string)$post['ID'] === $postId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "WP post ID '{$postId}' not found in post list");
    }
}
