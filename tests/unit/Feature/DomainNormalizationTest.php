<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class DomainNormalizationTest extends TestCase
{
    public function test_normalizes_www_prefix(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $baseName = strtolower('normtest' . Str::random(4) . '.test');
        $wwwName = 'www.' . $baseName;

        $response = $this->postJson("/api/users/{$username}/domains", [
            'domain' => $wwwName,
            'type' => 'addon',
        ]);

        // If accepted, the stored domain should be normalized (no leading www.)
        if ($response->getStatusCode() === 201) {
            $storedDomain = $response->json('data.domain');
            assert(is_string($storedDomain));
            $this->assertNotSame($wwwName, $storedDomain,
                "Domain was stored with www. prefix instead of being normalized"
            );
        } else {
            // Rejected — acceptable, www. prefix is invalid as primary domain
            $this->assertContains($response->getStatusCode(), [400, 422]);
        }
    }

    public function test_rejects_invalid_domain_formats(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $invalidDomains = [
            'notadomain',
            '-leading-dash.test',
            'trailing-dash-.test',
            'a..b.test',
            '',
            '   ',
        ];

        foreach ($invalidDomains as $domain) {
            $response = $this->postJson("/api/users/{$username}/domains", [
                'domain' => $domain,
                'type' => 'addon',
            ]);
            $this->assertContains(
                $response->getStatusCode(),
                [400, 422],
                "Expected 400/422 for invalid domain: '{$domain}'"
            );
        }
    }
}
