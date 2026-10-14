<?php

namespace Tests\Feature;

use Tests\Attributes\SetsCache;
use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\FtpTest::test_create_ftp_account
 * @depends Tests\Feature\UsersRebuildTest::test_rebuild_preserves_all_resources
 */
class FtpDeleteTest extends TestCase
{
    #[SetsCache('deleted_ftp_username')]
    #[UnsetsCache('ftp_account')]
    public function test_delete_ftp_account(): void
    {
        $username = $this->getCacheAsString('user.username');
        $ftpUsername = $this->getCacheAsString('ftp_account.user');
        $this->authenticate();

        // preserve the username for the follow-up list test
        $this->setCache('deleted_ftp_username', $ftpUsername);

        $response = $this->deleteJson("/api/users/{$username}/ftp-accounts/{$ftpUsername}");
        $this->assertContains($response->getStatusCode(), [200, 204]);

        $this->unsetCache('ftp_account');
    }

    public function test_ftp_account_removed_from_list(): void
    {
        $username = $this->getCacheAsString('user.username');
        /** @var string|null $ftpUsername */
        $ftpUsername = self::getFromCache('deleted_ftp_username');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/ftp-accounts");
        $response->assertStatus(200);

        if ($ftpUsername !== null) {
            $data = $response->json('data');
            assert(is_array($data));
            /** @var list<array<string, mixed>> $data */
            foreach ($data as $account) {
                if (isset($account['username'])) {
                    $this->assertNotEquals($ftpUsername, $account['username'],
                        "Deleted FTP account '{$ftpUsername}' still appears in list"
                    );
                }
            }
        }
    }
}
