<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\GeoLocation\DbIp;
use App\Integrations\Statistics\Awstats;
use Tests\TestCase;
use Tests\Unit\Integrations\GeoLocation\FakeGeoLocation;

class AwstatsVisitorsTest extends TestCase
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
        foreach (glob($this->dataDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dataDir)) {
            rmdir($this->dataDir);
        }
        parent::tearDown();
    }

    public function test_overview_reads_unique_hits_daily_visits_and_session_length(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([
            'unique' => 10,
            'total' => 63,
            'visits' => [
                'records' => [
                    '2026-09-01' => 5,
                    '2026-09-02' => 6,
                    '2026-09-15' => 7,
                ],
                'total' => 18,
            ],
            'visits_length' => [
                '0s-30s' => 8,
                '30s-2mn' => 6,
                '2mn-5mn' => 4,
            ],
        ], $stats->domainVisitors('example.com', '2026-09-01', '2026-09-30'));
    }

    public function test_overview_clips_daily_visits_to_the_requested_range(): void
    {
        $stats = new Awstats($this->dataDir);

        $overview = $stats->domainVisitors('example.com', '2026-09-02', '2026-09-15');

        $this->assertSame([
            '2026-09-02' => 6,
            '2026-09-15' => 7,
        ], $overview['visits']['records']);
        $this->assertSame(13, $overview['visits']['total']);
        $this->assertSame(43, $overview['total']);
        $this->assertSame(10, $overview['unique']);
        $this->assertSame([
            '0s-30s' => 8,
            '30s-2mn' => 6,
            '2mn-5mn' => 4,
        ], $overview['visits_length']);
        $this->assertSame([
            ['label' => '/', 'visits' => 10],
            ['label' => '/about', 'visits' => 5],
        ], $stats->domainVisitorBreakdown('example.com', 'pages', '2026-09-02', '2026-09-15'));
    }

    public function test_missing_files_yield_zero_overview_not_errors(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([
            'unique' => 0,
            'total' => 0,
            'visits' => ['records' => [], 'total' => 0],
            'visits_length' => [],
        ], $stats->domainVisitors('missing.com', '2026-09-01', '2026-09-30'));
        $this->assertSame([
            'unique' => 0,
            'total' => 0,
            'visits' => ['records' => [], 'total' => 0],
            'visits_length' => [],
        ], $stats->domainVisitors('empty.com', '2026-09-01', '2026-09-30'));
    }

    public function test_pages_breakdown_uses_sider_hits_not_visit_ratios(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([
            ['label' => '/', 'visits' => 10],
            ['label' => '/about', 'visits' => 5],
        ], $stats->domainVisitorBreakdown('example.com', 'pages', '2026-09-01', '2026-09-30'));
    }

    public function test_referrer_breakdown_uses_origin_keys(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([
            ['label' => 'direct', 'visits' => 30, 'code' => 'direct'],
            ['label' => 'website', 'visits' => 15, 'code' => 'website'],
            ['label' => 'search_engine', 'visits' => 10, 'code' => 'search_engine'],
            ['label' => 'internal', 'visits' => 4, 'code' => 'internal'],
            ['label' => 'unknown', 'visits' => 2, 'code' => 'unknown'],
        ], $stats->domainVisitorBreakdown('example.com', 'referrers', '2026-09-01', '2026-09-30'));
    }

    public function test_os_and_browser_breakdowns_use_section_hits(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([
            ['label' => 'win10', 'visits' => 40],
            ['label' => 'linux', 'visits' => 21],
        ], $stats->domainVisitorBreakdown('example.com', 'os', '2026-09-01', '2026-09-30'));
        $this->assertSame([
            ['label' => 'chrome', 'visits' => 50],
            ['label' => 'firefox', 'visits' => 11],
        ], $stats->domainVisitorBreakdown('example.com', 'browsers', '2026-09-01', '2026-09-30'));
    }

    public function test_unknown_dimension_and_missing_data_are_empty_arrays(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'devices', '2026-09-01', '2026-09-30'));
        $this->assertSame([], $stats->domainVisitorBreakdown('missing.com', 'pages', '2026-09-01', '2026-09-30'));
        $this->assertSame([], $stats->domainVisitorBreakdown('empty.com', 'os', '2026-09-01', '2026-09-30'));
    }

    public function test_geo_dimensions_are_empty_without_a_city_database(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'countries', '2026-09-01', '2026-09-30'));
        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'continents', '2026-09-01', '2026-09-30'));
        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'regions', '2026-09-01', '2026-09-30'));
    }

    public function test_geo_breakdowns_lookup_visitor_ips_offline(): void
    {
        $geo = new FakeGeoLocation([
            '8.8.8.8' => [
                'country' => 'United States',
                'country_code' => 'US',
                'region' => 'California',
                'region_code' => 'CA',
            ],
            '1.1.1.1' => [
                'country' => 'Australia',
                'country_code' => 'AU',
                'region' => 'New South Wales',
                'region_code' => 'NSW',
            ],
        ]);
        $stats = new Awstats($this->dataDir, geo: $geo);

        $this->assertSame([
            ['label' => 'United States', 'visits' => 10, 'code' => 'US'],
            ['label' => 'Australia', 'visits' => 6, 'code' => 'AU'],
        ], $stats->domainVisitorBreakdown('example.com', 'countries', '2026-09-01', '2026-09-30'));
        $this->assertSame([
            ['label' => 'North America', 'visits' => 10, 'code' => 'NA'],
            ['label' => 'Oceania', 'visits' => 6, 'code' => 'OC'],
        ], $stats->domainVisitorBreakdown('example.com', 'continents', '2026-09-01', '2026-09-30'));
        $this->assertSame([
            ['label' => 'California', 'visits' => 10, 'code' => 'CA'],
            ['label' => 'New South Wales', 'visits' => 6, 'code' => 'NSW'],
        ], $stats->domainVisitorBreakdown('example.com', 'regions', '2026-09-01', '2026-09-30'));
    }

    public function test_dbip_without_a_city_database_leaves_geo_empty(): void
    {
        $geo = new DbIp($this->dataDir);
        $stats = new Awstats($this->dataDir, geo: $geo);

        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'countries', '2026-09-01', '2026-09-30'));
        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'continents', '2026-09-01', '2026-09-30'));
        $this->assertSame([], $stats->domainVisitorBreakdown('example.com', 'regions', '2026-09-01', '2026-09-30'));
    }
}
