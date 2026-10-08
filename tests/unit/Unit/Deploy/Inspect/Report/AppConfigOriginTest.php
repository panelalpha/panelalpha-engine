<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\AppConfigOrigin;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;

/**
 * Which app config applies to a project, and where it was taken from.
 *
 * Parsing goes through {@see AppConfig::load()} — the class the deploy
 * uses — so what is asserted here is the part `load()` does not report: which
 * of the candidate files was the one taken, under the same exists and
 * non-empty rules, so the two cannot disagree about it.
 */
class AppConfigOriginTest extends ReportTestCase
{
    public function test_a_project_that_ships_no_descriptor_has_no_app_config(): void
    {
        $this->assertSame([null, null], AppConfigOrigin::find($this->tmpDir, null));
    }

    public function test_a_yaml_descriptor_in_the_repository_is_reported_as_the_repositorys(): void
    {
        $this->write(AppConfig::YAML_FILENAME, "description: demo\nenv:\n  DEMO: '1'\n");

        [$appConfig, $origin] = AppConfigOrigin::find($this->tmpDir, null);

        $this->assertInstanceOf(AppConfig::class, $appConfig);
        $this->assertSame('repository', $origin);
    }

    public function test_a_markdown_page_in_the_repository_is_reported_the_same_way(): void
    {
        $this->write(AppConfig::MARKDOWN_FILENAME, "# Demo\n\nA page describing how to host this.\n");

        [$appConfig, $origin] = AppConfigOrigin::find($this->tmpDir, null);

        $this->assertInstanceOf(AppConfig::class, $appConfig);
        $this->assertSame('repository', $origin);
    }

    /**
     * The same emptiness rule `load()` applies. If the two disagreed, the
     * report would name a file the deploy ignores.
     */
    public function test_a_descriptor_with_nothing_in_it_is_not_an_app_config(): void
    {
        $this->write(AppConfig::YAML_FILENAME, "   \n\n");

        $this->assertSame([null, null], AppConfigOrigin::find($this->tmpDir, null));
    }

    public function test_a_repository_url_with_no_engine_page_does_not_invent_one(): void
    {
        $this->assertSame(
            [null, null],
            AppConfigOrigin::find($this->tmpDir, 'https://github.com/no-such-owner/no-such-repo.git')
        );
    }

    /**
     * The repository's own copy wins over anything written about it from
     * outside, so a project that ships one is always reported as the source.
     */
    public function test_the_repositorys_own_copy_wins_over_an_engine_page(): void
    {
        $this->write(AppConfig::YAML_FILENAME, "description: shipped by the repo\n");

        [, $origin] = AppConfigOrigin::find($this->tmpDir, 'https://github.com/some-owner/some-repo.git');

        $this->assertSame('repository', $origin);
    }

    public function test_a_trailing_slash_on_the_project_dir_still_reads_as_the_repositorys(): void
    {
        $this->write(AppConfig::YAML_FILENAME, "description: demo\n");

        [, $origin] = AppConfigOrigin::find($this->tmpDir . '/', null);

        $this->assertSame('repository', $origin);
    }
}
