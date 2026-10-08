<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class UserDomainTest extends TestCase
{
    #[SetsCache('domain')]
    public function test_create_domain(): void
    {
        $this->skipIfCached('domain');

        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $domainName = strtolower('example' . Str::random(5) . '.test');

        $domainPayload = [
            'domain' => $domainName,
            'aliases' => [
                'www.' . $domainName,
            ],
            'type' => 'addon',
            'no_ssl' => false
        ];

        $response = $this->postJson("/api/users/{$username}/domains", $domainPayload);
        $response->assertStatus(201);

        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('domain', $result);
    }

    public function test_main_domain_http_challenge(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));

        $domainName = $this->getCache('user.domain');
        assert(is_string($domainName));

        $this->domain_http_challenge($username, $domainName);
    }

    public function test_addon_domain_http_challenge(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));

        $domainName = $this->getCache('domain.domain');
        assert(is_string($domainName));

        $this->domain_http_challenge($username, $domainName);
    }

    public function test_alias_domain_http_challenge(): void
    {
        $username = $this->getCache('user.username');
        assert(is_string($username));

        $domainName = $this->getCache('user.domain');
        assert(is_string($domainName));

        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}");

        $aliases = $response->json('data.details.aliases');
        assert(is_array($aliases));
        $aliasToTest = null;
        foreach ($aliases as $alias) {
            assert(is_string($alias));
            if ($alias !== 'www.' . $domainName) {
                $aliasToTest = $alias;
                break;
            }
        }

        if ($aliasToTest === null) {
            $aliasToTest = strtolower('alias' . Str::random(5) . '.test');
        }
        $aliases[] = $aliasToTest;

        $payload = $response->json('data.details');
        assert(is_array($payload));
        $payload['aliases'] = $aliases;

        $response = $this->putJson("/api/users/{$username}/domains/{$domainName}", $payload);
        $response->assertStatus(200);

        $this->domain_http_challenge($username, $domainName);
    }

    private function domain_http_challenge(string $username, string $domainName): void
    {
        // dump($domainName);

        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}");

        $documentRoot = $response->json('data.details.document_root');
        assert(is_string($documentRoot));

        $aliases = $response->json('data.details.aliases');
        assert(is_array($aliases));
        foreach ($aliases as $alias) {
            assert(is_string($alias));
        }
        /** @var array<string> $aliases */

        $challengeString = Str::random(32);
        $path = $documentRoot . "/challenge.txt";
        $response = $this->putJson("/api/users/{$username}/files/put-contents", [
            'path' => $path,
            'contents' => $challengeString,
        ]);
        $url = "https://{$domainName}/challenge.txt";

        $ip = Setting::get('default_ipv4');
        assert(is_string($ip));
        // dump($ip);

        $resolveHosts = ["{$domainName}:443:{$ip}"];
        foreach ($aliases as $alias) {
            $resolveHosts[] = "{$alias}:443:{$ip}";
        }
        $httpClient = Http::withOptions([
            'verify' => false,
            'curl' => [
                CURLOPT_RESOLVE => $resolveHosts,
            ],
        ]);

        $response = $httpClient->get($url);
        // dump($response->body());
        $this->assertEquals($challengeString, $response->body(), "domain {$domainName} http challenge");

        $tryCount = 0;
        foreach ($aliases as $alias) {
            $url = "https://{$alias}/challenge.txt";
            $response = $httpClient->get($url);
            $tryCount++;
            if (
                $challengeString !== $response->body()
                && $tryCount < 3
            ) {
                // webserver may still be reloading
                sleep(3);
                continue;
            }

            $this->assertEquals($challengeString, $response->body(), "domain alias {$alias} http challenge");
        }
    }

    public function test_get_user_domains(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/domains");
        $response->assertStatus(200);
    }

    public function test_get_installed_ssl_certs(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/domains/installed-ssl-certs");
        $response->assertStatus(200);
    }

    public function test_show_domain(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/domains/" . $domainName);
        $response->assertStatus(200);
    }

    public function test_update_domain(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $updatePayload = ['document_root' => '/new/path', 'redirect_enabled' => false, 'force_https_redirect' => false];
        $response = $this->putJson("/api/users/{$username}/domains/" . $domainName, $updatePayload);
        $response->assertStatus(200);
    }
}
