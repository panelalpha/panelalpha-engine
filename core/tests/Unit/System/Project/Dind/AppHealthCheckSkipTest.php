<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

/**
 * engine#182, the Apaxy follow-up. Apaxy's product is a directory listing, so
 * `_baseline/no-directory-listing` scored the working application
 * `serving: directory_listing`. The recipe now skips that check, covered by its
 * own `apaxy-theme-applied`; the skip is reported, and it lifts only while
 * the cover is asked.
 *
 * The bodies are the head of real responses from upstream's own image
 * (oupala/apaxy 5b76e32, `docker build .`, port 8080): the stock themed
 * listing (103,516 bytes) and the same container with its .htaccess removed.
 */
class AppHealthCheckSkipTest extends TestCase
{
    private const URL = 'https://github.com/oupala/apaxy';

    private const THEMED = <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
 <head>
  <title>Index of /</title>
  <link rel="stylesheet" href="/theme/style.css" type="text/css" />
        <link rel="shortcut icon" href="/theme/favicon.ico" />        <meta name="viewport" content="width=device-width, initial-scale=1" /> </head>
 <body>
<div class="wrapper">
<!-- we open the `wrapper` element here, but close it in the `footer.html` file -->

<ol class="breadcrumb" id="breadcrumb">
</ol>

<input type="search" id="filter" placeholder="filter content" />
HTML;

    private const UNTHEMED = <<<'HTML'
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
 <head>
  <title>Index of /</title>
 </head>
 <body>
<h1>Index of /</h1>
<ul><li><a href="README"> README</a></li>
<li><a href="example.3dml"> example.3dml</a></li>
HTML;

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

    /** @return array<string, mixed> what the deploy froze onto an Apaxy account */
    private static function apaxyDetails(): array
    {
        return [
            'git_repo' => self::URL,
            AppHealth::DETAIL_RUNTIME => 'dockerfile',
            // The recipe states no id, so it keeps the one it extends.
            AppHealth::DETAIL_PLATFORM => 'dockerfile',
            AppHealth::DETAIL_CHECKS_DIR => SourceRecipes::checksDirectory(SourceRecipes::directoryFor(self::URL) ?? ''),
        ];
    }

    /** @return array{serving: string, checks: array<string, array<string, mixed>>} */
    private static function report(CheckRunner $runner, string $body): array
    {
        $report = $runner->run(new ProbedResponse(200, $body, 'http://127.0.0.1:8080/'), null);
        $checks = [];
        foreach ($report['checks'] as $check) {
            $checks[$check['group'] . '/' . $check['id']] = $check;
        }

        return ['serving' => $report['serving'], 'checks' => $checks];
    }

    /** The control: the same checks with no skip score Apaxy working as a listing. */
    public function test_without_the_skip_the_working_app_reads_as_a_directory_listing(): void
    {
        $runner = CheckRunner::forWithDirectory('dockerfile', [], self::apaxyDetails()[AppHealth::DETAIL_CHECKS_DIR]);
        $report = self::report($runner, self::THEMED);

        $this->assertSame('directory_listing', $report['serving']);
        $this->assertSame('fail', $report['checks']['_baseline/no-directory-listing']['status']);
        $this->assertSame('pass', $report['checks']['_baseline/apaxy-theme-applied']['status']);
    }

    public function test_the_apaxy_account_reports_the_skip_with_its_reason(): void
    {
        $report = self::report(AppHealth::checkRunnerFor(self::apaxyDetails()), self::THEMED);

        $this->assertSame('ok', $report['serving']);
        $skipped = $report['checks']['_baseline/no-directory-listing'];
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('error', $skipped['severity']);
        $this->assertStringContainsString('the listing is the application', (string) $skipped['detail']);
        $this->assertSame('_baseline/apaxy-theme-applied', $skipped['evidence']['covered_by']);
        $this->assertSame('pass', $report['checks']['_baseline/apaxy-theme-applied']['status']);
    }

    /** Skipping removes that one check's contribution and nothing else. */
    public function test_the_skip_does_not_hide_the_listing_it_is_covered_by(): void
    {
        $report = self::report(AppHealth::checkRunnerFor(self::apaxyDetails()), self::UNTHEMED);

        $this->assertSame('unthemed_listing', $report['serving']);
        $this->assertSame('skipped', $report['checks']['_baseline/no-directory-listing']['status']);
        $this->assertSame('fail', $report['checks']['_baseline/apaxy-theme-applied']['status']);
    }

    public function test_every_other_check_is_still_asked(): void
    {
        $with = self::report(AppHealth::checkRunnerFor(self::apaxyDetails()), self::THEMED);
        $without = self::report(
            CheckRunner::forWithDirectory('dockerfile', [], self::apaxyDetails()[AppHealth::DETAIL_CHECKS_DIR]),
            self::THEMED
        );

        $this->assertSame(array_keys($without['checks']), array_keys($with['checks']));
        $statuses = static fn (array $r): array => array_map(static fn (array $c): string => $c['status'], $r['checks']);
        $this->assertSame(
            array_diff_key($statuses($without), ['_baseline/no-directory-listing' => 1]),
            array_diff_key($statuses($with), ['_baseline/no-directory-listing' => 1])
        );
    }

    /**
     * The runner's own guard, for a skip that reached it without the reader:
     * an error check whose cover is not asked is run, not waived.
     */
    public function test_an_error_skip_whose_cover_is_not_asked_still_runs(): void
    {
        $runner = CheckRunner::forWithDirectory('dockerfile', [], null, [[
            'check' => '_baseline/no-directory-listing',
            'reason' => 'r',
            'covered_by' => '_baseline/apaxy-theme-applied',
        ]]);
        $report = self::report($runner, self::THEMED);

        $this->assertSame('directory_listing', $report['serving']);
        $this->assertSame('fail', $report['checks']['_baseline/no-directory-listing']['status']);
    }

    public function test_an_error_skip_with_no_cover_still_runs(): void
    {
        $runner = new CheckRunner(CheckRegistry::for('dockerfile'), [
            ['check' => '_baseline/no-directory-listing', 'reason' => 'r', 'covered_by' => null],
        ]);

        $this->assertSame('directory_listing', self::report($runner, self::UNTHEMED)['serving']);
    }

    /** A deploy that pinned another recipe keeps that one's checks, not the repository's. */
    public function test_a_platform_the_recipe_is_not_resolves_by_id(): void
    {
        $details = ['git_repo' => self::URL, AppHealth::DETAIL_RUNTIME => 'php', AppHealth::DETAIL_PLATFORM => 'php'];
        $report = self::report(AppHealth::checkRunnerFor($details), self::UNTHEMED);

        $this->assertSame('fail', $report['checks']['_baseline/no-directory-listing']['status']);
    }
}
