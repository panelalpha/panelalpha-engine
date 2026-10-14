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
class FtpTest extends TestCase
{
    public function test_list_ftp_accounts(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/ftp-accounts");
        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    #[SetsCache('ftp_account')]
    public function test_create_ftp_account(): void
    {
        $this->skipIfCached('ftp_account');

        $this->authenticate();

        $username = $this->getCacheAsString('user.username');
        $domain = $this->getCacheAsString('user.domain');

        $ftpUsername = 'ftp' . strtolower(Str::random(6));
        $ftpPassword = Str::random(16);

        $response = $this->postJson("/api/users/{$username}/ftp-accounts", [
            'user' => $ftpUsername,
            'domain' => $domain,
            'password' => $ftpPassword,
        ]);
        $response->assertStatus(201);

        $result = $response->json('data');
        assert(is_array($result));
        $result['_password'] = $ftpPassword;
        $this->setCache('ftp_account', $result);
    }

    public function test_ftp_account_in_list(): void
    {
        $username = $this->getCacheAsString('user.username');
        $ftpUsername = $this->getCacheAsString('ftp_account.user');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/ftp-accounts");
        $response->assertStatus(200);
        $data = $response->json('data');
        assert(is_array($data));
        /** @var list<array<string, mixed>> $data */

        $found = false;
        foreach ($data as $account) {
            if (isset($account['user']) && $account['user'] === $ftpUsername) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "FTP account '{$ftpUsername}' not found in list");
    }

    public function test_ftp_connection(): void
    {
        $ftpUsername = $this->getCacheAsString('ftp_account.user');
        $ftpPassword = $this->getCacheAsString('ftp_account._password');

        if (!function_exists('ftp_connect')) {
            $this->markTestSkipped('PHP FTP extension not available');
        }

        $host = Setting::get('default_ipv4');
        assert(is_string($host));
        $conn = @ftp_connect($host, 21, 10);
        if ($conn === false) {
            $this->markTestSkipped("Could not connect to FTP at {$host}:21");
        }

        $loginResult = @ftp_login($conn, $ftpUsername, $ftpPassword);
        ftp_close($conn);

        $this->assertTrue($loginResult, "FTP login failed for user '{$ftpUsername}'");
    }

    #[UpdatesCache('ftp_account._password')]
    public function test_update_ftp_account_password(): void
    {
        $username = $this->getCacheAsString('user.username');
        $ftpUsername = $this->getCacheAsString('ftp_account.user');
        $this->authenticate();

        $newPassword = Str::random(16);
        $response = $this->putJson("/api/users/{$username}/ftp-accounts/{$ftpUsername}", [
            'password' => $newPassword,
        ]);
        $response->assertStatus(200);
        $this->setCache('ftp_account._password', $newPassword);
    }

    public function test_ftp_connection_with_new_password(): void
    {
        $ftpUsername = $this->getCacheAsString('ftp_account.user');
        $ftpPassword = $this->getCacheAsString('ftp_account._password');

        if (!function_exists('ftp_connect')) {
            $this->markTestSkipped('PHP FTP extension not available');
        }

        $host = Setting::get('default_ipv4');
        assert(is_string($host));
        $conn = @ftp_connect($host, 21, 10);
        if ($conn === false) {
            $this->markTestSkipped("Could not connect to FTP at {$host}:21");
        }

        $loginResult = @ftp_login($conn, $ftpUsername, $ftpPassword);
        ftp_close($conn);

        $this->assertTrue($loginResult, "FTP login with updated password failed for '{$ftpUsername}'");
    }
}
