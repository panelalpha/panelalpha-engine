<?php

namespace Tests\Integration\Models;

use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The account's details read and written through its accessors: limits, home, repository, warnings, addresses. */
class UserAccessorsEdgesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    #[DataProvider('limits')]
    public function test_each_limit_is_stored_under_its_key_and_read_back(string $setter, string $getter, string $key, int|float $value): void
    {
        $user = new User(['username' => 'limits']);

        $user->{$setter}($value);

        $this->assertSame($value, $user->{$getter}());
        $this->assertSame($value, $user->getDetails()[$key]);

        $user->{$setter}(null);
        $this->assertNull($user->{$getter}());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: int|float}> */
    public static function limits(): iterable
    {
        yield 'disk' => ['setDiskSpaceLimit', 'getDiskSpaceLimit', 'disk_space_limit', 2048];
        yield 'cpu' => ['setCpuLimit', 'getCpuLimit', 'cpu_limit', 1.5];
        yield 'read' => ['setDeviceReadBps', 'getDeviceReadBps', 'device_read_bps', 1000];
        yield 'write' => ['setDeviceWriteBps', 'getDeviceWriteBps', 'device_write_bps', 2000];
        yield 'bandwidth' => ['setBandwidthLimit', 'getBandwidthLimit', 'bandwidth_limit', 300];
        yield 'mysql' => ['setMysqlDatabasesLimit', 'getMysqlDatabasesLimit', 'mysql_databases_limit', 4];
        yield 'ftp' => ['setFtpAccountsLimit', 'getFtpAccountsLimit', 'ftp_accounts_limit', 5];
        yield 'sftp' => ['setSftpAccountsLimit', 'getSftpAccountsLimit', 'sftp_accounts_limit', 6];
        yield 'addon domains' => ['setAddonDomainsLimit', 'getAddonDomainsLimit', 'addon_domains_limit', 7];
        yield 'subdomains' => ['setSubdomainsLimit', 'getSubdomainsLimit', 'subdomains_limit', 8];
        yield 'inodes' => ['setInodesLimit', 'getInodesLimit', 'inodes_limit', 90000];
        yield 'memory' => ['setMemoryLimit', 'getMemoryLimit', 'memory_limit', 512];
    }

    public function test_details_that_are_not_a_document_read_as_empty(): void
    {
        $user = new User();
        $user->setRawAttributes(['details' => '"just a string"']);

        $this->assertSame([], $user->getDetails());
    }

    public function test_details_given_as_json_text_are_stored_as_a_document(): void
    {
        $user = new User();
        $user->details = '{"template":"dind","git_repo":"https://github.com/acme/app.git"}';

        $this->assertSame('dind', $user->getTemplate());
        $this->assertSame('https://github.com/acme/app.git', $user->getGitRepoOrFail());
    }

    public function test_a_project_without_a_repository_says_so(): void
    {
        $user = new User(['username' => 'norepo']);

        $this->expectExceptionMessage("git_repo for user 'norepo' not found");

        $user->getGitRepoOrFail();
    }

    public function test_a_legacy_var_www_home_resolves_to_the_project_home(): void
    {
        $user = new User(['username' => 'legacyhome']);
        $user->setDetails(['home_dir' => '/var/www']);

        $this->assertSame($user->project()->homeDirPath(), $user->getHomeDir());
        $this->assertStringEndsWith('/legacyhome', $user->getHomeDir());
    }

    public function test_deployment_warnings_are_read_only_as_a_list(): void
    {
        $user = new User(['username' => 'warned']);
        $this->assertFalse($user->hasDeploymentWarnings());

        $user->setDetails(['deployment_warnings' => 'not a list']);
        $this->assertSame([], $user->getDeploymentWarnings());

        $user->setDetails(['deployment_warnings' => ['cron not installed']]);
        $this->assertSame(['cron not installed'], $user->getDeploymentWarnings());
        $this->assertTrue($user->hasDeploymentWarnings());
    }

    public function test_forgetting_a_site_git_path_it_does_not_have_changes_nothing(): void
    {
        $user = $this->makeUser('sitegitless', ['site_git' => []]);
        $before = $user->getDetails();

        $user->forgetSiteGit('/public_html');

        $this->assertSame($before, $user->refresh()->getDetails());
    }

    public function test_databases_and_domain_names_are_listed_with_aliases(): void
    {
        $user = $this->makeUser('lister');
        MysqlDatabase::create(['user_id' => $user->id, 'database' => 'lister_one']);
        $domain = $this->makeMainDomain($user, 'lister.example.test');
        $domain->setDetails(['aliases' => ['www.lister.example.test']]);
        $domain->save();

        $this->assertSame(['lister_one'], array_map(fn (MysqlDatabase $db): string => $db->database, $user->getMysqlDatabases()));
        $this->assertSame(['lister.example.test', 'www.lister.example.test'], $user->refresh()->listAllDomainNames());
    }

    public function test_the_default_addresses_come_from_the_settings(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::public(4), 'default_ipv6' => '2001:db8::4']);

        $this->assertSame(['ipv4' => [TestNetwork::public(4)], 'ipv6' => ['2001:db8::4']], User::defaultIpAddresses());
    }

    public function test_a_dedicated_address_of_each_family_is_taken_from_a_free_subnet(): void
    {
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::private(1), 'default_ipv6' => '2001:db8:5::1']);
        IpSubnet::create(['ip' => TestNetwork::private(0), 'mask' => 30, 'family' => 4, 'is_shared' => false]);
        IpSubnet::create(['ip' => '2001:db8:5::', 'mask' => 126, 'family' => 6, 'is_shared' => false]);
        $user = $this->makeUser('dedicated');

        $this->assertTrue($user->assignFreeDedicatedIpv4());
        $this->assertTrue($user->assignFreeDedicatedIpv6());

        $this->assertEqualsCanonicalizing(
            [TestNetwork::private(2), '2001:db8:5::2'],
            IpAssigned::query()->where('user_id', $user->id)->pluck('ip_address')->all(),
        );
    }
}
