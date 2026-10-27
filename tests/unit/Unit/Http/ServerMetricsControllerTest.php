<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\ServerMetricsController;
use App\Lib\Host\ServerMetricsHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class ServerMetricsControllerTest extends TestCase
{
    private ServerMetricsController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->runMigration('2025_07_16_091653_create_server_metrics_table.php');
        $this->runMigration('2025_08_08_084810_add_index_to_server_metrics_table.php');
        $this->runMigration('2026_09_28_000000_add_disk_percent_to_server_metrics_table.php');

        $this->controller = new ServerMetricsController();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('server_metrics');
        parent::tearDown();
    }

    private function runMigration(string $file): void
    {
        $migration = require base_path("database/migrations/{$file}");
        $migration->up();
    }

    private function insertMetric(Carbon $timestamp, float $cpu, float $ram, ?float $disk = null): void
    {
        DB::table('server_metrics')->insert([
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'cpu_percent' => $cpu,
            'cpu_load_avg_1' => 0.1,
            'cpu_load_avg_5' => 0.2,
            'cpu_load_avg_15' => 0.3,
            'ram_percent' => $ram,
            'swap_percent' => 0,
            'disk_read_bps' => 0,
            'disk_write_bps' => 0,
            'disk_read_iops' => 0,
            'disk_write_iops' => 0,
            'net_in_bps' => 0,
            'net_out_bps' => 0,
            'net_in_pps' => 0,
            'net_out_pps' => 0,
            'disk_percent' => $disk,
        ]);
    }

    public function test_last_hour_averages_only_counts_rows_within_the_window_on_sqlite(): void
    {
        $now = Carbon::now('UTC');
        $this->insertMetric($now->copy()->subMinutes(10), 10.0, 20.0);
        $this->insertMetric($now->copy()->subMinutes(30), 30.0, 60.0);
        $this->insertMetric($now->copy()->subHours(2), 999.0, 999.0); // outside the hour

        $response = $this->controller->lastHourAverages();
        $data = json_decode($response->getContent(), true)['data'];

        $this->assertEqualsWithDelta(20.0, $data['avg_cpu_percent'], 0.001);
        $this->assertEqualsWithDelta(40.0, $data['avg_ram_percent'], 0.001);
    }

    public function test_last_hour_averages_is_null_with_no_rows_on_sqlite(): void
    {
        $response = $this->controller->lastHourAverages();
        $data = json_decode($response->getContent(), true)['data'];

        $this->assertNull($data['avg_cpu_percent']);
        $this->assertNull($data['avg_ram_percent']);
    }

    public function test_last_5_minutes_buckets_rows_and_fills_empty_periods_on_sqlite(): void
    {
        $bucketSeconds = 5;
        $timestamp = Carbon::now('UTC')->subSeconds($bucketSeconds);
        $timestamp->setTimestamp((int)floor($timestamp->timestamp / $bucketSeconds) * $bucketSeconds);
        $this->insertMetric($timestamp, 42.0, 55.0);

        $response = $this->controller->last5Minutes();
        $data = json_decode($response->getContent(), true)['data'];

        $this->assertCount(60, $data); // 295s / 5s + 1
        $last = end($data);
        // json_decode turns whole-number floats back into ints, so compare loosely
        $this->assertEquals(42.0, $last['cpu_percent']);
        $this->assertEquals(55.0, $last['ram_percent']);
        // an empty bucket only carries its period, no metrics
        $this->assertArrayNotHasKey('cpu_percent', $data[0]);
    }

    /**
     * The queries no longer contain any driver-specific SQL (NOW() - INTERVAL,
     * FROM_UNIXTIME/UNIX_TIMESTAMP), so there's nothing MySQL-flavored left to
     * verify against a live server. These assert the SQL text the controller's
     * query-builder chains produce under the MySQL grammar via ->toSql(),
     * which is pure string building and never opens a PDO connection -- so no
     * live MySQL server is needed or contacted. lastHourAveragesQuery() and
     * rawMetricsQuery() are the exact builders the controller executes; only
     * pretend()-style *execution* would require a real driver (it escapes
     * bindings through PDO::quote() even under pretend), which is why we stop
     * at toSql() instead.
     */
    public function test_last_hour_averages_builds_portable_sql_on_mysql_grammar(): void
    {
        $query = $this->withMysqlDefault(fn () => $this->invokePrivate('lastHourAveragesQuery'));

        $sql = $query->toSql();
        $this->assertStringNotContainsStringIgnoringCase('NOW()', $sql);
        $this->assertStringNotContainsStringIgnoringCase('INTERVAL', $sql);
        $this->assertStringContainsString('`timestamp` >= ?', $sql);
        $this->assertCount(1, $query->getBindings());
    }

    public function test_bucketed_metrics_build_portable_sql_on_mysql_grammar(): void
    {
        $query = $this->withMysqlDefault(fn () => $this->invokePrivate('rawMetricsQuery', [Carbon::now('UTC')]));

        $sql = $query->toSql();
        $this->assertStringNotContainsStringIgnoringCase('FROM_UNIXTIME', $sql);
        $this->assertStringNotContainsStringIgnoringCase('UNIX_TIMESTAMP', $sql);
        $this->assertStringContainsString('`timestamp` >= ?', $sql);
        $this->assertStringStartsWith('select `timestamp`, `cpu_percent`', $sql);
    }

    public function test_history_averages_each_bucket_in_sql_on_sqlite(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-28 12:34:56', 'UTC'));
        try {
            // 24h buckets are 10 minutes; the last complete one starts 12:20.
            $this->insertMetric(Carbon::parse('2026-09-28 12:21:00', 'UTC'), 10.0, 20.0, 50.0);
            $this->insertMetric(Carbon::parse('2026-09-28 12:29:55', 'UTC'), 30.0, 40.0, 52.0);
            $this->insertMetric(Carbon::parse('2026-09-28 12:00:05', 'UTC'), 70.0, 80.0);
            $this->insertMetric(Carbon::parse('2026-09-28 12:31:00', 'UTC'), 999.0, 999.0); // still running
            $this->insertMetric(Carbon::parse('2026-09-27 12:19:59', 'UTC'), 999.0, 999.0); // before the window

            $body = ServerMetricsHistory::for('24h');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(['range' => '24h', 'bucket_seconds' => 600, 'from' => '2026-09-27 12:30:00', 'to' => '2026-09-28 12:20:00'], $body['meta']);
        $this->assertCount(144, $body['data']);

        $last = end($body['data']);
        $this->assertSame('2026-09-28 12:20:00', $last['period']);
        $this->assertEquals(20.0, $last['cpu_percent']);
        $this->assertEquals(30.0, $last['ram_percent']);
        $this->assertEquals(51.0, $last['disk_percent']);

        // A bucket whose rows predate disk_percent reports it as null, not zero.
        $noon = $body['data'][141];
        $this->assertSame('2026-09-28 12:00:00', $noon['period']);
        $this->assertEquals(70.0, $noon['cpu_percent']);
        $this->assertArrayHasKey('disk_percent', $noon);
        $this->assertNull($noon['disk_percent']);

        $this->assertSame(['period' => '2026-09-27 12:30:00'], $body['data'][0]);
    }

    public function test_history_ranges_have_their_own_bucket_sizes(): void
    {
        foreach (['1h' => [60, 60], '7d' => [3600, 168], '30d' => [21600, 120]] as $range => [$bucket, $points]) {
            $body = ServerMetricsHistory::for($range);
            $this->assertSame($bucket, $body['meta']['bucket_seconds'], $range);
            $this->assertCount($points, $body['data'], $range);
        }
    }

    public function test_history_groups_by_bucket_without_float_division_on_mysql_grammar(): void
    {
        $query = $this->withMysqlDefault(fn () => (new ReflectionMethod(ServerMetricsHistory::class, 'query'))->invoke(
            null,
            Carbon::now('UTC')->subDay(),
            Carbon::now('UTC'),
            600
        ));

        $sql = $query->toSql();
        $this->assertStringContainsString("TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', `timestamp`) DIV 600", $sql);
        $this->assertStringContainsString('AVG(`disk_percent`) AS `disk_percent`', $sql);
        $this->assertStringContainsString('group by bucket', $sql);
    }

    public function test_cpu_percent_is_the_busy_share_between_two_reads(): void
    {
        // user nice system idle iowait irq softirq steal
        $before = "cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 1 2 3 4\n";
        $after = "cpu  160 0 140 760 120 10 10 0 0 0\ncpu0 1 2 3 4\n";

        // 200 ticks passed, 80 of them idle or iowait
        $this->assertSame(60.0, ServerMetricsController::cpuPercentBetween($before, $after));
        $this->assertNull(ServerMetricsController::cpuPercentBetween($before, $before));
        $this->assertNull(ServerMetricsController::cpuPercentBetween('', $after));
    }

    // toSql() never touches PDO, but tearDown()'s Schema::dropIfExists does --
    // always restore the sqlite default so it doesn't try to reach a real MySQL
    private function withMysqlDefault(callable $callback): mixed
    {
        config(['database.default' => 'mysql']);
        try {
            return $callback();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    private function invokePrivate(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);
        return $reflection->invoke($this->controller, ...$args);
    }
}
