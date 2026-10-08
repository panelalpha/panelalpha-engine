<?php

namespace Tests\Feature;

use Tests\Attributes\UnsetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\CronJobTest::test_delete_cron_job
 * @depends Tests\Feature\DatabaseDeleteTest::test_delete_database
 * @depends Tests\Feature\FileTest::test_delete_file
 * @depends Tests\Feature\MysqlPrivilegesTest::test_delete_mysql_user_privileges
 * @depends Tests\Feature\MysqlUsersDeleteTest::test_delete_mysql_user
 * @depends Tests\Feature\DomainDeleteTest::test_delete_domain
 * @depends Tests\Feature\PhpVersionTest::test_set_php_version
 * @depends Tests\Feature\FtpDeleteTest::test_delete_ftp_account
 * @depends Tests\Feature\SftpDeleteTest::test_delete_sftp_account
 * @depends Tests\Feature\UsersAllTest::test_users_all_and_paginated_consistent
 * @depends Tests\Feature\UsersValidationTest::test_xss_payloads_not_stored
 * @depends Tests\Feature\UsersRebuildTest::test_rebuild_preserves_all_resources
 * @depends Tests\Feature\UsersUsageTest::test_storage_usage_structure
 * @depends Tests\Feature\DomainsAliasTest::test_reject_duplicate_alias
 * @depends Tests\Feature\DomainsLogsTest::test_all_webservers_logs_not_fewer
 * @depends Tests\Feature\DomainsSslTest::test_reinstall_ssl_cert
 * @depends Tests\Feature\DomainNormalizationTest::test_rejects_invalid_domain_formats
 * @depends Tests\Feature\MysqlValidationTest::test_rejects_duplicate_database_name
 * @depends Tests\Feature\PhpValidationTest::test_rejects_empty_php_version
 * @depends Tests\Feature\WpCliPluginsTest::test_delete_plugin
 * @depends Tests\Feature\WpCliThemesTest::test_delete_theme
 * @depends Tests\Feature\WpCliUsersDeleteTest::test_delete_wp_user
 * @depends Tests\Feature\WpCliPostsDeleteTest::test_delete_wp_post
 * @depends Tests\Feature\PermalinksTest::test_permalink_returns_http_200
 */
class UserDeleteTest extends TestCase
{
    /**
     * 
     */
    #[UnsetsCache('user')]
    public function test_delete_user(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->deleteJson("/api/users/{$username}");
        $response->assertStatus(200);

        $this->unsetCache('user');
    }
}
