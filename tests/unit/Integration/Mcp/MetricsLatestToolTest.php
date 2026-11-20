<?php

namespace Tests\Integration\Mcp;

use App\Mcp\Tools\MetricsLatestTool;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class MetricsLatestToolTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_an_empty_metrics_table_answers_an_error(): void
    {
        $response = (new MetricsLatestTool())->handle(new Request([]));

        $this->assertTrue($response->isError());
        $this->assertSame('No metrics available yet.', (string) $response->content());
    }

    public function test_the_newest_sample_is_returned_grouped_by_resource(): void
    {
        $this->sample('2026-11-01 10:00:00', 11.5);
        $this->sample('2026-11-01 12:00:00', 42.25);
        $this->sample('2026-11-01 11:00:00', 7.0);

        $response = (new MetricsLatestTool())->handle(new Request([]));

        $this->assertFalse($response->isError());
        $body = json_decode((string) $response->content(), true);
        $this->assertSame('2026-11-01 12:00:00', $body['timestamp']);
        $this->assertEquals(42.25, $body['cpu_percent']);
        $this->assertEquals(['1m' => 1.5, '5m' => 1.25, '15m' => 1.0], $body['cpu_load_avg']);
        $this->assertEquals(63.5, $body['ram_percent']);
        $this->assertEquals(2.5, $body['swap_percent']);
        $this->assertEquals(['read_bps' => 1000, 'write_bps' => 2000, 'read_iops' => 30, 'write_iops' => 40], $body['disk_io']);
        $this->assertEquals(['in_bps' => 5000, 'out_bps' => 6000, 'in_pps' => 70, 'out_pps' => 80], $body['network_io']);
    }

    private function sample(string $at, float $cpu): void
    {
        DB::table('server_metrics')->insert([
            'timestamp' => $at,
            'cpu_percent' => $cpu,
            'cpu_load_avg_1' => 1.5,
            'cpu_load_avg_5' => 1.25,
            'cpu_load_avg_15' => 1.0,
            'ram_percent' => 63.5,
            'swap_percent' => 2.5,
            'disk_read_bps' => 1000,
            'disk_write_bps' => 2000,
            'disk_read_iops' => 30,
            'disk_write_iops' => 40,
            'net_in_bps' => 5000,
            'net_out_bps' => 6000,
            'net_in_pps' => 70,
            'net_out_pps' => 80,
        ]);
    }
}
