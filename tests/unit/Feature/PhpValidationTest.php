<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class PhpValidationTest extends TestCase
{
    public function test_rejects_invalid_php_version(): void
    {
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $response = $this->putJson("/api/domains/{$domainName}/php-version", [
            'version' => 'php99.9',
        ]);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    public function test_rejects_empty_php_version(): void
    {
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $response = $this->putJson("/api/domains/{$domainName}/php-version", [
            'version' => '',
        ]);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }
}
