<?php

namespace Tests\Integration\Integrations\Statistics;

use App\Integrations\Statistics\Awstats;
use Illuminate\Support\Carbon;
use Tests\Integration\Integrations\GeoLocation\FakeGeoLocation;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * AWStats databases with short or unknown rows, stray section markers and visitors the
 * geo database cannot place: the rows that make sense are counted, the rest skipped, and
 * a label seen in two months is one row.
 */
class AwstatsMalformedDataTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-awstats-malformed-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/data', 0775, true);
        mkdir($this->root.'/config', 0775, true);
        mkdir($this->root.'/logs', 0775, true);
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_page_seen_in_two_months_is_one_row_and_short_rows_are_skipped(): void
    {
        $this->month('082026', "BEGIN_SIDER 2\n/ 4 4 1 1\n/short\nEND_SIDER\n");
        $this->month('092026', "END_SIDER\nBEGIN_SIDER 2\n/ 6 6 1 1\n/about 3 3 1 1\nEND_SIDER\n/after 99\n");

        $this->assertSame([
            ['label' => '/', 'visits' => 10],
            ['label' => '/about', 'visits' => 3],
        ], $this->stats()->domainVisitorBreakdown('example.test', 'pages', '2026-08-01', '2026-09-30'));
    }

    public function test_os_and_referrer_rows_that_are_short_or_unknown_are_skipped(): void
    {
        $this->month('092026', "BEGIN_OS 2\nlinux 7 3\nwin10\nEND_OS\nBEGIN_ORIGIN 3\nFrom0 4 9\nFrom9 1 1\nFrom2 5\nEND_ORIGIN\n");
        $stats = $this->stats();

        $this->assertSame([['label' => 'linux', 'visits' => 7]], $stats->domainVisitorBreakdown('example.test', 'os', '2026-09-01', '2026-09-30'));
        $this->assertSame(
            [['label' => 'direct', 'visits' => 9, 'code' => 'direct']],
            $stats->domainVisitorBreakdown('example.test', 'referrers', '2026-09-01', '2026-09-30')
        );
        $this->assertSame([], $stats->domainVisitorBreakdown('example.test', 'nonsense', '2026-09-01', '2026-09-30'));
    }

    public function test_visitors_without_a_place_for_the_dimension_are_left_out(): void
    {
        $known = TestNetwork::routable(3);
        $nowhere = TestNetwork::routable(4);
        $unknownCountry = TestNetwork::routable(5);
        $this->month('092026', "BEGIN_VISITOR 4\n{$known} 5 8 1 20260901\n{$nowhere} 1 2 1 20260901\n{$unknownCountry} 1 3 1 20260901\nshort 1\nEND_VISITOR\n");
        $stats = $this->stats(new FakeGeoLocation([
            $known => ['country' => 'Poland', 'country_code' => 'PL', 'region' => '', 'region_code' => ''],
            $unknownCountry => ['country' => '', 'country_code' => 'XX', 'region' => 'Somewhere', 'region_code' => ''],
        ]));

        $this->assertSame(
            [['label' => 'Europe', 'visits' => 8, 'code' => 'EU']],
            $stats->domainVisitorBreakdown('example.test', 'continents', '2026-09-01', '2026-09-30')
        );
        $this->assertSame(
            [['label' => 'Somewhere', 'visits' => 3, 'code' => 'Somewhere']],
            $stats->domainVisitorBreakdown('example.test', 'regions', '2026-09-01', '2026-09-30')
        );
        $this->assertSame([
            ['label' => 'Poland', 'visits' => 8, 'code' => 'PL'],
            ['label' => 'XX', 'visits' => 3, 'code' => 'XX'],
        ], $stats->domainVisitorBreakdown('example.test', 'countries', '2026-09-01', '2026-09-30'));
    }

    public function test_short_day_and_session_rows_do_not_count(): void
    {
        $this->month('092026', "BEGIN_DAY 3\n20260901 1 2 300 4\n2026-09-02 1 2 300 4\n20260903 1 2\nEND_DAY\nBEGIN_SESSION 2\n0s-30s 3\n30s-2mn\nEND_SESSION\n");

        $visitors = $this->stats()->domainVisitors('example.test', '2026-09-01', '2026-09-30');

        $this->assertSame(['2026-09-01' => 4], $visitors['visits']['records']);
        $this->assertSame(['0s-30s' => 3], $visitors['visits_length']);
    }

    public function test_a_status_code_with_no_hits_left_is_not_a_row(): void
    {
        $this->month('092026', "BEGIN_TIME 1\n0 0 2 200 0 0 0\nEND_TIME\nBEGIN_ERRORS 2\n404 2 200\n500\nEND_ERRORS\n");

        $this->assertSame(
            [['label' => '404', 'visits' => 2, 'code' => '404', 'bytes' => 200]],
            $this->stats()->domainVisitorBreakdown('example.test', 'status_codes', '2026-09-01', '2026-09-30')
        );
    }

    public function test_ingesting_without_logs_only_writes_the_config(): void
    {
        $stats = $this->stats();

        $stats->ingestDomain('example.test', $this->root.'/no-logs-here');

        $this->assertFileExists($this->root.'/config/awstats.example.test.conf');
        $this->assertSame([], glob($this->root.'/data/*') ?: []);
    }

    public function test_only_current_and_recent_rotated_logs_are_merged(): void
    {
        $logs = $this->root.'/logs';
        touch($logs.'/access.log');
        touch($logs.'/access-2026-08.log.gz');
        touch($logs.'/access-2026-08.txt');
        touch($logs.'/error.log');
        mkdir($logs.'/access-2026-07.log');
        touch($logs.'/access-2025-01.log', Carbon::parse('2025-01-31')->timestamp);
        $runs = [];
        $stats = $this->recording($runs, 1);

        $stats->ingestDomain('example.test', $logs);

        $this->assertCount(1, $runs);
        $this->assertSame(['perl', '/merge.pl', $logs.'/access-2026-08.log.gz', $logs.'/access.log'], $runs[0]);
    }

    public function test_a_merge_tool_that_cannot_run_updates_nothing(): void
    {
        touch($this->root.'/logs/access.log');
        $stats = new Awstats($this->root.'/data', $this->root.'/config', null, $this->root.'/awstats.pl', $this->root.'/merge.pl');

        $stats->ingestDomain('example.test', $this->root.'/logs');

        $this->assertSame([], glob($this->root.'/data/*') ?: []);
    }

    private function month(string $mY, string $contents): void
    {
        file_put_contents($this->root."/data/awstats{$mY}.example.test.txt", $contents);
    }

    private function stats(?FakeGeoLocation $geo = null): Awstats
    {
        return new Awstats($this->root.'/data', $this->root.'/config', $geo);
    }

    /** @param list<list<string>> $runs */
    private function recording(array &$runs, int $exitCode): Awstats
    {
        return new class($this->root.'/data', $this->root.'/config', $runs, $exitCode) extends Awstats
        {
            /** @param list<list<string>> $runs */
            public function __construct(string $dataDir, string $configDir, private array &$runs, private int $exitCode)
            {
                parent::__construct($dataDir, $configDir, null, '/awstats.pl', '/merge.pl');
            }

            protected function run(array $argv, ?string $stdoutFile = null): int
            {
                $this->runs[] = $argv;

                return $this->exitCode;
            }
        };
    }
}
