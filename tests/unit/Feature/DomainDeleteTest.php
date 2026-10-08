<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 * @depends Tests\Feature\PhpVersionTest::test_set_php_version
 * @depends Tests\Feature\DomainsAliasTest::test_reject_duplicate_alias
 * @depends Tests\Feature\DomainsLogsTest::test_all_webservers_logs_not_fewer
 * @depends Tests\Feature\DomainsSslTest::test_reinstall_ssl_cert
 * @depends Tests\Feature\PhpValidationTest::test_rejects_empty_php_version
 */
class DomainDeleteTest extends TestCase
{
    #[UnsetsCache('domain')]
    public function test_delete_domain(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();
        $response = $this->deleteJson("/api/users/{$username}/domains/{$domainName}");
        $response->assertStatus(200);
        $this->unsetCache('domain');
    }
}
