<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\EnvironmentReport;

/**
 * Variable names, and never their values.
 *
 * The disclosure rule is the reason this section exists in the shape it does:
 * a committed .env is a mistake people make often, and an inspection endpoint
 * that echoed one back would turn that mistake into a leak.
 */
class EnvironmentReportTest extends ReportTestCase
{
    public function test_it_reports_names_and_never_values(): void
    {
        $this->write('.env', "APP_KEY=base64:sup3rs3cr3t\nSTRIPE_SECRET=sk_live_abc123\n");

        $report = EnvironmentReport::of($this->tmpDir, []);

        $this->assertSame(['APP_KEY', 'STRIPE_SECRET'], $report['variables']);
        $this->assertStringNotContainsString('sup3rs3cr3t', json_encode($report));
        $this->assertStringNotContainsString('sk_live_abc123', json_encode($report));
    }

    public function test_it_merges_every_candidate_file_and_dedupes_the_names(): void
    {
        $this->write('.env.example', "APP_KEY=\nDATABASE_URL=\n");
        $this->write('.env', "APP_KEY=real\nREDIS_URL=real\n");

        $report = EnvironmentReport::of($this->tmpDir, []);

        $this->assertSame(['.env.example', '.env'], $report['files']);
        $this->assertSame(['APP_KEY', 'DATABASE_URL', 'REDIS_URL'], $report['variables']);
    }

    /**
     * Kutt's spelling. The words the other way round is the same idea, and a
     * name this report does not know is a variable it cannot warn about: the
     * report came back `files: []` for a 3045-byte `.example.env` whose
     * eleventh line is `JWT_SECRET=`, and the deploy then restart-looped on
     * `Missing environment variables: JWT_SECRET: undefined` -- the one
     * variable that sank it, invisible in the report meant to name it.
     */
    public function test_it_reads_the_example_env_spelling(): void
    {
        $this->write('.example.env', "JWT_SECRET=\nDEFAULT_DOMAIN=localhost:3000\n");

        $report = EnvironmentReport::of($this->tmpDir, []);

        $this->assertSame(['.example.env'], $report['files']);
        $this->assertSame(['DEFAULT_DOMAIN', 'JWT_SECRET'], $report['variables']);
    }

    /** And the same without the leading dot. */
    public function test_it_reads_the_undotted_example_env(): void
    {
        $this->write('example.env', "JWT_SECRET=\n");

        $this->assertSame(['JWT_SECRET'], EnvironmentReport::of($this->tmpDir, [])['variables']);
    }

    public function test_names_are_sorted_so_the_report_is_stable(): void
    {
        $this->write('.env.example', "ZULU=\nALPHA=\nMIKE=\n");

        $this->assertSame(['ALPHA', 'MIKE', 'ZULU'], EnvironmentReport::of($this->tmpDir, [])['variables']);
    }

    public function test_comments_and_blank_lines_are_not_variables(): void
    {
        $this->write('.env.example', "# a comment\n\nAPP_KEY=\nnot a variable line\n");

        $this->assertSame(['APP_KEY'], EnvironmentReport::of($this->tmpDir, [])['variables']);
    }

    public function test_an_exported_variable_is_still_a_variable(): void
    {
        $this->write('.env.example', "export APP_KEY=\n");

        $this->assertSame(['APP_KEY'], EnvironmentReport::of($this->tmpDir, [])['variables']);
    }

    /**
     * A generated .env can hold thousands of keys, and the response has to
     * stay a response. The truncation has to be visible, or a caller reading
     * exactly MAX_KEYS names cannot tell a complete list from a cut one.
     */
    public function test_a_huge_env_file_is_truncated_and_says_so(): void
    {
        $lines = '';
        for ($i = 0; $i < EnvironmentReport::MAX_KEYS + 50; $i++) {
            $lines .= sprintf("KEY_%04d=\n", $i);
        }
        $this->write('.env.example', $lines);

        $report = EnvironmentReport::of($this->tmpDir, []);

        $this->assertCount(EnvironmentReport::MAX_KEYS, $report['variables']);
        $this->assertTrue($report['variables_truncated']);
    }

    public function test_a_list_that_fits_is_not_flagged_as_truncated(): void
    {
        $this->write('.env.example', "A=\nB=\n");

        $this->assertFalse(EnvironmentReport::of($this->tmpDir, [])['variables_truncated']);
    }

    public function test_an_empty_env_file_is_not_listed_as_a_file(): void
    {
        $this->write('.env', '');

        $report = EnvironmentReport::of($this->tmpDir, []);

        $this->assertSame([], $report['files']);
        $this->assertSame([], $report['variables']);
    }

    public function test_the_platforms_own_defaults_come_from_the_decision(): void
    {
        $report = EnvironmentReport::of($this->tmpDir, ['env' => ['PORT' => '8080']]);

        $this->assertSame(['PORT' => '8080'], $report['defaults']);
    }

    public function test_a_decision_with_no_env_reports_no_defaults(): void
    {
        $this->assertSame([], EnvironmentReport::of($this->tmpDir, ['env' => 'nonsense'])['defaults']);
        $this->assertSame([], EnvironmentReport::of($this->tmpDir, [])['defaults']);
    }

    public function test_a_project_with_no_env_files_reports_empty(): void
    {
        $report = EnvironmentReport::of($this->tmpDir, []);

        $this->assertSame([], $report['files']);
        $this->assertSame([], $report['variables']);
        $this->assertFalse($report['variables_truncated']);
    }
}
