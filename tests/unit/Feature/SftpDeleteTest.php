<?php

namespace Tests\Feature;

use Tests\Attributes\SetsCache;
use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\SftpTest::test_create_sftp_account
 * @depends Tests\Feature\UsersRebuildTest::test_rebuild_preserves_all_resources
 */
class SftpDeleteTest extends TestCase
{
    #[SetsCache('deleted_sftp_username')]
    #[UnsetsCache('sftp_account')]
    public function test_delete_sftp_account(): void
    {
        $username = $this->getCacheAsString('user.username');
        $sftpUsername = $this->getCacheAsString('sftp_account.username');
        $this->authenticate();

        // preserve the username for the follow-up list test
        $this->setCache('deleted_sftp_username', $sftpUsername);

        $response = $this->deleteJson("/api/users/{$username}/sftp-accounts/{$sftpUsername}");
        $this->assertContains($response->getStatusCode(), [200, 204]);

        $this->unsetCache('sftp_account');
    }

    public function test_sftp_account_removed_from_list(): void
    {
        $username = $this->getCacheAsString('user.username');
        /** @var string|null $sftpUsername */
        $sftpUsername = self::getFromCache('deleted_sftp_username');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/sftp-accounts");
        $response->assertStatus(200);

        if ($sftpUsername !== null) {
            $data = $response->json('data');
            assert(is_array($data));
            /** @var list<array<string, mixed>> $data */
            foreach ($data as $account) {
                if (isset($account['username'])) {
                    $this->assertNotEquals($sftpUsername, $account['username'],
                        "Deleted SFTP account '{$sftpUsername}' still appears in list"
                    );
                }
            }
        }
    }
}
