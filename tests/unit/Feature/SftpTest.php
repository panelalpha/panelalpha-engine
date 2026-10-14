<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\Attributes\UpdatesCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class SftpTest extends TestCase
{
    public function test_list_sftp_accounts(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/sftp-accounts");
        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    #[SetsCache('sftp_account')]
    public function test_create_sftp_account(): void
    {
        $this->skipIfCached('sftp_account');

        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $sftpUsername = $username . '_sftp' . strtolower(Str::random(6));
        $sftpPassword = Str::random(16);

        $response = $this->postJson("/api/users/{$username}/sftp-accounts", [
            'username' => $sftpUsername,
            'auth_method' => 'password',
            'password' => $sftpPassword,
        ]);
        $response->assertStatus(201);

        $result = $response->json('data');
        assert(is_array($result));
        $result['_password'] = $sftpPassword;
        $this->setCache('sftp_account', $result);
    }

    public function test_sftp_account_in_list(): void
    {
        $username = $this->getCacheAsString('user.username');
        $sftpUsername = $this->getCacheAsString('sftp_account.username');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/sftp-accounts");
        $response->assertStatus(200);
        $data = $response->json('data');
        assert(is_array($data));
        /** @var list<array<string, mixed>> $data */

        $found = false;
        foreach ($data as $account) {
            if (isset($account['username']) && $account['username'] === $sftpUsername) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "SFTP account '{$sftpUsername}' not found in list");
    }

    public function test_sftp_connection(): void
    {
        $host = Setting::get('default_ipv4');
        assert(is_string($host));
        $port = 2222;
        $sftpUsername = $this->getCacheAsString('sftp_account.username');
        $sftpPassword = $this->getCacheAsString('sftp_account._password');

        if (!function_exists('ssh2_connect')) {
            $this->markTestSkipped('PHP ssh2 extension not available');
        }

        $conn = @ssh2_connect($host, $port);
        if ($conn === false) {
            $this->markTestSkipped("Could not connect to SSH at {$host}:{$port}");
        }

        $authResult = @ssh2_auth_password($conn, $sftpUsername, $sftpPassword);
        $this->assertTrue($authResult, "SFTP/SSH login failed for user '{$sftpUsername}'");
    }

    #[UpdatesCache('sftp_account._password')]
    public function test_update_sftp_account_password(): void
    {
        $username = $this->getCacheAsString('user.username');
        $sftpUsername = $this->getCacheAsString('sftp_account.username');
        $this->authenticate();

        $newPassword = Str::random(16);
        $response = $this->putJson("/api/users/{$username}/sftp-accounts/{$sftpUsername}", [
            'auth_method' => 'password',
            'password' => $newPassword,
        ]);
        $response->assertStatus(200);
        $this->setCache('sftp_account._password', $newPassword);
    }

    public function test_sftp_connection_with_new_password(): void
    {
        $host = Setting::get('default_ipv4');
        assert(is_string($host));
        $port = 2222;
        $sftpUsername = $this->getCacheAsString('sftp_account.username');
        $sftpPassword = $this->getCacheAsString('sftp_account._password');

        if (!function_exists('ssh2_connect')) {
            $this->markTestSkipped('PHP ssh2 extension not available');
        }

        $conn = @ssh2_connect($host, $port);
        if ($conn === false) {
            $this->markTestSkipped("Could not connect to SSH at {$host}:{$port}");
        }

        $authResult = @ssh2_auth_password($conn, $sftpUsername, $sftpPassword);
        $this->assertTrue($authResult, "SFTP/SSH login with updated password failed for '{$sftpUsername}'");
    }

    public function test_rejects_invalid_sftp_username(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();

        $response = $this->postJson("/api/users/{$username}/sftp-accounts", [
            'username' => 'invalid username!',
            'auth_method' => 'password',
            'password' => Str::random(16),
        ]);
        $this->assertContains($response->getStatusCode(), [400, 422]);
    }
}
