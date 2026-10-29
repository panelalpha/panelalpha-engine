<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\GeoLocation\DbIp;
use App\Integrations\Statistics\Awstats;
use Tests\Support\TestNetwork;
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

        $fixtures = base_path('../tests/unit/fixtures/awstats');
        foreach (scandir($fixtures) ?: [] as $file) {
            if (!str_starts_with($file, 'awstats')) {
                continue;
            }
            file_put_contents($this->dataDir . '/' . $file, strtr((string) file_get_contents($fixtures . '/' . $file), self::visitors()));
        }
    }

    /** The visitors the fixture names by placeholder. */
    private static function visitors(): array
    {
        return ['{visitor-us}' => TestNetwork::routable(8), '{visitor-au}' => TestNetwork::routable(1)];
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
        ], $this->withoutSessionLength($stats->domainVisitors('example.com', '2026-09-01', '2026-09-30')));
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
            'session_length' => [
                'records' => ['2026-09-01' => null, '2026-09-02' => null],
                'average' => null,
                'estimated' => true,
            ],
        ], $stats->domainVisitors('missing.com', '2026-09-01', '2026-09-02'));
        $this->assertSame([
            'unique' => 0,
            'total' => 0,
            'visits' => ['records' => [], 'total' => 0],
            'visits_length' => [],
        ], $this->withoutSessionLength($stats->domainVisitors('empty.com', '2026-09-01', '2026-09-30')));
    }

    public function test_session_length_is_each_days_average_at_the_bucket_midpoints(): void
    {
        file_put_contents($this->dataDir . '/awstats09202601.example.com.txt', "BEGIN_SESSION 2\n0s-30s 2\n30s-2mn 2\nEND_SESSION\n");
        file_put_contents($this->dataDir . '/awstats09202602.example.com.txt', "BEGIN_SESSION 0\nEND_SESSION\n");
        file_put_contents($this->dataDir . '/awstats09202604.example.com.txt', "BEGIN_SESSION 2\n2mn-5mn 1\nunknown 9\nEND_SESSION\n");
        $stats = new Awstats($this->dataDir);

        $this->assertSame([
            'records' => [
                '2026-09-01' => 45,
                '2026-09-02' => null,
                '2026-09-03' => null,
                '2026-09-04' => 210,
            ],
            'average' => 78,
            'estimated' => true,
        ], $stats->domainVisitors('example.com', '2026-09-01', '2026-09-04')['session_length']);
    }

    public function test_session_length_counts_only_the_requested_days(): void
    {
        file_put_contents($this->dataDir . '/awstats09202601.example.com.txt', "BEGIN_SESSION 1\n1h+ 5\nEND_SESSION\n");
        file_put_contents($this->dataDir . '/awstats09202602.example.com.txt', "BEGIN_SESSION 1\n0s-30s 1\nEND_SESSION\n");
        $stats = new Awstats($this->dataDir);

        $this->assertSame(
            ['records' => ['2026-09-02' => 15], 'average' => 15, 'estimated' => true],
            $stats->domainVisitors('example.com', '2026-09-02', '2026-09-02')['session_length'],
        );
    }

    /**
     * @param array<string, mixed> $overview
     * @return array<string, mixed>
     */
    private function withoutSessionLength(array $overview): array
    {
        $this->assertArrayHasKey('session_length', $overview);
        unset($overview['session_length']);

        return $overview;
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
            self::visitors()['{visitor-us}'] => [
                'country' => 'United States',
                'country_code' => 'US',
                'region' => 'California',
                'region_code' => 'CA',
            ],
            self::visitors()['{visitor-au}'] => [
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
