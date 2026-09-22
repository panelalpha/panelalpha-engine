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
