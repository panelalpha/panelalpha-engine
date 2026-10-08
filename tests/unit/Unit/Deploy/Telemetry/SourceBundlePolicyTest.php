<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bundle missing a file is a worse bug report. A bundle containing a private
 * key is an incident. These tests are weighted accordingly.
 */
class SourceBundlePolicyTest extends TestCase
{
    private const MAX_FILE = 2 * 1024 * 1024;

    // ── when a bundle may be made at all ──────────────────────────────────

    public function test_off_is_the_default_and_never_captures(): void
    {
        $this->assertSame(SourceBundlePolicy::MODE_OFF, SourceBundlePolicy::normalizeMode(null));
        $this->assertSame(SourceBundlePolicy::MODE_OFF, SourceBundlePolicy::normalizeMode(''));
        $this->assertSame(SourceBundlePolicy::MODE_OFF, SourceBundlePolicy::normalizeMode('yes-please'));

        $this->assertFalse(SourceBundlePolicy::shouldCapture(
            SourceBundlePolicy::MODE_OFF,
            DeployReport::OUTCOME_FAILED,
            null
        ));
    }

    public function test_unexplained_mode_captures_only_failures_with_no_rule(): void
    {
        $mode = SourceBundlePolicy::MODE_UNEXPLAINED;

        $this->assertTrue(SourceBundlePolicy::shouldCapture($mode, DeployReport::OUTCOME_FAILED, null));
        $this->assertFalse(SourceBundlePolicy::shouldCapture($mode, DeployReport::OUTCOME_FAILED, 'disk-full'));
    }

    public function test_failed_mode_captures_any_failure(): void
    {
        $mode = SourceBundlePolicy::MODE_FAILED;

        $this->assertTrue(SourceBundlePolicy::shouldCapture($mode, DeployReport::OUTCOME_FAILED, null));
        $this->assertTrue(SourceBundlePolicy::shouldCapture($mode, DeployReport::OUTCOME_FAILED, 'disk-full'));
    }

    /**
     * A recovered signal is one event in an otherwise fine deploy, and would be
     * the highest-volume outcome of the three. Neither it nor a partial deploy
     * is worth a copy of someone's source.
     */
    #[DataProvider('nonFailureOutcomes')]
    public function test_only_outright_failures_are_ever_candidates(string $outcome): void
    {
        foreach ([SourceBundlePolicy::MODE_UNEXPLAINED, SourceBundlePolicy::MODE_FAILED] as $mode) {
            $this->assertFalse(
                SourceBundlePolicy::shouldCapture($mode, $outcome, null),
                "{$outcome} was treated as a bundle candidate in mode {$mode}"
            );
        }
    }

    /** @return list<list<string>> */
    public static function nonFailureOutcomes(): array
    {
        return [
            [DeployReport::OUTCOME_PARTIAL],
            [DeployReport::OUTCOME_RECOVERED],
            [DeployReport::OUTCOME_SUCCESS],
            [DeployReport::OUTCOME_CANCELLED],
        ];
    }

    // ── what may travel ───────────────────────────────────────────────────

    #[DataProvider('secretPaths')]
    public function test_secrets_never_travel(string $path): void
    {
        $this->assertFalse(
            SourceBundlePolicy::shouldInclude($path, 100, self::MAX_FILE),
            "{$path} would have been uploaded"
        );
    }

    /** @return list<list<string>> */
    public static function secretPaths(): array
    {
        return [
            ['.env'],
            ['.env.local'],
            ['.env.production'],
            ['config/.env.staging'],
            ['certs/server.pem'],
            ['certs/server.key'],
            ['keystore.jks'],
            ['deploy/id_rsa'],
            ['deploy/id_ed25519'],
            ['.npmrc'],
            ['.netrc'],
            ['.git-credentials'],
            ['secrets.json'],
            ['config/master.key'],
            ['gcp-service-account-prod.json'],
            ['db/backup.sql'],
            ['storage/database.sqlite'],
            ['dump.dump'],
        ];
    }

    /**
     * Example env files carry no values and are exactly what the engine's own
     * env materialisation reads — a deploy that failed on a missing variable is
     * unreadable without them.
     */
    #[DataProvider('allowedEnvFiles')]
    public function test_example_env_files_do_travel(string $path): void
    {
        $this->assertTrue(SourceBundlePolicy::shouldInclude($path, 100, self::MAX_FILE));
    }

    /** @return list<list<string>> */
    public static function allowedEnvFiles(): array
    {
        return [['.env.example'], ['.env.sample'], ['.env.dist'], ['.env.template']];
    }

    #[DataProvider('excludedDirectoryPaths')]
    public function test_dependency_and_build_directories_are_excluded(string $path): void
    {
        $this->assertFalse(SourceBundlePolicy::shouldInclude($path, 100, self::MAX_FILE));
    }

    /** @return list<list<string>> */
    public static function excludedDirectoryPaths(): array
    {
        return [
            ['.git/config'],
            ['node_modules/react/index.js'],
            ['vendor/laravel/framework/src/x.php'],
            ['.next/static/chunk.js'],
            ['dist/main.js'],
            ['target/debug/app'],
            ['.venv/lib/python3.11/site-packages/x.py'],
            ['apps/web/node_modules/nested/dep.js'],
            ['.ssh/known_hosts'],
            ['.aws/credentials'],
        ];
    }

    #[DataProvider('sourcePaths')]
    public function test_the_files_that_explain_a_build_do_travel(string $path): void
    {
        $this->assertTrue(
            SourceBundlePolicy::shouldInclude($path, 1000, self::MAX_FILE),
            "{$path} was excluded but is needed to diagnose a build"
        );
    }

    /** @return list<list<string>> */
    public static function sourcePaths(): array
    {
        return [
            ['package.json'],
            ['bun.lock'],
            ['composer.json'],
            ['Dockerfile'],
            ['docker-compose.yml'],
            ['src/index.ts'],
            ['app/Http/Controllers/HomeController.php'],
            ['next.config.mjs'],
            ['go.mod'],
            ['Gemfile'],
            ['panelalpha.md'],
            ['.dockerignore'],
            ['.nvmrc'],
        ];
    }

    /**
     * `docker/Dockerfile` is one of the nested Dockerfile candidates the
     * detector looks for, so the directory must not be swept up with the build
     * output directories.
     */
    public function test_the_docker_directory_is_not_excluded(): void
    {
        $this->assertTrue(SourceBundlePolicy::shouldInclude('docker/Dockerfile', 500, self::MAX_FILE));
        $this->assertTrue(SourceBundlePolicy::shouldInclude('scripts/docker/Dockerfile', 500, self::MAX_FILE));
    }

    public function test_oversized_files_are_excluded(): void
    {
        $this->assertTrue(SourceBundlePolicy::shouldInclude('assets/hero.png', 1024, 2048));
        $this->assertFalse(SourceBundlePolicy::shouldInclude('assets/hero.png', 4096, 2048));
    }

    public function test_leading_slashes_and_empty_paths_do_not_bypass_the_rules(): void
    {
        $this->assertFalse(SourceBundlePolicy::shouldInclude('/node_modules/x.js', 10, self::MAX_FILE));
        $this->assertFalse(SourceBundlePolicy::shouldInclude('/.env', 10, self::MAX_FILE));
        $this->assertFalse(SourceBundlePolicy::shouldInclude('', 10, self::MAX_FILE));
    }

    public function test_backslash_paths_are_normalised_before_matching(): void
    {
        $this->assertFalse(SourceBundlePolicy::shouldInclude('config\\.env.production', 10, self::MAX_FILE));
    }

    public function test_case_does_not_defeat_the_secret_rules(): void
    {
        $this->assertFalse(SourceBundlePolicy::shouldInclude('Certs/Server.PEM', 10, self::MAX_FILE));
        $this->assertFalse(SourceBundlePolicy::shouldInclude('.ENV.Production', 10, self::MAX_FILE));
    }
}
