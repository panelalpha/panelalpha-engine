<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class DomainsLogsTest extends TestCase
{
    public function test_get_domain_logs(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}/log-files");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        foreach ($data as $logEntry) {
            $this->assertIsArray($logEntry);
            $this->assertArrayHasKey('file', $logEntry);
            $this->assertIsString($logEntry['file']);
            $this->assertGreaterThan(0, strlen($logEntry['file']));
            $this->assertArrayHasKey('size', $logEntry);
            $this->assertIsNumeric($logEntry['size']);
        }
    }

    public function test_all_webservers_logs_not_fewer(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        $defaultResponse = $this->getJson("/api/users/{$username}/domains/{$domainName}/log-files");
        $defaultResponse->assertStatus(200);
        $defaultData = $defaultResponse->json('data');
        assert(is_array($defaultData));
        $defaultCount = count($defaultData);

        $allResponse = $this->getJson("/api/users/{$username}/domains/{$domainName}/log-files?all_webservers=1");
        $allResponse->assertStatus(200);
        $allData = $allResponse->json('data');
        assert(is_array($allData));
        $allCount = count($allData);

        $this->assertGreaterThanOrEqual($defaultCount, $allCount,
            'all_webservers=1 should return at least as many log files as the default query'
        );
    }
}
