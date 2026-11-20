<?php

namespace Tests\Integration\Console;

use App\Console\Commands\System\GuardHostDisk;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Net\Outbound\OutboundLimits;
use App\Lib\Net\Outbound\OutboundStatus;
use App\Models\Ipv4NatMap;
use App\Models\OutboundEvent;
use App\Models\Setting;
use App\System;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `metrics:prune`, `system:egress:*`, `system:disk:guard` and `system:nat:build` on the paths a normal run does not
 * take: limits turned off, a broker that does not answer, a host that cannot be read.
 */
class SystemReportCommandsTest extends TestCase
{
    use InMemoryDatabase;

    /** @var array<string, string|false> each variable a test set, with the value it had */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1800000060));
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $previous) {
            putenv($previous === false ? $name : "{$name}={$previous}");
        }
        Carbon::setTestNow();
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    private function env(string $name, string $value): void
    {
        $this->env[$name] ??= getenv($name);
        putenv("{$name}={$value}");
    }

    /** @return array{int, string} */
    private function run_(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    private function metric(string $at): void
    {
        DB::table('server_metrics')->insert(array_fill_keys([
            'cpu_percent', 'cpu_load_avg_1', 'cpu_load_avg_5', 'cpu_load_avg_15', 'ram_percent', 'swap_percent',
            'disk_read_bps', 'disk_write_bps', 'disk_read_iops', 'disk_write_iops', 'net_in_bps', 'net_out_bps', 'net_in_pps', 'net_out_pps',
        ], 0) + ['timestamp' => $at]);
    }

    public function test_metrics_limits_set_to_zero_are_skipped(): void
    {
        $this->env('METRICS_LIMIT_DAYS', '0');
        $this->env('METRICS_LIMIT_ENTRIES', '0');
        $this->metric('2000-01-01 00:00:00');

        [$code, $out] = $this->run_('metrics:prune');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("METRICS_LIMIT_DAYS not set, skipping days limit pruning.\nMETRICS_LIMIT_ENTRIES not set, skipping record limit pruning.\n", $out);
        $this->assertSame(1, DB::table('server_metrics')->count());
    }

    public function test_metrics_over_the_entry_limit_lose_their_oldest_rows(): void
    {
        $this->env('METRICS_LIMIT_DAYS', '0');
        $this->env('METRICS_LIMIT_ENTRIES', '2');
        foreach (['2027-01-15 08:00:00', '2027-01-15 08:01:00', '2027-01-15 08:02:00', '2027-01-15 08:03:00'] as $at) {
            $this->metric($at);
        }

        [$code, $out] = $this->run_('metrics:prune');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deleted 1 oldest records to keep max 2 entries.', $out);
        $this->assertSame(['2027-01-15 08:01:00', '2027-01-15 08:02:00', '2027-01-15 08:03:00'], DB::table('server_metrics')->orderBy('timestamp')->pluck('timestamp')->all());
    }

    public function test_metrics_exactly_at_the_entry_limit_have_no_cutoff(): void
    {
        $this->env('METRICS_LIMIT_DAYS', '0');
        $this->env('METRICS_LIMIT_ENTRIES', '2');
        $this->metric('2027-01-15 08:00:00');
        $this->metric('2027-01-15 08:01:00');

        [$code, $out] = $this->run_('metrics:prune');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No cutoff timestamp found, skipping record limit pruning.', $out);
        $this->assertSame(2, DB::table('server_metrics')->count());
    }

    private function outbound(?\Throwable $failure = null, string $report = '{"active": true, "settings": {}, "blocks": [], "marks": []}'): void
    {
        $this->app->instance(OutboundStatus::class, new OutboundStatus(new OutboundLimits(static function () use ($failure, $report): string {
            if ($failure !== null) {
                throw $failure;
            }

            return $report;
        })));
    }

    public function test_egress_commands_say_when_the_broker_does_not_answer(): void
    {
        $this->outbound(new \RuntimeException('pa-root: no such action'));

        $this->assertSame([1, "Could not read the outbound limits: pa-root: no such action\n"], $this->run_('system:egress:list'));
        $this->assertSame([1, "Could not read the outbound limits: pa-root: no such action\n"], $this->run_('system:egress:sync'));
        $this->assertSame([1, "Could not lift the block: pa-root: no such action\n"], $this->run_('system:egress:unblock', ['owner' => 'shop']));
    }

    public function test_egress_list_prints_json_with_the_events_it_names(): void
    {
        $this->outbound();
        OutboundEvent::query()->create([
            'owner' => 'shop', 'network' => OutboundEvent::NETWORK_TENANTS, 'kind' => OutboundEvent::KIND_SMTP,
            'address' => TestNetwork::private(5), 'started_at' => Carbon::now()->subMinutes(5), 'last_seen_at' => Carbon::now()->subMinute(),
        ]);

        [$code, $out] = $this->run_('system:egress:list', ['--json' => true]);

        $this->assertSame(0, $code);
        $json = json_decode($out, true);
        $this->assertTrue($json['active']);
        $this->assertSame([], $json['settings']);
        $this->assertCount(1, $json['events']);
        $this->assertSame('shop', $json['events'][0]['owner']);
    }

    public function test_egress_list_names_limits_turned_off_and_an_ended_block(): void
    {
        $this->outbound(null, '{"active": true, "settings": {"EGRESS_NEW_CONNECTIONS_PER_SECOND": 0, "EGRESS_NEW_DESTINATIONS_BURST": 0,'
            .' "EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE": 0}, "blocks": [], "marks": []}');
        OutboundEvent::query()->create([
            'owner' => 'shop', 'network' => OutboundEvent::NETWORK_TENANTS, 'kind' => OutboundEvent::KIND_SCAN,
            'address' => TestNetwork::private(5), 'started_at' => Carbon::now()->subHours(2),
            'last_seen_at' => Carbon::now()->subHours(2), 'until_at' => Carbon::now()->subHour(),
        ]);

        [$code, $out] = $this->run_('system:egress:list', ['--days' => '1']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('SMTP to other servers blocked, no limit on new connections, no scan limit.', $out);
        $this->assertMatchesRegularExpression('/\| shop\s+\| tenants \| scan, blocked \|.*\| ended \|/', $out);
        $this->assertStringNotContainsString('Lift a block early', $out);
    }

    public function test_the_disk_guard_does_nothing_when_free_space_cannot_be_read(): void
    {
        config(['deploy.host_build_cache_max' => 'off', 'deploy.disk_pressure_free' => '15%']);
        $this->app->instance(System::class, $this->diskHost(prune: null, df: 'df: cannot read'));

        $this->assertSame([0, "Could not read free space on the host.\n"], $this->run_('system:disk:guard'));
    }

    public function test_a_build_cache_prune_that_fails_is_a_warning_and_the_guard_goes_on(): void
    {
        config(['deploy.host_build_cache_max' => '10G', 'deploy.disk_pressure_free' => '15%']);
        $this->app->instance(System::class, $this->diskHost(prune: new \RuntimeException("buildx: no builder\n"), df: null));

        [$code, $out] = $this->run_('system:disk:guard');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Could not prune the host build cache: buildx: no builder\n", $out);
        $this->assertStringContainsString('nothing to do', $out);
    }

    public function test_a_total_that_is_no_size_frees_nothing(): void
    {
        $this->assertSame(0, GuardHostDisk::freed("Total:\tlots"));
    }

    private function diskHost(?\Throwable $prune, ?string $df): System
    {
        return new class ($prune, $df) extends System {
            public function __construct(private ?\Throwable $prune, private ?string $df)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if ($this->prune !== null) {
                    throw $this->prune;
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('unexpected process');
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                throw new \LogicException('unexpected process');
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->df ?? "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sda1 104857600 1 52428800 50% /\n";
            }
        };
    }

    public function test_nat_discovery_records_each_mapping_and_replaces_the_default_address(): void
    {
        $local = TestNetwork::private(5);
        $public = TestNetwork::routable(7);
        Setting::set('default_ipv4', $local);
        $root = (new ScriptedRoot)
            ->on('network.addressDetails', (string) json_encode([
                ['ifindex' => 1, 'ifname' => 'eth0', 'link_type' => 'ether', 'addr_info' => [['family' => 'inet', 'local' => $local, 'prefixlen' => 24, 'scope' => 'global']]],
            ]))
            ->on('network.defaultRoute', 'default via '.TestNetwork::private(1)." dev eth0 proto static\n")
            ->on('network.publicAddressLookup', $public."\n");

        try {
            [$code, $out] = $this->run_('system:nat:build', ['--lookup-url' => 'https://lookup.example.test/ip', '--replace-default-ipv4' => true]);
            $asked = $root->requests();
        } finally {
            $root->close();
        }

        $this->assertSame(0, $code, $out);
        $this->assertSame("Discovered IPv4 NAT mappings:\n  {$local} => {$public}\nReplaced default_ipv4: {$local} => {$public}\n", $out);
        $this->assertSame($public, Ipv4NatMap::getLocalToPublicMap()[$local] ?? null);
        $this->assertSame($public, Setting::get('default_ipv4'));
        $lookups = array_filter($asked, static fn (array $argv): bool => in_array('network.publicAddressLookup', $argv, true));
        $this->assertStringContainsString('https://lookup.example.test/ip?local_ip=', implode(' ', array_merge(...array_values($lookups))));
    }

    public function test_nat_discovery_that_finds_nothing_warns(): void
    {
        $root = (new ScriptedRoot)->on('network.addressDetails', '[]');

        try {
            [$code, $out] = $this->run_('system:nat:build');
        } finally {
            $root->close();
        }

        $this->assertSame([0, "No IPv4 NAT mappings were discovered.\n"], [$code, $out]);
    }
}
