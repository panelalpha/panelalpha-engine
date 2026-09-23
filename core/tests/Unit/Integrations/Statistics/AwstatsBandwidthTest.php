<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\Statistics\Awstats;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AwstatsBandwidthTest extends TestCase
{
    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = sys_get_temp_dir() . '/pa-awstats-' . bin2hex(random_bytes(8));
        mkdir($this->dataDir, 0775, true);

        $fixtures = base_path('tests/fixtures/awstats');
        foreach (scandir($fixtures) ?: [] as $file) {
            if (!str_starts_with($file, 'awstats')) {
                continue;
            }
            copy($fixtures . '/' . $file, $this->dataDir . '/' . $file);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob($this->dataDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dataDir)) {
            rmdir($this->dataDir);
        }
        parent::tearDown();
    }

    public function test_domain_daily_series_reads_day_bandwidth_from_the_text_database(): void
    {
        $stats = new Awstats($this->dataDir);

        $series = $stats->domainBandwidth('example.com', '2026-09-01', '2026-09-30', 'day');

        $this->assertSame([
            '2026-09-01' => 1000,
            '2026-09-02' => 2500,
            '2026-09-15' => 4000,
        ], $series);
    }

    public function test_daily_bandwidth_includes_traffic_awstats_did_not_count_as_viewed(): void
    {
        // Measured on a live project: 20 browser requests and 52 from curl,
        // which AWStats files as a robot, at 355 bytes each.
        file_put_contents($this->dataDir . '/awstats092026.robots.com.txt', implode("\n", [
            'BEGIN_DAY 1',
            '20260923 20 20 7100 1',
            'END_DAY',
            '',
        ]));
        file_put_contents($this->dataDir . '/awstats09202623.robots.com.txt', implode("\n", [
            'BEGIN_TIME 24',
            '7 0 0 0 1 1 355',
            '8 20 20 7100 51 51 18105',
            'END_TIME',
            'BEGIN_DAY 1',
            '20260923 20 20 7100 1',
            'END_DAY',
            '',
        ]));
        $stats = new Awstats($this->dataDir);

        $this->assertSame(['2026-09-23' => 25560], $stats->domainBandwidth('robots.com', '2026-09-01', '2026-09-30', 'day'));
        $this->assertSame(['2026-09-01' => 25560], $stats->domainBandwidth('robots.com', '2026-09-01', '2026-09-30', 'month'));
    }

    public function test_a_day_with_only_robot_traffic_is_not_missing_from_the_series(): void
    {
        file_put_contents($this->dataDir . '/awstats092026.robots.com.txt', "BEGIN_DAY 0\nEND_DAY\n");
        file_put_contents($this->dataDir . '/awstats09202622.robots.com.txt', "BEGIN_TIME 24\n8 0 0 0 52 52 18460\nEND_TIME\n");
        file_put_contents($this->dataDir . '/awstats09202624.robots.com.txt', "BEGIN_TIME 24\n9 0 0 0 1 1 355\nEND_TIME\n");
        $stats = new Awstats($this->dataDir);

        $this->assertSame(['2026-09-22' => 18460], $stats->domainBandwidth('robots.com', '2026-09-01', '2026-09-23', 'day'));
    }

    public function test_domain_series_clips_to_the_requested_range(): void
    {
        $stats = new Awstats($this->dataDir);

        $series = $stats->domainBandwidth('example.com', '2026-09-02', '2026-09-15', 'day');

        $this->assertSame([
            '2026-09-02' => 2500,
            '2026-09-15' => 4000,
        ], $series);
    }

    public function test_domain_month_buckets_sum_days(): void
    {
        $stats = new Awstats($this->dataDir);

        $series = $stats->domainBandwidth('example.com', '2026-08-01', '2026-09-30', 'month');

        $this->assertSame([
            '2026-08-01' => 100,
            '2026-09-01' => 7500,
        ], $series);
    }

    public function test_project_bandwidth_sums_domains(): void
    {
        $stats = new Awstats($this->dataDir);

        $series = $stats->projectBandwidth(
            ['example.com', 'other.com'],
            '2026-09-01',
            '2026-09-02',
            'day'
        );

        $this->assertSame([
            '2026-09-01' => 1500,
            '2026-09-02' => 3200,
        ], $series);
    }

    public function test_missing_or_empty_files_are_zeros_not_errors(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([], $stats->domainBandwidth('missing.com', '2026-09-01', '2026-09-30', 'day'));
        $this->assertSame([], $stats->domainBandwidth('empty.com', '2026-09-01', '2026-09-30', 'day'));
        $this->assertSame(0, $stats->projectCalendarMonthBytes(['missing.com', 'empty.com']));
    }

    public function test_calendar_month_usage_is_host_timezone_bytes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 15:00:00', 'UTC'));
        config(['app.timezone' => 'UTC']);

        $stats = new Awstats($this->dataDir);

        $this->assertSame(8700, $stats->projectCalendarMonthBytes(['example.com', 'other.com']));
    }
}
