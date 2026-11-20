<?php

namespace Tests\Integration\Models;

use App\Models\HubConnection;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Tunnel;
use App\Models\WordpressMigration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The small lookups and guards on the models, against the real schema. */
class ModelQueryHelpersTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Setting::flushCache();
        parent::tearDown();
    }

    public function test_the_hub_counts_as_inactive_when_its_table_cannot_be_read(): void
    {
        Schema::drop((new HubConnection())->getTable());

        $this->assertFalse(HubConnection::isActive());
    }

    public function test_a_subnet_skips_its_assigned_addresses_when_picking_a_free_one(): void
    {
        $subnet = IpSubnet::create(['ip' => TestNetwork::private(0), 'mask' => 30, 'family' => 4, 'is_shared' => false]);
        $user = $this->makeUser('ipowner');
        IpAssigned::create(['user_id' => $user->id, 'ip_subnet_id' => $subnet->id, 'ip_address' => TestNetwork::private(1)]);

        $this->assertSame(TestNetwork::private(3), $subnet->findFreeIp([TestNetwork::private(2)]));
    }

    public function test_a_full_subnet_has_no_free_address(): void
    {
        $subnet = IpSubnet::create(['ip' => TestNetwork::private(0), 'mask' => 31, 'family' => 4, 'is_shared' => false]);

        $this->assertNull($subnet->findFreeIp([TestNetwork::private(1)]));
    }

    public function test_a_setting_exists_only_once_stored(): void
    {
        Setting::set('cov_probe', 'on');
        Setting::flushCache();

        $this->assertTrue(Setting::exists('cov_probe'));
        $this->assertFalse(Setting::exists('cov_absent'));
    }

    public function test_a_since_that_is_not_after_the_epoch_means_no_bound(): void
    {
        $this->assertNull(Task::parseSince(-30));
        $this->assertSame(1700000000, Task::parseSince('1700000000')?->getTimestamp());
    }

    public function test_merging_no_details_writes_nothing(): void
    {
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default');

        $this->assertFalse($task->mergeDetails([]));
        $this->assertNull($task->refresh()->details);
    }

    public function test_an_unreadable_tunnel_lifetime_never_expires(): void
    {
        $tunnel = new Tunnel(['details' => ['expires_at' => 'not a date at all']]);

        $this->assertNull($tunnel->expiresAt());
        $this->assertFalse($tunnel->isExpired());
    }

    public function test_tunnel_presence_is_answered_per_domain_and_per_project(): void
    {
        $user = $this->makeUser('tunowner');
        $other = $this->makeUser('tunother');
        $domain = $this->makeMainDomain($user, 'tunowner.example.test');
        $quiet = $this->makeMainDomain($other, 'tunother.example.test');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $user->id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'app.tunowner.example.test']);

        $this->assertTrue(Tunnel::domainHasTunnels($domain));
        $this->assertFalse(Tunnel::domainHasTunnels($quiet));
        $this->assertTrue(Tunnel::projectHasTunnels($user));
        $this->assertFalse(Tunnel::projectHasTunnels($other));
        $this->assertTrue(Tunnel::projectHasCloudflareTunnels($user));
        $this->assertFalse(Tunnel::projectHasCloudflareTunnels($other));
    }

    public function test_a_finished_migration_asks_for_no_secret(): void
    {
        $migration = new WordpressMigration(['status' => WordpressMigration::STATUS_COMPLETED]);

        $this->assertNull($migration->missingSourceSecret());
    }

    public function test_marking_a_step_the_migration_does_not_have_is_refused(): void
    {
        $migration = new WordpressMigration(['steps' => [['name' => 'export', 'status' => 'pending']]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown WordPress migration step: import');

        $migration->markStepCompleted('import');
    }

    public function test_proxy_rule_scopes_select_by_owner_state_and_transport(): void
    {
        $this->assertSame(['owner_scope', 'system'], $this->firstWhere(ProxyRule::query()->systemOwned()));
        $this->assertSame(['owner_scope', 'user'], $this->firstWhere(ProxyRule::query()->userOwned()));
        $this->assertSame(['enabled', true], $this->firstWhere(ProxyRule::query()->enabled()));
        $this->assertSame(['transport', 'http'], $this->firstWhere(ProxyRule::query()->http()));
        $stream = ProxyRule::query()->stream()->getQuery()->wheres[0];
        $this->assertSame(['transport', ['tcp', 'udp']], [$stream['column'], $stream['values']]);
    }

    /** @return array{0: string, 1: mixed} */
    private function firstWhere(Builder $query): array
    {
        $where = $query->getQuery()->wheres[0];

        return [$where['column'], $where['value']];
    }
}
