<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class WordpressTest extends TestCase
{
    #[SetsCache('wordpress_main_domain_mysql_db')]
    public function test_install_wordress_on_main_domain(): void
    {
        $this->authenticate();
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('user.domain');

        $path = "/home/{$username}/{$domainName}/public_html";

        $response = $this->postJson("/api/users/{$username}/wp-cli/command", ["args" => [
            "core",
            "is-installed",
            "--path={$path}",
        ]]);
        if ($response->json('exit_code') === 0) {
            return;
        }

        if (!$this->hasCache('wordpress_main_domain_mysql_db')) {
            $mysqlPassword = Str::random(16);
            $response = $this->postJson("/api/users/{$username}/mysql/users", [
                'name' => "main",
                'password' => $mysqlPassword,
            ]);
            $response->assertStatus(201);
            $mysqlUsername = $response->json('data.user');
            assert(is_string($mysqlUsername));

            $response = $this->postJson("/api/users/{$username}/mysql/databases", [
                'name' => "main",
            ]);
            $response->assertStatus(201);
            $mysqlDbName = $response->json('data.database');
            assert(is_string($mysqlDbName));

            $response = $this->putJson("/api/users/{$username}/mysql/privileges/" . $mysqlUsername . "/" . $mysqlDbName, [
                'privileges' => 'ALL PRIVILEGES',
            ]);
            $response->assertStatus(200);

            $response = $this->getJson("/api/users/{$username}/mysql/server-info");
            $response->assertStatus(200);
            $dbHost = $response->json('data.host');
            assert(is_string($dbHost));

            $this->setCache('wordpress_main_domain_mysql_db', [
                'host' => $dbHost,
                'name' => $mysqlDbName,
                'username' => $mysqlUsername,
                'password' => $mysqlPassword,
            ]);
        }

        $dbData = $this->getCache('wordpress_main_domain_mysql_db');
        assert(is_array($dbData));

        $response = $this->postJson("/api/users/{$username}/wp-cli/command", ["args" => [
            "core",
            "download",
            "--path={$path}",
        ]]);
        $this->assertEquals(0, $response->json('exit_code'), "download wordpress on main domain ({$domainName})");

        $response = $this->postJson("/api/users/{$username}/wp-cli/command", ["args" => [
            "config",
            "create",
            "--path={$path}",
            "--dbhost={$dbData['host']}",
            "--dbname={$dbData['name']}",
            "--dbuser={$dbData['username']}",
            "--dbpass={$dbData['password']}",
        ]]);
        $this->assertEquals(0, $response->json('exit_code'), "create wordpress config on main domain ({$domainName})");

        $response = $this->postJson("/api/users/{$username}/wp-cli/command", ["args" => [
            "core",
            "install",
            "--path={$path}",
            "--url=https://{$domainName}",
            "--title={$domainName}",
            "--admin_user=admin",
            "--admin_password=" . Str::random(16),
            "--admin_email=admin@{$domainName}",
            "--skip-email",
        ]]);
        $this->assertEquals(0, $response->json('exit_code'), "install wordpress on main domain ({$domainName})");
    }
}
