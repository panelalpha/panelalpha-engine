<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class HttpAcmeChallengeTest extends TestCase
{
    public function test_create_and_serve_http_acme_challenge(): void
    {
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $token = 'acme' . Str::random(16);
        $content = $token . '.test-thumbprint';

        $response = $this->postJson("/api/domains/{$domainName}/http-acme-challenges", [
            'token' => $token,
            'content' => $content,
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('data.domain', $domainName);
        $response->assertJsonPath('data.token', $token);
        $response->assertJsonPath('data.content', $content);

        $list = $this->getJson("/api/domains/{$domainName}/http-acme-challenges");
        $list->assertStatus(200);
        $list->assertJsonFragment(['token' => $token, 'content' => $content]);

        $show = $this->getJson("/api/domains/{$domainName}/http-acme-challenges/{$token}");
        $show->assertStatus(200);
        $show->assertJsonPath('data.content', $content);

        $ip = Setting::get('default_ipv4');
        assert(is_string($ip));

        $httpClient = Http::withOptions([
            'curl' => [
                CURLOPT_RESOLVE => ["{$domainName}:80:{$ip}"],
            ],
        ]);

        $tryCount = 0;
        $body = '';
        do {
            $body = $httpClient->get("http://{$domainName}/.well-known/acme-challenge/{$token}")->body();
            $tryCount++;
            if ($body !== $content && $tryCount < 5) {
                sleep(2);
            }
        } while ($body !== $content && $tryCount < 5);

        $this->assertEquals($content, $body, 'HTTP-01 challenge should be served on port 80');
    }

    /**
     * @depends test_create_and_serve_http_acme_challenge
     */
    public function test_duplicate_token_conflict(): void
    {
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $list = $this->getJson("/api/domains/{$domainName}/http-acme-challenges");
        $challenges = $list->json('data.challenges');
        assert(is_array($challenges) && $challenges !== []);
        $token = $challenges[0]['token'] ?? null;
        assert(is_string($token));

        $response = $this->postJson("/api/domains/{$domainName}/http-acme-challenges", [
            'token' => $token,
            'content' => 'other-content',
        ]);
        $response->assertStatus(409);
    }

    /**
     * @depends test_create_and_serve_http_acme_challenge
     */
    public function test_second_token_and_alias_lookup(): void
    {
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $token2 = 'acme' . Str::random(16);
        $content2 = $token2 . '.second';

        $response = $this->postJson("/api/domains/{$domainName}/http-acme-challenges", [
            'token' => $token2,
            'content' => $content2,
        ]);
        $response->assertStatus(201);

        $alias = 'www.' . $domainName;
        $viaAlias = $this->postJson("/api/domains/{$alias}/http-acme-challenges", [
            'token' => 'acme' . Str::random(16),
            'content' => 'via-alias',
        ]);
        $viaAlias->assertStatus(201);
        $viaAlias->assertJsonPath('data.domain', $domainName);
    }

    /**
     * @depends test_second_token_and_alias_lookup
     */
    public function test_delete_http_acme_challenges(): void
    {
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $list = $this->getJson("/api/domains/{$domainName}/http-acme-challenges");
        $challenges = $list->json('data.challenges');
        assert(is_array($challenges) && $challenges !== []);
        $token = $challenges[0]['token'] ?? null;
        assert(is_string($token));

        $deleteOne = $this->deleteJson("/api/domains/{$domainName}/http-acme-challenges/{$token}");
        $deleteOne->assertStatus(204);

        $deleteAll = $this->deleteJson("/api/domains/{$domainName}/http-acme-challenges");
        $deleteAll->assertStatus(204);

        $empty = $this->getJson("/api/domains/{$domainName}/http-acme-challenges");
        $empty->assertStatus(200);
        $empty->assertJsonPath('data.challenges', []);

        $ip = Setting::get('default_ipv4');
        assert(is_string($ip));
        $httpClient = Http::withOptions([
            'curl' => [
                CURLOPT_RESOLVE => ["{$domainName}:80:{$ip}"],
            ],
            'http_errors' => false,
        ]);
        sleep(2);
        $response = $httpClient->get("http://{$domainName}/.well-known/acme-challenge/{$token}");
        $this->assertNotEquals(200, $response->status(), 'Challenge URL should not return 200 after cleanup');
    }
}
