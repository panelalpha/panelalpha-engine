<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use App\Models\Setting;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 * @depends Tests\Feature\WordpressTest::test_install_wordress_on_main_domain
 */
class PermalinksTest extends TestCase
{
    private function wpCli(string $username, array $args): \Illuminate\Testing\TestResponse
    {
        $this->authenticate();
        return $this->postJson("/api/users/{$username}/wp-cli/command", ['args' => $args]);
    }

    public function test_set_permalink_structure(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');
        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->wpCli($username, [
            'rewrite',
            'structure',
            '/%postname%/',
            "--path={$path}",
        ]);
        $this->assertEquals(
            0,
            $response->json('exit_code'),
            'Setting permalink structure failed: ' . (string)($response->json('stdout') ?? '')
        );
        $response = $this->wpCli($username, [
            "eval",
            "insert_with_markers(get_home_path() . '.htaccess', 'WordPress', (new WP_Rewrite)->mod_rewrite_rules());",
            "--path={$path}",
        ]);
    }

    public function test_permalink_returns_http_200(): void
    {
        $domainName = $this->getCacheAsString('user.domain');

        $ip = Setting::get('default_ipv4');
        if ($ip === null) {
            $this->markTestSkipped('No default_ipv4 setting configured');
        }

        $httpClient = Http::withOptions([
            'verify' => false,
            'curl' => [
                CURLOPT_RESOLVE => ["{$domainName}:443:{$ip}"],
            ],
        ]);

        $response = $httpClient->get("https://{$domainName}/sample-page/");
        $this->assertEquals(
            200,
            $response->status(),
            "Expected HTTP 200 for pretty permalink URL https://{$domainName}/sample-page/"
        );
    }
}
