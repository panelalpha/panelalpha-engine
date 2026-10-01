<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * engine#182: a source recipe's `check:` list and `checks/` directory never ran.
 *
 * declaredChecks() walked PlatformRegistry::all(), which has no source recipes,
 * runChecks() called the two-argument CheckRunner::for(), so the persisted
 * `deploy_checks_dir` was never read, and a check naming a path other than `/`
 * was reported failed as "not fetched". Market Radar is the shipped case.
 */
class AppHealthRecipeChecksTest extends TestCase
{
    private const URL = 'https://github.com/vvolv/market-radar';

    private const SHELL = '<!doctype html><html><head><title>Market Radar</title></head><body><div id="root"></div></body></html>';

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        CheckRegistry::flush();
        SourceRecipes::flush();
        parent::tearDown();
    }

    /** @return array<string, mixed> what the deploy froze onto a Market Radar account */
    private static function marketRadarDetails(): array
    {
        return [
            AppHealth::DETAIL_RUNTIME => 'compose',
            AppHealth::DETAIL_PLATFORM => 'market-radar',
            AppHealth::DETAIL_CHECKS_DIR => SourceRecipes::checksDirectory(SourceRecipes::directoryFor(self::URL) ?? ''),
        ];
    }

    public function test_the_recipes_own_check_is_asked_about_its_own_path(): void
    {
        $runner = AppHealth::checkRunnerFor(self::marketRadarDetails());

        $this->assertSame(['/api/health'], $runner->paths());
    }

    public function test_a_dead_database_behind_a_healthy_shell_is_reported(): void
    {
        $runner = AppHealth::checkRunnerFor(self::marketRadarDetails());
        $paths = AppHealth::parsePathProbeOutput(
            "/api/health\t500 0.004\t" . base64_encode('{"status":"error","database":"unreachable"}') . "\n",
            'http',
            8080
        );

        $report = $runner->runByPath(
            ['/' => new ProbedResponse(200, self::SHELL, 'http://127.0.0.1:8080/')] + $paths,
            sys_get_temp_dir()
        );

        $this->assertSame('database_error', $report['serving']);
    }

    public function test_a_connected_database_passes(): void
    {
        $runner = AppHealth::checkRunnerFor(self::marketRadarDetails());
        $paths = AppHealth::parsePathProbeOutput(
            "/api/health\t200 0.004\t" . base64_encode('{"status":"ok","database":"connected"}') . "\n",
            'http',
            8080
        );

        $report = $runner->runByPath(
            ['/' => new ProbedResponse(200, self::SHELL, 'http://127.0.0.1:8080/')] + $paths,
            sys_get_temp_dir()
        );

        $this->assertSame('ok', $report['serving']);
    }

    /** A shipped platform with no recipe directory resolves exactly as before. */
    public function test_a_shipped_platform_needs_no_checks_directory(): void
    {
        $runner = AppHealth::checkRunnerFor([
            AppHealth::DETAIL_RUNTIME => 'php',
            AppHealth::DETAIL_PLATFORM => 'laravel',
        ]);

        $this->assertSame([], $runner->paths());
    }

    public function test_the_path_probe_quotes_each_path_and_dials_loopback(): void
    {
        $script = AppHealth::pathProbeScript('https', 8443, ['/api/health', "/a'b"], 3);

        $this->assertStringContainsString("'/api/health'", $script);
        $this->assertStringContainsString("'/a'\\''b'", $script);
        $this->assertStringContainsString("'https://127.0.0.1:8443'", $script);
        $this->assertStringContainsString('--max-time 3', $script);
        $this->assertStringNotContainsString('Host:', $script);
    }

    public function test_the_path_probe_asks_with_the_projects_domain_as_host(): void
    {
        $script = AppHealth::pathProbeScript('http', 8000, ['/api/health'], 3, ' Shop.Example.COM ');

        $this->assertStringContainsString("-H 'Host: shop.example.com' ", $script);
        $this->assertStringContainsString("-H 'X-Forwarded-Proto: https' ", $script);
        $this->assertStringContainsString("'http://127.0.0.1:8000'", $script);
    }

    public function test_a_path_that_got_no_answer_is_none_and_garbage_is_ignored(): void
    {
        $parsed = AppHealth::parsePathProbeOutput(
            "/api/health\t000 0\t\nnot a line\n\t200 0\t\n",
            'http',
            8080
        );

        $this->assertSame(['/api/health'], array_keys($parsed));
        $this->assertFalse($parsed['/api/health']->answered());
        $this->assertSame('http://127.0.0.1:8080/api/health', $parsed['/api/health']->url);
    }
}
