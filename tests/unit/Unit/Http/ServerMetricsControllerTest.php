<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\ServerMetricsController;
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

    private function insertMetric(Carbon $timestamp, float $cpu, float $ram): void
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
