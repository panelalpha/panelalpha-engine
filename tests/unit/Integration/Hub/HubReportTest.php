<?php

namespace Tests\Integration\Hub;

use App\Lib\Hub\HubReport;
use App\Lib\Hub\HubSsoRoutes;
use App\Models\Backup;
use App\Models\BackupContainer;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestNetwork;

/** The minute report: exactly the fields the Hub reads, from stored data only, within the Hub's ranges. */
class HubReportTest extends HubTestCase
{
    private const NOW = '2026-11-24 10:00:07';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_report_has_exactly_the_fields_the_hub_reads_and_nothing_private(): void
    {
        $connection = $this->pushConnection();
        $connection->forceFill(['host_facts' => [
            'hostname' => 'srv1.acme.com', 'os' => 'Ubuntu 24.04.1 LTS', 'kernel' => '6.8.0-45-generic', 'cpu_cores' => 8,
            'memory_total_bytes' => 17179869184, 'disk_total_bytes' => 214748364800, 'ipv4' => TestNetwork::public(10),
            'booted_at' => Carbon::now()->getTimestamp() - 3600,
        ]])->save();
        $user = $this->project('shop', [
            'deploy_strategy' => 'php',
            'deployment_status' => 'success',
            'health_healthy' => true,
            'git_repo' => 'https://deploy:ghp_SECRETTOKEN@github.com/acme/shop.git?token=leak#frag',
            'git_branch' => 'main',
            'git_commit' => 'abc123',
            'git_token' => 'ghp_OTHERSECRET',
            'env_vars' => ['DB_PASSWORD' => 'hunter2'],
        ]);
        $user->forceFill(['email' => 'owner@acme.com', 'name' => 'Shop'])->save();

        $report = HubReport::build($connection);

        $this->assertSame(['schema', 'sent_at', 'engine', 'host', 'usage', 'projects'], array_keys($report));
        $this->assertSame(1, $report['schema']);
        $this->assertSame(['hostname', 'os', 'kernel', 'uptime_seconds', 'cpu_cores', 'memory_total_bytes', 'disk_total_bytes', 'ipv4'], array_keys($report['host']));
        $this->assertSame(3600, $report['host']['uptime_seconds'], 'counted on from the stored boot time');

        $project = $report['projects'][0];
        $this->assertSame(['username', 'name', 'domain', 'status', 'created_at', 'protected', 'staging_of', 'app', 'details', 'config', 'usage', 'disk', 'containers'], array_keys($project));
        $this->assertSame(['status', 'type', 'label', 'framework', 'public_url', 'admin_sso'], array_keys($project['app']));
        $this->assertSame(['health_healthy', 'deployment_status', 'git_repo', 'git_branch', 'git_commit'], array_keys($project['details']));
        $this->assertSame(['cpu_limit', 'memory_limit', 'disk_space_limit'], array_keys($project['config']));
        $this->assertSame('https://github.com/acme/shop.git', $project['details']['git_repo']);

        $json = (string) json_encode($report);
        foreach (['ghp_SECRETTOKEN', 'deploy:', 'token=leak', 'ghp_OTHERSECRET', 'hunter2', 'owner@acme.com', '/home/', 'mysql_prefix', self::SECRET] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_every_project_goes_in_every_report_with_every_key_even_with_nothing_stored(): void
    {
        $connection = $this->pushConnection();
        $this->project('full', ['deploy_strategy' => 'php', 'deployment_status' => 'success', 'health_healthy' => false]);
        $this->project('bare', []);
        $this->project('off', [], 'suspended');
        $this->project('old', []);
        DB::table('users')->where('username', 'old')->update(['created_at' => null]);

        $projects = HubReport::build($connection)['projects'];

        $this->assertSame(['full', 'bare', 'off', 'old'], array_column($projects, 'username'), 'an app left out counts as gone');
        foreach ($projects as $project) {
            $this->assertSame(['username', 'name', 'domain', 'status', 'created_at', 'protected', 'staging_of', 'app', 'details', 'config', 'usage', 'disk', 'containers'], array_keys($project));
            $this->assertSame(['status', 'type', 'label', 'framework', 'public_url', 'admin_sso'], array_keys($project['app']));
            $this->assertSame(['health_healthy', 'deployment_status', 'git_repo', 'git_branch', 'git_commit'], array_keys($project['details']));
            $this->assertSame(['cpu_limit', 'memory_limit', 'disk_space_limit'], array_keys($project['config']));
            $this->assertSame([], $project['containers']);
        }
        $this->assertSame('suspended', $projects[2]['status']);
        $this->assertNull($projects[3]['created_at'], 'a row without a creation time does not stop the report');
        $this->assertFalse($projects[0]['details']['health_healthy']);
    }

    public function test_backups_go_only_when_the_last_answer_asked_for_them(): void
    {
        $connection = $this->pushConnection();
        $user = $this->project('shop');
        $container = BackupContainer::query()->forceCreate(['name' => 'local', 'driver' => 'local', 'location' => '/backups']);
        $backup = Backup::prepare($user, $container);
        $backup->setBackupStatus('completed');
        $backup->save();

        $this->assertArrayNotHasKey('backups', HubReport::build($connection));

        $connection->forceFill(['report_want' => ['backups', 'something_new']])->save();
        $backups = HubReport::build($connection)['backups'];

        $this->assertSame(['shop'], array_keys($backups));
        $this->assertSame(['id', 'async_status', 'error', 'size_bytes', 'updated_at'], array_keys($backups['shop'][0]));
        $this->assertSame('completed', $backups['shop'][0]['async_status']['backup']);
    }

    public function test_host_usage_is_the_last_complete_minute_and_null_when_stale(): void
    {
        $connection = $this->pushConnection();
        $this->assertNull(HubReport::build($connection)['usage']);

        foreach ([['09:59:05', 10.0, 100], ['09:59:35', 20.0, -5], ['10:00:02', 99.0, 7]] as [$at, $cpu, $bps]) {
            DB::table('server_metrics')->insert([
                'timestamp' => '2026-11-24 '.$at, 'cpu_percent' => $cpu, 'cpu_load_avg_1' => 0, 'cpu_load_avg_5' => 0,
                'cpu_load_avg_15' => 0, 'ram_percent' => 50, 'swap_percent' => 0, 'disk_read_bps' => 0, 'disk_write_bps' => 0,
                'disk_read_iops' => 0, 'disk_write_iops' => 0, 'net_in_bps' => $bps, 'net_out_bps' => -1, 'net_in_pps' => 0,
                'net_out_pps' => 0, 'disk_percent' => 120,
            ]);
        }

        $usage = HubReport::build($connection)['usage'];

        $this->assertSame('2026-11-24T09:59:00Z', $usage['sampled_at']);
        $this->assertSame(15.0, $usage['cpu_percent'], 'the minute still running is left out');
        $this->assertSame(100.0, $usage['disk_percent'], 'held to the range the Hub accepts');
        $this->assertSame(48, $usage['net_in_bps']);
        $this->assertNull($usage['net_out_bps'], 'a negative counter is no value, never a negative one');
    }

    public function test_sent_at_always_moves_forward_even_within_one_millisecond(): void
    {
        $connection = $this->pushConnection();

        $first = HubReport::build($connection)['sent_at'];
        $second = HubReport::build($connection->refresh())['sent_at'];

        $this->assertSame('2026-11-24T10:00:07.000Z', $first);
        $this->assertSame('2026-11-24T10:00:07.001Z', $second);
    }

    public function test_values_out_of_the_hubs_range_are_left_out(): void
    {
        $connection = $this->pushConnection();
        $connection->forceFill(['host_facts' => ['hostname' => '-bad host-', 'cpu_cores' => 99999, 'ipv4' => 'not-an-ip']])->save();
        $this->project('Bad Name');
        $this->project('ok', ['deploy_strategy' => 'php', 'git_repo' => 'ftp:/nohost']);

        $report = HubReport::build($connection);

        $this->assertNull($report['host']['hostname']);
        $this->assertNull($report['host']['cpu_cores']);
        $this->assertNull($report['host']['ipv4']);
        $this->assertSame(['ok'], array_column($report['projects'], 'username'), 'a username the Hub refuses would refuse every project');
        $this->assertNull($report['projects'][0]['details']['git_repo']);
    }

    public function test_admin_login_is_offered_only_once_every_vhost_has_its_route(): void
    {
        $connection = $this->pushConnection(['admin_sso']);
        $this->project('wp', ['deploy_strategy' => 'compose', 'deploy_admin_sso' => true]);

        $this->assertFalse(HubReport::build($connection)['projects'][0]['app']['admin_sso']);

        Setting::set(HubSsoRoutes::SETTING, '1');

        $this->assertTrue(HubReport::build($connection->refresh())['projects'][0]['app']['admin_sso']);
    }

    public function test_containers_are_keyed_by_service_from_the_newest_sample(): void
    {
        $connection = $this->pushConnection();
        $this->project('shop');
        foreach (['-3 minutes', '-1 minute'] as $ago) {
            DB::table('project_container_metrics')->insert([
                'timestamp' => Carbon::now('UTC')->modify($ago)->format('Y-m-d H:i:s'), 'username' => 'shop',
                'container_id' => str_repeat('a', 64), 'name' => 'shop-app-1', 'service' => 'app', 'cpu_percent' => 150.5,
                'memory_bytes' => 1000, 'memory_limit_bytes' => null,
            ]);
        }

        $containers = HubReport::build($connection)['projects'][0]['containers'];

        $this->assertCount(1, $containers);
        $this->assertSame(['service' => 'app', 'name' => 'shop-app-1', 'cpu_percent' => 150.5, 'memory_bytes' => 1000,
            'memory_limit_bytes' => null, 'sampled_at' => '2026-11-24T09:59:07Z'], $containers[0]);
    }

    public function test_a_report_of_many_projects_is_built_from_the_database_in_well_under_a_second(): void
    {
        $connection = $this->pushConnection();
        foreach (range(1, 120) as $n) {
            $this->project("p{$n}", ['deploy_strategy' => 'php', 'deployment_status' => 'success']);
        }

        $started = microtime(true);
        $report = HubReport::build($connection);

        $this->assertCount(120, $report['projects']);
        $this->assertLessThan(1.0, microtime(true) - $started);
    }
}
