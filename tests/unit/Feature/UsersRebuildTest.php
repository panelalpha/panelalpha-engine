<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\FtpTest::test_create_ftp_account
 * @depends Tests\Feature\SftpTest::test_create_sftp_account
 * @depends Tests\Feature\DatabaseTest::test_create_database
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class UsersRebuildTest extends TestCase
{
    public function test_rebuild_preserves_all_resources(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        // Record current resource lists before rebuild
        $ftpResponse = $this->getJson("/api/users/{$username}/ftp-accounts");
        $ftpResponse->assertStatus(200);
        /** @var list<array<string, mixed>> $ftpBefore */
        $ftpBefore = (array)($ftpResponse->json('data') ?? []);

        $sftpResponse = $this->getJson("/api/users/{$username}/sftp-accounts");
        $sftpResponse->assertStatus(200);
        /** @var list<array<string, mixed>> $sftpBefore */
        $sftpBefore = (array)($sftpResponse->json('data') ?? []);

        $dbResponse = $this->getJson("/api/users/{$username}/mysql/databases");
        $dbResponse->assertStatus(200);
        /** @var list<array<string, mixed>> $dbBefore */
        $dbBefore = (array)($dbResponse->json('data') ?? []);

        $domainResponse = $this->getJson("/api/users/{$username}/domains");
        $domainResponse->assertStatus(200);
        /** @var list<array<string, mixed>> $domainsBefore */
        $domainsBefore = (array)($domainResponse->json('data') ?? []);

        // Perform rebuild
        $rebuildResponse = $this->postJson("/api/users/{$username}/rebuild");
        $rebuildResponse->assertStatus(202);
        // The suite's queue is sync, so the job has run by the time the 202 is back.
        $this->assertSame('completed', $this->getJson('/api/tasks/' . $rebuildResponse->json('data.id'))->json('data.status'));

        // Verify nothing was lost after rebuild
        $this->authenticate();

        /** @var list<array<string, mixed>> $ftpAfter */
        $ftpAfter = (array)($this->getJson("/api/users/{$username}/ftp-accounts")->json('data') ?? []);
        /** @var list<array<string, mixed>> $sftpAfter */
        $sftpAfter = (array)($this->getJson("/api/users/{$username}/sftp-accounts")->json('data') ?? []);
        /** @var list<array<string, mixed>> $dbAfter */
        $dbAfter = (array)($this->getJson("/api/users/{$username}/mysql/databases")->json('data') ?? []);
        /** @var list<array<string, mixed>> $domainsAfter */
        $domainsAfter = (array)($this->getJson("/api/users/{$username}/domains")->json('data') ?? []);

        $this->assertCount(count($ftpBefore), $ftpAfter, 'FTP accounts count changed after rebuild');
        $this->assertCount(count($sftpBefore), $sftpAfter, 'SFTP accounts count changed after rebuild');
        $this->assertCount(count($dbBefore), $dbAfter, 'MySQL databases count changed after rebuild');
        $this->assertCount(count($domainsBefore), $domainsAfter, 'Domains count changed after rebuild');

        // Verify each original FTP account still present by username
        foreach ($ftpBefore as $ftpAccount) {
            if (!isset($ftpAccount['username'])) {
                continue;
            }
            $ftpUser = (string)$ftpAccount['username'];
            $found = false;
            foreach ($ftpAfter as $a) {
                if (isset($a['username']) && $a['username'] === $ftpUser) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "FTP account '{$ftpUser}' missing after rebuild");
        }

        // Verify each original SFTP account still present
        foreach ($sftpBefore as $sftpAccount) {
            if (!isset($sftpAccount['username'])) {
                continue;
            }
            $sftpUser = (string)$sftpAccount['username'];
            $found = false;
            foreach ($sftpAfter as $a) {
                if (isset($a['username']) && $a['username'] === $sftpUser) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "SFTP account '{$sftpUser}' missing after rebuild");
        }

        // Verify each original database still present
        foreach ($dbBefore as $db) {
            if (isset($db['database']) && is_string($db['database'])) {
                $dbKey = $db['database'];
            } elseif (isset($db['name']) && is_string($db['name'])) {
                $dbKey = $db['name'];
            } else {
                continue;
            }
            $found = false;
            foreach ($dbAfter as $d) {
                $dKey = null;
                if (isset($d['database']) && is_string($d['database'])) {
                    $dKey = $d['database'];
                } elseif (isset($d['name']) && is_string($d['name'])) {
                    $dKey = $d['name'];
                }
                if ($dKey === $dbKey) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "MySQL database '{$dbKey}' missing after rebuild");
        }

        // Verify each original domain still present
        foreach ($domainsBefore as $domain) {
            if (!isset($domain['domain']) || !is_string($domain['domain'])) {
                continue;
            }
            $domainKey = $domain['domain'];
            $found = false;
            foreach ($domainsAfter as $d) {
                if (isset($d['domain']) && $d['domain'] === $domainKey) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Domain '{$domainKey}' missing after rebuild");
        }
    }
}
