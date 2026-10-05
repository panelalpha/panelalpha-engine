<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\Statistics\Awstats;
use Tests\TestCase;

// Fixtures are AWStats' own output for 15 browser 200s, 6 404s, 4 500s and
// four more 200s from curl, all on 2026-10-03.
class AwstatsStatusCodesTest extends TestCase
{
    private string $dataDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = sys_get_temp_dir() . '/pa-awstats-status-' . bin2hex(random_bytes(8));
        mkdir($this->dataDir, 0775, true);
        foreach (glob(base_path('tests/fixtures/awstats-status') . '/awstats*') ?: [] as $file) {
            copy($file, $this->dataDir . '/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dataDir);
        parent::tearDown();
    }

    public function test_rows_are_the_errors_section_plus_what_is_left_for_200_and_304(): void
    {
        $rows = (new Awstats($this->dataDir))->domainVisitorBreakdown('status.example.com', 'status_codes', '2026-10-03', '2026-10-03');

        // The access log held 19 x 200 (893 bytes), 6 x 404 (2034), 4 x 500 (16).
        $this->assertSame([
            ['label' => '200/304', 'visits' => 19, 'code' => '200/304', 'bytes' => 893],
            ['label' => '404', 'visits' => 6, 'code' => '404', 'bytes' => 2034],
            ['label' => '500', 'visits' => 4, 'code' => '500', 'bytes' => 16],
        ], $rows);
    }

    public function test_a_day_outside_the_range_is_not_counted_though_its_month_is(): void
    {
        $stats = new Awstats($this->dataDir);

        $this->assertSame([], $stats->domainVisitorBreakdown('status.example.com', 'status_codes', '2026-10-02', '2026-10-02'));
        $this->assertCount(3, $stats->domainVisitorBreakdown('status.example.com', 'status_codes', '2026-09-20', '2026-10-31'));
    }

    public function test_a_month_without_day_databases_is_counted_whole(): void
    {
        rename(
            $this->dataDir . '/awstats102026.status.example.com.txt',
            $this->dataDir . '/awstats082026.status.example.com.txt'
        );

        $rows = (new Awstats($this->dataDir))->domainVisitorBreakdown('status.example.com', 'status_codes', '2026-08-10', '2026-08-10');

        $this->assertSame(['200/304' => 19, '404' => 6, '500' => 4], array_column($rows, 'visits', 'label'));
    }

    public function test_other_dimensions_do_not_carry_bytes(): void
    {
        $rows = (new Awstats($this->dataDir))->domainVisitorBreakdown('status.example.com', 'pages', '2026-10-01', '2026-10-31');

        $this->assertNotEmpty($rows);
        $this->assertArrayNotHasKey('bytes', $rows[0]);
    }
}
