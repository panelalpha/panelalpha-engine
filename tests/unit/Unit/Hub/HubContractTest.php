<?php

namespace Tests\Unit\Hub;

use App\Lib\Deploy\Telemetry\HostFacts;
use App\Lib\Project\ProjectOverview;
use App\Models\Backup;
use App\Models\BackupContainer;
use App\System;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WP5: the fields PanelAlpha Connect reads from the regular API, called with
 * a Hub token. A rename or a shape change here breaks every connected Hub, so
 * it has to break this first.
 */
class HubContractTest extends HubTestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->hubToken(['admin_sso'])->plainTextToken;
    }

    /**
     * The whole route shells out to the webserver, so the unit suite locks
     * the two parts the Hub reads: `version`, and `host` as HostFacts builds it.
     */
    public function test_system_info_version_and_host(): void
    {
        $host = HostFacts::host(new System());
        $this->assertSame(
            ['hostname', 'os', 'kernel', 'uptime_seconds', 'cpu_cores', 'memory_total_bytes', 'disk_total_bytes', 'ipv4'],
            array_keys($host)
        );
        $this->assertSame('srv1.acme.com', $host['hostname']);
        $this->assertSame(self::ENGINE_IP, $host['ipv4']);

        $source = (string) file_get_contents(app_path('Http/Controllers/SystemController.php'));
        $this->assertStringContainsString("'version' => config('system.version'),", $source);
        $this->assertStringContainsString("'host' => HostFacts::host(\$system),", $source);
    }

    public function test_metrics_current(): void
    {
        $data = $this->withToken($this->token)->getJson('/api/metrics/current')->assertOk()->json('data');

        foreach (['cpu_usage_percent', 'ram_usage_percent', 'disk_usage_percent'] as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertTrue($data[$key] === null || is_numeric($data[$key]), $key);
        }
    }

    public function test_metrics_history(): void
    {
        // One sample in a complete bucket of every range.
        foreach ([5, 90, 13 * 60] as $minutesAgo) {
            $this->sample(Carbon::now('UTC')->subMinutes($minutesAgo));
        }

        foreach (['1h' => 60, '24h' => 600, '7d' => 3600, '30d' => 21600] as $range => $bucket) {
            $body = $this->withToken($this->token)->getJson("/api/metrics/history?range={$range}")->assertOk()->json();

            $this->assertSame($bucket, $body['meta']['bucket_seconds'], $range);
            $this->assertSame($range, $body['meta']['range']);
            foreach ($body['data'] as $period) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $period['period']);
            }

            $filled = array_values(array_filter($body['data'], fn (array $p): bool => count($p) > 1));
            $this->assertNotEmpty($filled, $range);
            foreach (['cpu_percent', 'ram_percent', 'disk_percent', 'net_in_bps', 'net_out_bps', 'disk_read_bps', 'disk_write_bps'] as $key) {
                $this->assertIsNumeric($filled[0][$key], "{$range} {$key}");
            }
            $empty = array_values(array_filter($body['data'], fn (array $p): bool => count($p) === 1));
            $this->assertSame(['period'], array_keys($empty[0]), 'a bucket without samples carries only its period');
        }
    }

    private function sample(Carbon $at): void
    {
        DB::table('server_metrics')->insert([
            'timestamp' => $at->format('Y-m-d H:i:s'),
            'cpu_percent' => 11.2, 'cpu_load_avg_1' => 0, 'cpu_load_avg_5' => 0, 'cpu_load_avg_15' => 0,
            'ram_percent' => 60.1, 'swap_percent' => 0, 'disk_read_bps' => 4096, 'disk_write_bps' => 81920,
            'disk_read_iops' => 0, 'disk_write_iops' => 0, 'net_in_bps' => 120345, 'net_out_bps' => 98231,
            'net_in_pps' => 0, 'net_out_pps' => 0, 'disk_percent' => 28.3,
        ]);
    }

    public function test_projects_all(): void
    {
        $this->project('shop', [
            'deploy_strategy' => 'php',
            'deploy_label' => 'WordPress',
            'deploy_admin_sso' => true,
            'deployment_status' => 'success',
            'health_healthy' => true,
        ]);
        $this->project('api', ['deploy_strategy' => 'express', 'deployment_status' => 'failed'], 'suspended');
        ProjectOverview::fakeRunning(['shop']);

        $body = $this->withToken($this->token)->getJson('/api/projects/all')->assertOk()->json();
        $this->assertArrayNotHasKey('meta', $body, 'not paginated');

        $shop = collect($body['data'])->firstWhere('username', 'shop');
        $this->assertSame('shop', $shop['username']);
        $this->assertArrayHasKey('name', $shop);
        $this->assertSame('active', $shop['status']);
        $this->assertNotNull($shop['created_at']);
        $this->assertTrue($shop['details']['health_healthy']);
        $this->assertSame('success', $shop['details']['deployment_status']);
        $this->assertSame([
            'type' => 'wordpress',
            'label' => 'WordPress',
            'status' => 'live',
            'public_url' => 'https://shop.example.com',
            'admin_sso' => true,
        ], array_intersect_key($shop['app'], array_flip(['type', 'label', 'status', 'public_url', 'admin_sso'])));

        $api = collect($body['data'])->firstWhere('username', 'api');
        $this->assertSame('suspended', $api['status']);
        $this->assertSame('suspended', $api['app']['status']);
        $this->assertSame('failed', $api['details']['deployment_status']);
    }

    public function test_project_backups_newest_first(): void
    {
        $user = $this->project('shop');
        $container = BackupContainer::query()->forceCreate(['name' => 'local', 'driver' => 'local', 'location' => '/backups']);

        $older = Backup::prepare($user, $container);
        $older->setBackupStatus('failed');
        $older->error = 'No space left on device';
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $newer = Backup::prepare($user, $container);
        $newer->setBackupStatus('completed');
        $newer->save();
        $newer->items()->create(['remote_path' => 'files.tar.gz', 'size_bytes' => 700]);
        $newer->items()->create(['remote_path' => 'database-app.sql.gz', 'size_bytes' => 34]);

        $data = $this->withToken($this->token)->getJson('/api/projects/shop/backups')->assertOk()->json('data');

        $this->assertSame([$newer->id, $older->id], array_column($data, 'id'));
        $this->assertSame('completed', $data[0]['async_status']['backup']);
        $this->assertSame(734, $data[0]['size_bytes']);
        $this->assertNull($data[0]['error']);
        $this->assertSame('failed', $data[1]['async_status']['backup']);
        $this->assertSame('No space left on device', $data[1]['error']);
        foreach (['created_at', 'updated_at'] as $key) {
            $this->assertNotFalse(Carbon::canBeCreatedFromFormat($data[0][$key], DATE_ATOM) ?: strtotime($data[0][$key]), $key);
        }
    }

    /**
     * Both run the app's own script inside its container, so the suite locks
     * the envelope: users under `data`, the login link as a bare `url`.
     */
    public function test_admin_login_envelopes(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/User/AppUserController.php'));

        $this->assertStringContainsString("new JsonResponse(['data' => \$this->appManager(\$username)->listUsers()])", $source);
        $this->assertSame(3, substr_count($source, "return new JsonResponse(['url' => \$"), 'every SSO pattern answers a bare url');
    }

    public function test_token_routes(): void
    {
        $rotated = $this->withToken($this->token)->postJson('/api/tokens/current/rotate')->assertOk()->json('data');
        $this->assertSame(['token', 'token_id', 'abilities', 'expires_at'], array_keys($rotated));

        $this->app['auth']->forgetGuards();
        $this->withToken($rotated['token'])->deleteJson('/api/tokens/current')->assertNoContent();
    }
}
