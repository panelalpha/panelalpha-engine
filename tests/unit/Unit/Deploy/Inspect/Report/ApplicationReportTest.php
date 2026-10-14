<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Strategies;

/**
 * What the engine would do with a directory, as the report says it.
 *
 * The section is a promise to a caller who cannot see the host: every path is
 * relative to the project, every command is one that will really run, and
 * `deployable` is answered by the same check the deploy runs rather than by
 * an opinion formed here.
 */
class ApplicationReportTest extends ReportTestCase
{
    /**
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private function report(array $decision, ?string $issue = null, ?string $appConfigOrigin = null): array
    {
        return (new ApplicationReport(
            $this->tmpDir,
            $this->context(),
            $decision + ['strategy' => Strategies::FALLBACK, 'label' => 'Unknown'],
            $appConfigOrigin,
            null,
            $issue
        ))->build();
    }

    public function test_the_undeployable_decision_is_the_shape_the_report_expects(): void
    {
        $decision = ApplicationReport::undeployable();

        $this->assertSame(Strategies::FALLBACK, $decision['strategy']);
        $this->assertSame('Unknown', $decision['label']);
        $this->assertNull($decision['compose_path']);
        $this->assertNull($decision['runtime']);
    }

    /**
     * An absolute host path in an API response tells the caller about the
     * server rather than about their project.
     */
    public function test_the_compose_file_is_reported_relative_to_the_project(): void
    {
        $this->write('docker-compose.yml', "services: {}\n");

        $report = $this->report(['compose_path' => $this->tmpDir . '/docker-compose.yml']);

        $this->assertSame('docker-compose.yml', $report['compose_file']);
    }

    public function test_a_compose_path_outside_the_project_is_left_as_it_is(): void
    {
        $report = $this->report(['compose_path' => '/etc/panelalpha/shared-compose.yml']);

        $this->assertSame('/etc/panelalpha/shared-compose.yml', $report['compose_file']);
    }

    public function test_a_project_with_no_compose_reports_none(): void
    {
        $this->assertNull($this->report([])['compose_file']);
    }

    /**
     * Detection rejecting the project is a better answer than anything this
     * section could work out afterwards, so it is not second-guessed.
     */
    public function test_an_issue_detection_already_found_outranks_the_local_check(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report(
            ['strategy' => Strategies::LARAVEL],
            'Repository could not be cloned.'
        );

        $this->assertSame('Repository could not be cloned.', $report['issue']);
        $this->assertFalse($report['deployable']);
    }

    public function test_a_strategy_missing_the_file_it_stands_on_is_not_deployable(): void
    {
        $this->write('README.md', '# no composer.json here');

        $report = $this->report(['strategy' => Strategies::LARAVEL]);

        $this->assertFalse($report['deployable']);
        $this->assertNotNull($report['issue']);
        $this->assertStringContainsString('composer.json', (string) $report['issue']);
    }

    public function test_an_empty_directory_is_reported_as_undeployable(): void
    {
        $report = $this->report([]);

        $this->assertFalse($report['deployable']);
        $this->assertStringContainsString('empty', (string) $report['issue']);
    }

    public function test_a_project_the_check_accepts_is_deployable_with_no_issue(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report(['strategy' => Strategies::LARAVEL]);

        $this->assertTrue($report['deployable']);
        $this->assertNull($report['issue']);
    }

    /**
     * "php 8.3" is a fact nobody can check; "php 8.3, from composer.json" is
     * one they can go and read. The source field is the point of the section.
     */
    public function test_the_toolchain_says_where_each_version_came_from(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report([
            'strategy' => Strategies::LARAVEL,
            'requirements' => [new Requirement('php', '8.3', '^8.3', 'composer.json require.php')],
        ]);

        $this->assertSame([[
            'id' => 'php',
            'version' => '8.3',
            'constraint' => '^8.3',
            'source' => 'composer.json require.php',
            'role' => Requirement::ROLE_RUNTIME,
        ]], $report['toolchain']);
    }

    public function test_anything_that_is_not_a_requirement_is_left_out_of_the_toolchain(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report([
            'strategy' => Strategies::LARAVEL,
            'requirements' => ['php 8.3', null, new Requirement('node', '22', '22', '.nvmrc')],
        ]);

        $this->assertSame(['node'], array_column($report['toolchain'], 'id'));
    }

    public function test_a_decision_with_no_requirements_reports_an_empty_toolchain(): void
    {
        $this->write('composer.json', '{}');

        $this->assertSame([], $this->report(['strategy' => Strategies::LARAVEL])['toolchain']);
    }

    public function test_a_command_that_is_only_whitespace_is_reported_as_none(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report([
            'strategy' => Strategies::LARAVEL,
            'install_command' => 'composer install',
            'build_command' => '   ',
        ]);

        $this->assertSame('composer install', $report['commands']['install']);
        $this->assertNull($report['commands']['build']);
        $this->assertNull($report['commands']['start']);
    }

    public function test_the_package_manager_the_decision_declared_wins(): void
    {
        $this->write('package.json', '{}');
        $this->write('package-lock.json', '{}');

        $report = $this->report(['package_manager' => 'pnpm']);

        $this->assertSame('pnpm', $report['package_manager']);
    }

    public function test_a_package_manager_nobody_declared_is_read_from_the_lockfile(): void
    {
        $this->write('package.json', '{}');
        $this->write('pnpm-lock.yaml', '');

        $this->assertSame('pnpm', $this->report([])['package_manager']);
    }

    public function test_a_project_without_a_package_json_has_no_package_manager(): void
    {
        $this->write('composer.json', '{}');

        $this->assertNull($this->report(['strategy' => Strategies::LARAVEL])['package_manager']);
    }

    public function test_the_app_config_origin_is_passed_through_as_given(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report(['strategy' => Strategies::LARAVEL], null, 'repository');

        $this->assertSame('repository', $report['app_config']);
    }
}
