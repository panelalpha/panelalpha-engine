<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class UsersUsageTest extends TestCase
{
    private function getUsage(string $username): array
    {
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/usage");
        $response->assertStatus(200);
        $data = $response->json();
        assert(is_array($data));
        return $data;
    }

    public function test_usage_increments_after_addon_domain(): void
    {
        $username = $this->getCacheAsString('user.username');

        $before = $this->getUsage($username);
        /** @var int $beforeCount */
        $beforeCount = $before['addon_domains']['usage'] ?? 0;

        $this->authenticate();
        $domainName = strtolower('usage' . Str::random(5) . '.test');
        $this->postJson("/api/users/{$username}/domains", [
            'domain' => $domainName,
            'type' => 'addon',
        ]);

        $after = $this->getUsage($username);
        /** @var int $afterCount */
        $afterCount = $after['addon_domains']['usage'] ?? 0;

        $this->assertGreaterThan($beforeCount, $afterCount,
            'addon_domains.usage did not increment after creating an addon domain'
        );
    }

    public function test_usage_increments_after_ftp_account(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domain = $this->getCacheAsString('user.domain');

        $before = $this->getUsage($username);
        /** @var int $beforeCount */
        $beforeCount = $before['ftp_accounts']['usage'] ?? 0;

        $this->authenticate();
        $ftpUsername = 'ftpusage' . strtolower(Str::random(5));
        $this->postJson("/api/users/{$username}/ftp-accounts", [
            'user' => $ftpUsername,
            'password' => Str::random(16),
            'domain' => $domain,
        ]);
        $after = $this->getUsage($username);
        /** @var int $afterCount */
        $afterCount = $after['ftp_accounts']['usage'] ?? 0;

        $this->assertGreaterThan($beforeCount, $afterCount,
            'ftp_accounts.usage did not increment after creating an FTP account'
        );
    }

    public function test_usage_increments_after_sftp_account(): void
    {
        $username = $this->getCacheAsString('user.username');

        $before = $this->getUsage($username);
        /** @var int $beforeCount */
        $beforeCount = $before['sftp_accounts']['usage'] ?? 0;

        $this->authenticate();
        $sftpUsername = $username . '_sftpusage' . strtolower(Str::random(5));
        $this->postJson("/api/users/{$username}/sftp-accounts", [
            'username' => $sftpUsername,
            'auth_method' => 'password',
            'password' => Str::random(16),
        ]);

        $after = $this->getUsage($username);
        /** @var int $afterCount */
        $afterCount = $after['sftp_accounts']['usage'] ?? 0;

        $this->assertGreaterThan($beforeCount, $afterCount,
            'sftp_accounts.usage did not increment after creating an SFTP account'
        );
    }

    public function test_usage_increments_after_mysql_database(): void
    {
        $username = $this->getCacheAsString('user.username');

        $before = $this->getUsage($username);
        /** @var int $beforeCount */
        $beforeCount = $before['mysql_databases']['usage'] ?? 0;

        $this->authenticate();
        $this->postJson("/api/users/{$username}/mysql/databases", [
            'name' => 'usagedb' . strtolower(Str::random(5)),
        ]);

        $after = $this->getUsage($username);
        /** @var int $afterCount */
        $afterCount = $after['mysql_databases']['usage'] ?? 0;

        $this->assertGreaterThan($beforeCount, $afterCount,
            'mysql_databases.usage did not increment after creating a MySQL database'
        );
    }

    public function test_storage_usage_structure(): void
    {
        $username = $this->getCacheAsString('user.username');
        $data = $this->getUsage($username);

        $this->assertArrayHasKey('storage', $data);
        $storage = $data['storage'];
        $this->assertIsArray($storage);
        $this->assertArrayHasKey('usage', $storage);
        $this->assertGreaterThanOrEqual(0, $storage['usage'],
            'storage.usage should be a non-negative number'
        );
    }
}
