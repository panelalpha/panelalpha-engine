<?php

namespace Tests\Unit\Integrations\Statistics;

use App\Integrations\Statistics\Awstats;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AwstatsIngestTest extends TestCase
{
    private string $root = '';

    /** @var list<list<string>> */
    private array $argv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pa-awstats-ingest-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0775, true);
        mkdir($this->root . '/data', 0775, true);
        mkdir($this->root . '/logs', 0775, true);
        $this->argv = [];
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_configure_writes_site_domain_aliases_log_format_and_data_dir(): void
    {
        $stats = $this->awstats();

        $stats->configureDomain('example.com', ['www.example.com', 'alias.example.com'], $this->root . '/logs');

        $conf = (string) file_get_contents($this->root . '/config/awstats.example.com.conf');
        $this->assertMatchesRegularExpression('/^SiteDomain="example\.com"$/m', $conf);
        $this->assertMatchesRegularExpression('/^HostAliases="www\.example\.com alias\.example\.com"$/m', $conf);
        $this->assertMatchesRegularExpression('/^LogFormat=1$/m', $conf);
        $this->assertMatchesRegularExpression('/^DirData="' . preg_quote($this->root . '/data', '/') . '"$/m', $conf);
        $this->assertMatchesRegularExpression(
            '/^LogFile="' . preg_quote($this->root . '/logs/access.log', '/') . '"$/m',
            $conf
        );
        $this->assertStringNotContainsString('LoadPlugin', $conf);
    }

    public function test_configure_is_idempotent(): void
    {
        $stats = $this->awstats();
        $stats->configureDomain('example.com', ['www.example.com'], $this->root . '/logs');
        $stats->configureDomain('example.com', ['www.example.com', 'new.example.com'], $this->root . '/logs');

        $conf = (string) file_get_contents($this->root . '/config/awstats.example.com.conf');
        $this->assertMatchesRegularExpression('/^HostAliases="www\.example\.com new\.example\.com"$/m', $conf);
        $this->assertSame(1, substr_count($conf, 'SiteDomain='));
    }

    public function test_forget_removes_that_domain_config_and_data_only(): void
    {
        $stats = $this->awstats();
        $stats->configureDomain('example.com', [], $this->root . '/logs');
        $stats->configureDomain('other.com', [], $this->root . '/logs');
        $stats->configureDomain('sub.example.com', [], $this->root . '/logs');
        file_put_contents($this->root . '/data/awstats092026.example.com.txt', 'keep-me-not');
        file_put_contents($this->root . '/data/awstats17092026.example.com.txt', 'day-break');
        file_put_contents($this->root . '/data/awstats092026.other.com.txt', 'keep');
        file_put_contents($this->root . '/data/awstats092026.sub.example.com.txt', 'sub');
        file_put_contents($this->root . '/data/awstats17092026.sub.example.com.txt', 'sub-day');

        $stats->forgetDomain('example.com');

        $this->assertFileDoesNotExist($this->root . '/config/awstats.example.com.conf');
        $this->assertFileDoesNotExist($this->root . '/data/awstats092026.example.com.txt');
        $this->assertFileDoesNotExist($this->root . '/data/awstats17092026.example.com.txt');
        $this->assertFileExists($this->root . '/config/awstats.other.com.conf');
        $this->assertFileExists($this->root . '/data/awstats092026.other.com.txt');
        $this->assertFileExists($this->root . '/config/awstats.sub.example.com.conf');
        $this->assertFileExists($this->root . '/data/awstats092026.sub.example.com.txt');
        $this->assertFileExists($this->root . '/data/awstats17092026.sub.example.com.txt');
    }

    public function test_ingest_writes_missing_conf_then_merges_and_updates_including_day_break(): void
    {
        file_put_contents($this->root . '/logs/access.log', "line\n");
        $stats = $this->awstats();

        $stats->ingestDomain('example.com', $this->root . '/logs', ['www.example.com']);

        $this->assertFileExists($this->root . '/config/awstats.example.com.conf');
        $this->assertCount(3, $this->argv);

        $merge = $this->argv[0];
        $this->assertSame('perl', $merge[0]);
        $this->assertSame('/usr/share/awstats/tools/logresolvemerge.pl', $merge[1]);
        $this->assertContains($this->root . '/logs/access.log', $merge);

        $monthly = $this->argv[1];
        $this->assertSame('perl', $monthly[0]);
        $this->assertSame('/usr/lib/cgi-bin/awstats.pl', $monthly[1]);
        $this->assertContains('-update', $monthly);
        $this->assertContains('-config=example.com', $monthly);
        $this->assertContains('-configdir=' . $this->root . '/config', $monthly);
        $this->assertNotEmpty(preg_grep('/^-logfile=/', $monthly));
        $this->assertEmpty(preg_grep('/databasebreak/', $monthly));

        $daily = $this->argv[2];
        $this->assertContains('-update', $daily);
        $this->assertContains('-databasebreak=day', $daily);
        $this->assertContains('-config=example.com', $daily);
    }

    public function test_ingest_backfill_uses_about_twelve_months_of_rotated_access_logs(): void
    {
        $recent = $this->root . '/logs/access.log-2026-08.log.gz';
        $old = $this->root . '/logs/access.log-2025-01.log.gz';
        $bytes = $this->root . '/logs/bytes.log';
        file_put_contents($this->root . '/logs/access.log', 'now');
        file_put_contents($recent, 'recent');
        file_put_contents($old, 'old');
        file_put_contents($bytes, 'bytes');
        touch($recent, Carbon::parse('2026-08-01')->timestamp);
        touch($old, Carbon::parse('2025-01-15')->timestamp);

        $this->awstats()->ingestDomain('example.com', $this->root . '/logs', []);

        $merge = $this->argv[0];
        $this->assertContains($this->root . '/logs/access.log', $merge);
        $this->assertContains($recent, $merge);
        $this->assertNotContains($old, $merge);
        $this->assertNotContains($bytes, $merge);
    }

    public function test_awstats_data_is_gitignored_like_config(): void
    {
        $gitignore = (string) file_get_contents(dirname(base_path()) . '/.gitignore');
        $this->assertMatchesRegularExpression('/^awstats-config\r?$/m', $gitignore);
        $this->assertMatchesRegularExpression('/^awstats-data\r?$/m', $gitignore);
    }

    private function awstats(): Awstats
    {
        $capture = function (array $argv): int {
            $this->argv[] = $argv;

            return 0;
        };

        return new class($this->root . '/data', $this->root . '/config', $capture) extends Awstats {
            public function __construct(string $dataDir, string $configDir, private \Closure $onRun)
            {
                parent::__construct($dataDir, $configDir);
            }

            protected function run(array $argv, ?string $stdoutFile = null): int
            {
                return ($this->onRun)($argv);
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
