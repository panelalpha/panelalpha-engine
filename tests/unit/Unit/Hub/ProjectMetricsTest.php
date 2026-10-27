<?php

namespace Tests\Unit\Hub;

use App\Lib\Host\MetricBuckets;
use App\Lib\Host\ProjectStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** CPU and memory per project (its DinD container) and per container inside it. */
class ProjectMetricsTest extends HubTestCase
{
    private const OUTER = 'f4460b8fc7101cf1aef37843bf5454b7c564f71045118f7683c94f41b1e48c92';
    private const INNER = '39beaea46ea41bef038340d1aa445ec7ba7c48c64d0dad7c3456ac5c7f78e8e0';

    public function test_the_host_script_is_valid_bash_and_names_only_projects(): void
    {
        $script = ProjectStats::script(['shop', "o'brien"]);
        $path = (string) tempnam(sys_get_temp_dir(), 'stats-');
        file_put_contents($path, $script);
        exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        @unlink($path);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString("want=' shop o'\\''brien '", $script);
    }

    public function test_a_container_config_is_read_only_when_it_is_a_regular_file_at_its_own_path(): void
    {
        $root = $this->dataRoot();
        $this->config($root, self::INNER, '{"Name":"/project-app-1"}');
        $this->config($root . '/elsewhere', self::OUTER, '{"Name":"/someone-else-1"}');

        $this->assertSame('{"Name":"/project-app-1"}', $this->readConfig($root, self::INNER));

        unlink($root . '/containers/' . self::INNER . '/config.v2.json');
        symlink($root . '/elsewhere/containers/' . self::OUTER . '/config.v2.json', $root . '/containers/' . self::INNER . '/config.v2.json');
        $this->assertSame('', $this->readConfig($root, self::INNER), 'a linked file');

        $this->removeTree($root . '/containers/' . self::INNER);
        symlink($root . '/elsewhere/containers/' . self::OUTER, $root . '/containers/' . self::INNER);
        $this->assertSame('', $this->readConfig($root, self::INNER), 'a linked container directory');

        $this->removeTree($root);
    }

    public function test_a_fifo_in_place_of_a_container_config_does_not_block_the_sampler(): void
    {
        $root = $this->dataRoot();
        mkdir($root . '/containers/' . self::INNER, 0700, true);
        exec('mkfifo ' . escapeshellarg($root . '/containers/' . self::INNER . '/config.v2.json'));

        $started = microtime(true);
        $this->assertSame('', $this->readConfig($root, self::INNER));
        $this->assertLessThan(1.5, microtime(true) - $started);

        $this->removeTree($root);
    }

    public function test_the_whole_host_run_is_time_limited(): void
    {
        $this->assertLessThan(60, ProjectStats::HOST_TIMEOUT_SECONDS, 'shorter than the sampling interval');
        $this->assertMatchesRegularExpression('/timeout -k 1 2 bash -c .*head -c 1048576/', ProjectStats::script(['shop']));
    }

    public function test_parse_reads_both_levels_and_drops_reclaimable_cache(): void
    {
        $out = "P\tshop\t76701696\t1073741824\t1701696\t143214671\n"
            . "C\tshop\t" . self::INNER . "\tproject-app-1\tapp\t52428800\tmax\t428800\t900000\n"
            . "C\tshop\tbuildkit\tx\ty\t1\t1\t1\t1\n"
            . "garbage\n";

        $read = ProjectStats::parse($out);

        $this->assertSame(['memory' => 75000000, 'limit' => 1073741824, 'usec' => 143214671], $read['projects']['shop']);
        $this->assertCount(1, $read['containers'], 'only hex container ids');
        $this->assertSame([
            'username' => 'shop', 'id' => self::INNER, 'name' => 'project-app-1', 'service' => 'app',
            'memory' => 52000000, 'limit' => null, 'usec' => 900000,
        ], $read['containers'][0]);
    }

    public function test_cpu_percent_is_cpu_time_over_wall_time(): void
    {
        $at = Carbon::parse('2026-10-01 10:00:00', 'UTC');

        $this->assertNull(ProjectStats::cpuPercent(null, 5, $at));
        // 30 s of CPU in 60 s is half a core.
        $this->assertSame(50.0, ProjectStats::cpuPercent(['usec' => 0, 'at' => $at], 30_000_000, $at->copy()->addMinute()));
        // Two cores flat out.
        $this->assertSame(200.0, ProjectStats::cpuPercent(['usec' => 0, 'at' => $at], 120_000_000, $at->copy()->addMinute()));
        // The container restarted and its counter began again.
        $this->assertNull(ProjectStats::cpuPercent(['usec' => 9_000_000, 'at' => $at], 10, $at->copy()->addMinute()));
    }

    public function test_sample_writes_both_levels_and_cpu_from_the_previous_sample(): void
    {
        $this->project('shop');
        $usec = 1_000_000;
        $stats = new ProjectStats(function (string $script) use (&$usec): string {
            return "P\tshop\t100\t1000\t0\t{$usec}\n"
                . "C\tshop\t" . self::INNER . "\tproject-app-1\tapp\t60\tmax\t0\t{$usec}\n";
        });

        $first = Carbon::parse('2026-10-01 10:00:00', 'UTC');
        $this->assertSame(['projects' => 1, 'containers' => 1], $stats->sample($first));
        $usec += 6_000_000;
        $stats->sample($first->copy()->addMinute());

        $rows = DB::table('project_metrics')->orderBy('timestamp')->get();
        $this->assertNull($rows[0]->cpu_percent);
        $this->assertEqualsWithDelta(10.0, (float) $rows[1]->cpu_percent, 0.001);
        $inner = DB::table('project_container_metrics')->orderBy('timestamp')->get();
        $this->assertEqualsWithDelta(10.0, (float) $inner[1]->cpu_percent, 0.001);
        $this->assertSame('app', $inner[1]->service);
    }

    public function test_a_failing_host_read_writes_nothing(): void
    {
        $this->project('shop');
        $stats = new ProjectStats(fn (): string => throw new \RuntimeException('nsenter failed'));

        $this->assertSame(['projects' => 0, 'containers' => 0], $stats->sample());
        $this->assertSame(0, DB::table('project_metrics')->count());
    }

    public function test_the_project_carries_its_latest_usage(): void
    {
        $this->project('shop');
        $this->project('idle');
        $now = Carbon::now('UTC');
        $this->row('project_metrics', $now->copy()->subMinute(), ['cpu_percent' => 3.456, 'memory_bytes' => 75000000, 'memory_limit_bytes' => 1073741824]);
        $this->row('project_metrics', $now->copy()->subHour(), ['username' => 'idle']);

        $projects = collect($this->withToken($this->adminToken())->getJson('/api/projects/all')->assertOk()->json('data'));

        $this->assertSame(
            ['cpu_percent' => 3.46, 'memory_bytes' => 75000000, 'memory_limit_bytes' => 1073741824],
            array_diff_key($projects->firstWhere('username', 'shop')['usage'], ['sampled_at' => 1])
        );
        $this->assertNull($projects->firstWhere('username', 'idle')['usage'], 'stale samples are not "now"');
    }

    public function test_project_history_uses_the_server_buckets(): void
    {
        $this->project('shop');
        $now = Carbon::now('UTC');
        foreach ([5, 90, 13 * 60] as $ago) {
            $this->row('project_metrics', $now->copy()->subMinutes($ago), ['cpu_percent' => 20, 'memory_bytes' => 1000, 'memory_limit_bytes' => 4000]);
        }
        // 7d and 30d read the hourly averages.
        ProjectStats::rollup($now);

        foreach (MetricBuckets::RANGES as $range => [, $bucket]) {
            $body = $this->withToken($this->adminToken())->getJson("/api/projects/shop/metrics/history?range={$range}")->assertOk()->json();

            $this->assertSame($bucket, $body['meta']['bucket_seconds']);
            $filled = array_values(array_filter($body['data'], fn (array $p): bool => count($p) > 1));
            $this->assertNotEmpty($filled, $range);
            $this->assertEquals(['period' => $filled[0]['period'], 'cpu_percent' => 20.0, 'memory_bytes' => 1000, 'memory_limit_bytes' => 4000], $filled[0]);
        }

        $this->withToken($this->adminToken())->getJson('/api/projects/shop/metrics/history?range=12h')->assertUnprocessable();
        $this->withToken($this->adminToken())->getJson('/api/projects/nope/metrics/history')->assertNotFound();
    }

    public function test_containers_now_and_over_a_range(): void
    {
        $this->project('shop');
        $now = Carbon::now('UTC');
        foreach ([3, 2] as $ago) {
            $at = $now->copy()->subMinutes($ago);
            $this->row('project_container_metrics', $at, ['container_id' => self::INNER, 'name' => 'project-app-1', 'service' => 'app', 'cpu_percent' => 1.5, 'memory_bytes' => 52000000]);
            $this->row('project_container_metrics', $at, ['container_id' => str_repeat('a', 64), 'name' => 'project-db-1', 'service' => 'db', 'cpu_percent' => 0.5, 'memory_bytes' => 300000000]);
        }

        $now_ = $this->withToken($this->adminToken())->getJson('/api/projects/shop/containers/metrics')->assertOk()->json('data');
        $this->assertSame(['project-app-1', 'project-db-1'], array_column($now_, 'name'));
        $this->assertSame(['container_id', 'name', 'service', 'cpu_percent', 'memory_bytes', 'memory_limit_bytes', 'sampled_at'], array_keys($now_[0]));
        $this->assertSame(52000000, $now_[0]['memory_bytes']);

        $ranged = $this->withToken($this->adminToken())->getJson('/api/projects/shop/containers/metrics?range=1h')->assertOk()->json();
        $this->assertSame(60, $ranged['meta']['bucket_seconds']);
        $this->assertCount(2, $ranged['data']);
        $this->assertSame(['container_id', 'name', 'service', 'history'], array_keys($ranged['data'][0]));
        $this->assertCount(60, $ranged['data'][0]['history']);
    }

    public function test_a_project_chart_lines_up_with_the_server_chart(): void
    {
        $this->project('shop');
        $token = $this->adminToken();

        Carbon::setTestNow(Carbon::now('UTC'));
        try {
            foreach (array_keys(MetricBuckets::RANGES) as $range) {
                $server = $this->withToken($token)->getJson("/api/metrics/history?range={$range}")->assertOk()->json();
                $project = $this->withToken($token)->getJson("/api/projects/shop/metrics/history?range={$range}")->assertOk()->json();

                $this->assertSame($server['meta'], $project['meta'], $range);
                $this->assertSame(array_column($server['data'], 'period'), array_column($project['data'], 'period'), $range);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('24h', $this->withToken($token)->getJson('/api/metrics/history')->assertOk()->json('meta.range'));
        $this->withToken($token)->getJson('/api/metrics/history?range=12h')->assertUnprocessable()->assertJsonValidationErrors(['range']);
    }

    public function test_rollup_averages_complete_hours_once(): void
    {
        $now = Carbon::parse('2026-10-01 12:30:00', 'UTC');
        foreach ([[10, 0, 10, 100], [10, 30, 30, 300], [11, 15, 50, 500], [12, 10, 99, 999]] as [$h, $m, $cpu, $mem]) {
            $at = Carbon::parse(sprintf('2026-10-01 %02d:%02d:00', $h, $m), 'UTC');
            $this->row('project_metrics', $at, ['cpu_percent' => $cpu, 'memory_bytes' => $mem, 'memory_limit_bytes' => 4000]);
            $this->row('project_container_metrics', $at, ['container_id' => self::INNER, 'name' => 'project-app-1', 'service' => 'app', 'cpu_percent' => $cpu, 'memory_bytes' => $mem]);
        }

        $this->assertSame(4, ProjectStats::rollup($now), 'two complete hours, two levels');
        $this->assertSame(2, ProjectStats::rollup($now), 'a rerun rewrites only the last averaged hour');
        $this->assertSame(2, DB::table('project_metrics_hourly')->count(), 'and duplicates nothing');

        $hours = DB::table('project_metrics_hourly')->orderBy('timestamp')->get();
        $this->assertSame(['2026-10-01 10:00:00', '2026-10-01 11:00:00'], $hours->pluck('timestamp')->all());
        $this->assertEqualsWithDelta(20.0, (float) $hours[0]->cpu_percent, 0.001);
        $this->assertSame(200, (int) $hours[0]->memory_bytes);
        $this->assertSame(2, (int) $hours[0]->samples);
        $this->assertSame('app', DB::table('project_container_metrics_hourly')->value('service'));
        $this->assertSame(2, DB::table('project_container_metrics_hourly')->count());

        // The 12:00 hour completes; only it is new.
        ProjectStats::rollup($now->copy()->addHour());
        $this->assertSame(3, DB::table('project_metrics_hourly')->count());
    }

    public function test_minute_rows_keep_two_days_and_hourly_rows_thirty(): void
    {
        $now = Carbon::now('UTC');
        $this->row('project_metrics', $now->copy()->subHours(49), ['cpu_percent' => 1]);
        $this->row('project_metrics', $now->copy()->subHours(47), ['cpu_percent' => 1]);
        foreach ([31, 29] as $days) {
            DB::table('project_metrics_hourly')->insert([
                'timestamp' => $now->copy()->subDays($days)->startOfHour()->format('Y-m-d H:i:s'),
                'username' => 'shop', 'cpu_percent' => 1, 'samples' => 60,
            ]);
        }

        $this->artisan('metrics:prune')->assertExitCode(0);

        $this->assertSame(1, DB::table('project_metrics')->count());
        $hourly = DB::table('project_metrics_hourly')->pluck('timestamp')->all();
        $this->assertCount(2, $hourly, 'the 29-day hour stays, the 47-hour sample was averaged in');
        $this->assertSame(
            $now->copy()->subHours(47)->startOfHour()->format('Y-m-d H:i:s'),
            max($hourly)
        );
    }

    public function test_long_ranges_read_hourly_averages_past_the_minute_rows(): void
    {
        $this->project('shop');
        DB::table('project_metrics_hourly')->insert([
            'timestamp' => Carbon::now('UTC')->subDays(6)->startOfHour()->format('Y-m-d H:i:s'),
            'username' => 'shop', 'cpu_percent' => 42, 'memory_bytes' => 5000, 'memory_limit_bytes' => 9000, 'samples' => 60,
        ]);

        $body = $this->withToken($this->adminToken())->getJson('/api/projects/shop/metrics/history?range=7d')->assertOk()->json();

        $filled = array_values(array_filter($body['data'], fn (array $p): bool => count($p) > 1));
        $this->assertCount(1, $filled);
        $this->assertEquals(42, $filled[0]['cpu_percent']);
        $this->assertSame(5000, $filled[0]['memory_bytes']);
    }

    /** @param array<string, mixed> $values */
    private function row(string $table, Carbon $at, array $values): void
    {
        DB::table($table)->insert(['timestamp' => $at->format('Y-m-d H:i:s'), 'username' => 'shop'] + $values);
    }

    private function dataRoot(): string
    {
        $root = sys_get_temp_dir() . '/stats-root-' . bin2hex(random_bytes(4));
        mkdir($root . '/containers', 0700, true);

        return (string) realpath($root);
    }

    private function config(string $root, string $id, string $json): void
    {
        @mkdir($root . '/containers/' . $id, 0700, true);
        file_put_contents($root . '/containers/' . $id . '/config.v2.json', $json);
    }

    /** Runs the host script's own config() function against a test data root. */
    private function readConfig(string $root, string $id): string
    {
        preg_match('/^config\(\) \{\n.*?^\}\n/ms', ProjectStats::script(['shop']), $function);
        $this->assertNotEmpty($function, 'the host script defines config()');
        $command = $function[0] . 'config ' . escapeshellarg($root) . ' ' . escapeshellarg($id);

        return (string) shell_exec('bash -c ' . escapeshellarg($command));
    }

    private function removeTree(string $path): void
    {
        exec('rm -rf ' . escapeshellarg($path));
    }
}
