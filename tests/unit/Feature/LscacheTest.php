<?php

namespace Tests\Feature;

use App\System;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\WordpressTest::test_install_wordress_on_main_domain
 */
class LscacheTest extends TestCase
{
    public function test_lscache(): void
    {
        $currentWebserver = (new System())->webserver()->getCurrentWebserver();
        if (!in_array($currentWebserver, [
            'litespeed',
            'openlitespeed',
        ])) {
            $this->markTestSkipped('this test is for litespeed/openlitespeed webservers, current webserver: ' . $currentWebserver);
        }
        $this->authenticate();

        $username = $this->getCache('user.username');
        assert(is_string($username));

        $domainName = $this->getCache('user.domain');
        assert(is_string($domainName));

        $response = $this->get("/api/users/{$username}");
        $homeDir = $response->json('data.config.home_dir');
        assert(is_string($homeDir));

        $response = $this->get("/api/users/{$username}/domains/{$domainName}");
        $documentRoot = $response->json('data.details.document_root');
        assert(is_string($documentRoot));

        $response = $this->postJson("/api/users/{$username}/wp-cli/command", ["args" => [
            "core",
            "is-installed",
            "--path={$homeDir}/{$documentRoot}",
        ]]);
        $this->assertEquals(0, $response->json('exit_code'), "wordpress is installed on main domain ({$domainName})");

        $url = "https://{$domainName}/";

        Artisan::call('system:lscache --disable');
        sleep(1);

        $ip = Setting::get('default_ipv4');
        assert(is_string($ip));
        $resolveHosts = ["{$domainName}:443:{$ip}"];
        $httpClient = Http::withOptions([
            'verify' => false,
            'curl' => [
                CURLOPT_RESOLVE => $resolveHosts,
            ],
        ]);
        $response = $httpClient->get($url);
        $header = $response->header('x-liteSpeed-cache');

        $this->assertEmpty($header, "empty x-liteSpeed-cache header with lscache disabled");

        Artisan::call('system:lscache --enable');
        sleep(1);

        $response = $this->postJson("/api/users/{$username}/wp-cli/command", ["args" => [
            "plugin",
            "install",
            "litespeed-cache",
            "--activate",
            "--path={$homeDir}/{$documentRoot}",
        ]]);
        $this->assertEquals(0, $response->json('exit_code'), "litespeed cache plugin installed and activated");

        $response = $httpClient->get($url);
        $header = $response->header('x-liteSpeed-cache');

        $this->assertNotEmpty($header, "not empty x-liteSpeed-cache header with lscache enabled");

        $response = $httpClient->get($url);
        $header = $response->header('x-liteSpeed-cache');

        $this->assertEquals("hit", $header, "cache hit with lscache enabled and url called twice");
    }
}
