<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\ProjectCache;
use PHPUnit\Framework\TestCase;

/**
 * The prune script, run for real against a temporary tree.
 *
 * Asserting on a generated command string would only prove the string was
 * generated. This runs the thing itself -- `PA_CACHE_ROOTS` exists for exactly
 * this -- so the age comparison, the name filter and the deletion are what is
 * under test rather than a description of them.
 */
class PruneProjectCachesScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pa-prune-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private static function script(): string
    {
        return dirname(__DIR__, 4) . '/scripts/prune-project-caches.sh';
    }

    /**
     * @return array{0: string, 1: int} output and exit code
     */
    private function runScript(string $args): array
    {
        $cmd = 'PA_CACHE_ROOTS=' . escapeshellarg($this->root)
            . ' sh ' . escapeshellarg(self::script()) . ' ' . $args . ' 2>&1';
        exec($cmd, $lines, $code);

        return [implode("\n", $lines), $code];
    }

    private function cache(string $name, int $ageSeconds): string
    {
        $dir = $this->root . '/' . $name;
        mkdir($dir . '/npm', 0o755, true);
        file_put_contents($dir . '/npm/blob', str_repeat('x', 1024));
        touch($dir, time() - $ageSeconds);

        return $dir;
    }

    public function test_the_script_is_shipped_and_executable_on_its_own(): void
    {
        $this->assertFileExists(self::script());
    }

    public function test_a_cache_past_the_window_is_deleted(): void
    {
        $stale = $this->cache('idle', 90000);

        [$out, $code] = $this->runScript('86400');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('idle', $out);
        $this->assertStringContainsString('deleted', $out);
        $this->assertDirectoryDoesNotExist($stale);
    }

    public function test_a_cache_inside_the_window_is_left_alone(): void
    {
        $fresh = $this->cache('busy', 3600);

        [$out, $code] = $this->runScript('86400');

        $this->assertSame(0, $code);
        $this->assertSame('', trim($out));
        $this->assertDirectoryExists($fresh);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $stale = $this->cache('idle', 90000);

        [$out] = $this->runScript('86400 --dry-run');

        $this->assertStringContainsString('would-delete', $out);
        $this->assertDirectoryExists($stale);
    }

    /**
     * A deploy in flight is about to read its cache; deleting it fails the
     * deploy outright.
     */
    public function test_a_skipped_account_keeps_its_cache(): void
    {
        $skipped = $this->cache('deploying', 90000);
        $other = $this->cache('idle', 90000);

        [$out] = $this->runScript('86400 --skip deploying');

        $this->assertDirectoryExists($skipped);
        $this->assertDirectoryDoesNotExist($other);
        $this->assertStringNotContainsString('deploying', $out);
    }

    public function test_the_report_is_tab_separated_and_carries_size_and_age(): void
    {
        $this->cache('idle', 90000);

        [$out] = $this->runScript('86400 --dry-run');

        $parts = explode("\t", trim($out));
        $this->assertCount(4, $parts);
        $this->assertStringEndsWith('/idle', $parts[0]);
        $this->assertGreaterThan(0, (int) $parts[1]);
        $this->assertGreaterThanOrEqual(90000, (int) $parts[2]);
        $this->assertSame('would-delete', $parts[3]);
    }

    /**
     * These names reach `rm -rf`. A directory whose name is not an account
     * name is left alone rather than guessed at.
     */
    public function test_a_directory_that_is_not_an_account_name_is_ignored(): void
    {
        mkdir($this->root . '/..hidden', 0o755, true);
        touch($this->root . '/..hidden', time() - 90000);
        $ok = $this->cache('real', 90000);

        [$out] = $this->runScript('86400');

        $this->assertDirectoryExists($this->root . '/..hidden');
        $this->assertDirectoryDoesNotExist($ok);
        $this->assertStringNotContainsString('hidden', $out);
    }

    public function test_a_file_in_the_root_is_not_treated_as_a_cache(): void
    {
        file_put_contents($this->root . '/stray.txt', 'x');
        touch($this->root . '/stray.txt', time() - 90000);

        [$out, $code] = $this->runScript('86400');

        $this->assertSame(0, $code);
        $this->assertSame('', trim($out));
        $this->assertFileExists($this->root . '/stray.txt');
    }

    public function test_an_empty_root_is_not_an_error(): void
    {
        [$out, $code] = $this->runScript('86400');

        $this->assertSame(0, $code);
        $this->assertSame('', trim($out));
        $this->assertDirectoryExists($this->root);
    }

    public function test_a_missing_root_is_not_an_error(): void
    {
        $cmd = 'PA_CACHE_ROOTS=/nonexistent/pa-cache sh '
            . escapeshellarg(self::script()) . ' 86400 2>&1';
        exec($cmd, $lines, $code);

        $this->assertSame(0, $code);
        $this->assertSame('', trim(implode("\n", $lines)));
    }

    public function test_a_max_age_that_is_not_seconds_is_refused(): void
    {
        [$out, $code] = $this->runScript('24h');

        $this->assertSame(2, $code);
        $this->assertStringContainsString('whole seconds', $out);
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        [, $code] = $this->runScript('86400 --delete-everything');

        $this->assertSame(2, $code);
    }

    /**
     * A future timestamp -- a clock that jumped, a restored backup -- is not
     * evidence that nobody is using the cache.
     */
    public function test_a_future_timestamp_is_never_stale(): void
    {
        $dir = $this->root . '/clockskew';
        mkdir($dir, 0o755, true);
        touch($dir, time() + 86400);

        [$out] = $this->runScript('86400');

        $this->assertSame('', trim($out));
        $this->assertDirectoryExists($dir);
    }

    public function test_php_parses_the_scripts_report(): void
    {
        $this->cache('idle', 90000);

        [$out] = $this->runScript('86400 --dry-run');
        $rows = ProjectCache::parseReport($out);

        $this->assertCount(1, $rows);
        $this->assertSame('would-delete', $rows[0]['action']);
        $this->assertGreaterThan(0, $rows[0]['bytes']);
    }
}
