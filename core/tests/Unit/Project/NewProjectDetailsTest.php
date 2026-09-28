<?php

namespace Tests\Unit\Project;

use App\Lib\Host\ProjectMemory;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\NewProjectDetails;
use PHPUnit\Framework\TestCase;

class NewProjectDetailsTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function build(array $overrides = [], array $domain = [], array $env = []): array
    {
        return NewProjectDetails::build(
            array_merge(['username' => 'alice'], $overrides),
            $domain,
            $env
        );
    }

    public function test_the_home_directory_and_mysql_prefix_come_from_the_username(): void
    {
        $details = $this->build();

        $this->assertSame('/home/alice', $details['home_dir']);
        $this->assertSame('alice_', $details['mysql_prefix']);
    }

    public function test_every_resource_limit_is_present_even_when_unset(): void
    {
        $details = $this->build();

        foreach (ResourceLimit::keys() as $key) {
            $this->assertArrayHasKey($key, $details, "{$key} must be initialised");
        }
    }

    /**
     * The asymmetry that already exists on disk: an unset disk limit is stored
     * as -1, every other unset limit as null -- except memory, which every
     * project has and which falls back to the host default (#294).
     */
    public function test_an_unset_disk_limit_is_minus_one_and_the_rest_are_null(): void
    {
        $details = $this->build();

        $this->assertSame(-1, $details['disk_space_limit']);
        $this->assertSame(ProjectMemory::defaultMb(), $details['memory_limit']);
        foreach (ResourceLimit::keys() as $key) {
            if ($key === 'disk_space_limit' || $key === 'memory_limit') {
                continue;
            }
            $this->assertNull($details[$key], "{$key} should default to null");
        }
    }

    public function test_supplied_limits_are_kept(): void
    {
        $details = $this->build([
            'disk_space_limit' => 1024,
            'memory_limit' => 512,
            'cpu_limit' => 1.5,
            'inodes_limit' => 900,
        ]);

        $this->assertSame(1024, $details['disk_space_limit']);
        $this->assertSame(512, $details['memory_limit']);
        $this->assertSame(1.5, $details['cpu_limit']);
        $this->assertSame(900, $details['inodes_limit']);
    }

    public function test_the_dedicated_ip_flags_are_always_booleans(): void
    {
        $off = $this->build();
        $this->assertFalse($off['dedicated_ipv4']);
        $this->assertFalse($off['dedicated_ipv6']);

        $on = $this->build(['dedicated_ipv4' => true, 'dedicated_ipv6' => 1]);
        $this->assertTrue($on['dedicated_ipv4']);
        $this->assertTrue($on['dedicated_ipv6']);
    }

    public function test_source_and_settings_keys_default_to_null(): void
    {
        $details = $this->build();

        foreach (
            ['php_fpm_pool_settings', 'lsphp_settings', 'redis_config',
            'template', 'git_repo', 'git_branch', 'git_token'] as $key
        ) {
            $this->assertArrayHasKey($key, $details);
            $this->assertNull($details[$key]);
        }
    }

    public function test_the_allocator_result_and_env_vars_are_carried_through(): void
    {
        $details = $this->build(
            [],
            ['source' => 'panelalpha', 'tls' => 'edge'],
            ['APP_ENV' => 'production']
        );

        $this->assertSame(['source' => 'panelalpha', 'tls' => 'edge'], $details['domain']);
        $this->assertSame(['APP_ENV' => 'production'], $details['env_vars']);
    }

    /** The stored shape is what already exists on disk; keep it byte for byte. */
    public function test_the_key_order_matches_what_the_controller_wrote(): void
    {
        $this->assertSame([
            'home_dir',
            'mysql_prefix',
            'disk_space_limit',
            'memory_limit',
            'cpu_limit',
            'device_read_bps',
            'device_write_bps',
            'bandwidth_limit',
            'mysql_databases_limit',
            'ftp_accounts_limit',
            'sftp_accounts_limit',
            'addon_domains_limit',
            'subdomains_limit',
            'inodes_limit',
            'php_fpm_pool_settings',
            'lsphp_settings',
            'redis_config',
            'dedicated_ipv4',
            'dedicated_ipv6',
            'template',
            'git_repo',
            'git_branch',
            'git_token',
            'env_vars',
            'domain',
        ], array_keys($this->build()));
    }

    public function test_a_copy_carries_the_limits_over_from_the_source(): void
    {
        $copy = NewProjectDetails::forCopy('bob', [
            'disk_space_limit' => 1024,
            'memory_limit' => 512,
            'inodes_limit' => 900,
        ]);

        $this->assertSame(1024, $copy['disk_space_limit']);
        $this->assertSame(512, $copy['memory_limit']);
        $this->assertSame(900, $copy['inodes_limit']);
        $this->assertSame('/home/bob', $copy['home_dir']);
        $this->assertSame('bob_', $copy['mysql_prefix']);
    }

    /** A copy gets its own dedicated address or none; it never inherits one. */
    public function test_a_copy_never_inherits_a_dedicated_address(): void
    {
        $copy = NewProjectDetails::forCopy('bob', [
            'dedicated_ipv4' => true,
            'dedicated_ipv6' => true,
        ]);

        $this->assertFalse($copy['dedicated_ipv4']);
        $this->assertFalse($copy['dedicated_ipv6']);
    }

    /**
     * The branch and token reach a copy through copiedDeploySnapshot(), and
     * env_vars and the allocator result do not apply at all.
     */
    public function test_a_copy_carries_neither_credentials_nor_request_only_keys(): void
    {
        $copy = NewProjectDetails::forCopy('bob', [
            'git_token' => 'pat-secret',
            'git_branch' => 'main',
            'env_vars' => ['A' => 'b'],
            'domain' => ['source' => 'panelalpha'],
        ]);

        foreach (['git_token', 'git_branch', 'env_vars', 'domain'] as $key) {
            $this->assertArrayNotHasKey($key, $copy, "{$key} must not be carried by forCopy()");
        }
    }

    public function test_a_copy_keeps_the_key_order_the_model_wrote(): void
    {
        $this->assertSame([
            'home_dir',
            'mysql_prefix',
            'disk_space_limit',
            'memory_limit',
            'cpu_limit',
            'device_read_bps',
            'device_write_bps',
            'bandwidth_limit',
            'mysql_databases_limit',
            'ftp_accounts_limit',
            'sftp_accounts_limit',
            'addon_domains_limit',
            'subdomains_limit',
            'inodes_limit',
            'php_fpm_pool_settings',
            'lsphp_settings',
            'redis_config',
            'dedicated_ipv4',
            'dedicated_ipv6',
            'template',
            'git_repo',
            'app_port',
        ], array_keys(NewProjectDetails::forCopy('bob', [])));
    }
}
