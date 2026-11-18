<?php

namespace Tests\Integration\Deploy\Inspect\Report;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Inspect\Report\DeployVerdict;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Findings\Finding;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the engine would do with a directory, as the report says it.
 *
 * The section is a promise to a caller who cannot see the host: every path is
 * relative to the project, every command is one that will really run, and
 * `deployable` is answered by the same check the deploy runs, and by what the
 * deploy would end up serving.
 */
class ApplicationReportTest extends ReportTestCase
{
    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function report(array $decision, ?string $issue = null, ?string $appConfigOrigin = null, ?AppConfig $appConfig = null): array
    {
        return (new ApplicationReport(
            $this->tmpDir,
            $this->context(),
            $decision + ['strategy' => Strategies::FALLBACK, 'label' => 'Unknown'],
            $appConfigOrigin,
            $appConfig,
            $issue === null ? null : new Finding(AppInspector::DETECTION_FAILED, $issue)
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

        $report = $this->report(['compose_path' => $this->tmpDir.'/docker-compose.yml']);

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

        $this->assertSame(['code' => AppInspector::DETECTION_FAILED, 'message' => 'Repository could not be cloned.'], $report['issue']);
        $this->assertFalse($report['deployable']);
    }

    public function test_a_strategy_missing_the_file_it_stands_on_is_not_deployable(): void
    {
        $this->write('README.md', '# no composer.json here');

        $report = $this->report(['strategy' => Strategies::LARAVEL]);

        $this->assertFalse($report['deployable']);
        $this->assertNotNull($report['issue']);
        $this->assertSame(DeployabilityCheck::ROOT_FILE_MISSING, $report['issue']['code']);
        $this->assertSame('composer.json', $report['issue']['file']);
        $this->assertStringContainsString('composer.json', $report['issue']['message']);
    }

    public function test_a_root_file_the_app_config_prepare_may_write_does_not_make_the_project_undeployable(): void
    {
        $this->write('README.md', '# composer.json is written into app/ by the recipe');

        $report = $this->report(
            ['strategy' => Strategies::PHP, 'app_root' => 'app'],
            appConfigOrigin: 'engine',
            appConfig: AppConfig::fromDirectory(['prepare' => "cp composer.json app/composer.json\n"])
        );

        $this->assertTrue($report['deployable']);
        $this->assertNull($report['issue']);
    }

    public function test_an_app_config_without_prepare_still_needs_the_root_file(): void
    {
        $this->write('README.md', '# no composer.json here');

        $report = $this->report(
            ['strategy' => Strategies::PHP, 'app_root' => 'app'],
            appConfigOrigin: 'engine',
            appConfig: AppConfig::fromDirectory(['entrypoint' => "#!/bin/sh\nexec apache2-foreground\n"])
        );

        $this->assertFalse($report['deployable']);
        $this->assertSame(DeployabilityCheck::ROOT_FILE_MISSING, $report['issue']['code']);
    }

    public function test_an_empty_directory_is_reported_as_undeployable(): void
    {
        $report = $this->report([]);

        $this->assertFalse($report['deployable']);
        $this->assertSame(DeployabilityCheck::PROJECT_EMPTY, $report['issue']['code']);
        $this->assertStringContainsString('empty', $report['issue']['message']);
    }

    public function test_a_project_the_check_accepts_is_deployable_with_no_issue(): void
    {
        $this->write('composer.json', '{}');

        $report = $this->report(['strategy' => Strategies::LARAVEL]);

        $this->assertSame(DeployVerdict::DEPLOYABLE, $report['verdict']);
        $this->assertTrue($report['deployable']);
        $this->assertNull($report['issue']);
    }

    public function test_a_refusal_is_not_deployable(): void
    {
        $report = $this->report([]);

        $this->assertSame(DeployVerdict::NOT_DEPLOYABLE, $report['verdict']);
    }

    /** The fallback serves "Project not configured", which is no deploy of the application. */
    public function test_a_project_nothing_recognises_is_the_placeholder_page_and_not_deployable(): void
    {
        $this->write('Makefile', "all:\n");
        $this->write('README', 'Linux kernel');

        $report = $this->report(['strategy' => Strategies::FALLBACK]);

        $this->assertSame(DeployVerdict::PLACEHOLDER, $report['verdict']);
        $this->assertFalse($report['deployable']);
        $this->assertSame(['code' => DeployVerdict::CODE_UNRECOGNISED, 'message' => DeployVerdict::UNRECOGNISED], $report['issue']);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function dotnetRepositoriesServingNothing(): array
    {
        $sln = "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"App\", \"App\\App.csproj\", \"{11111111-2222-3333-4444-555555555555}\"\nEndProject\n";

        return [
            'a WinForms app (Fictiverse/Redream)' => [['Redream.sln' => $sln, 'App/App.csproj' => '<Project Sdk="Microsoft.NET.Sdk">'
                . '<PropertyGroup><OutputType>WinExe</OutputType><TargetFramework>net6.0-windows</TargetFramework>'
                . '<UseWindowsForms>true</UseWindowsForms></PropertyGroup></Project>']],
            'a library (sqlkata/querybuilder)' => [['SqlKata.sln' => $sln, 'App/App.csproj' => '<Project Sdk="Microsoft.NET.Sdk">'
                . '<PropertyGroup><TargetFrameworks>netstandard2.0;net8.0</TargetFrameworks></PropertyGroup></Project>']],
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    #[DataProvider('dotnetRepositoriesServingNothing')]
    public function test_a_dotnet_repository_serving_nothing_is_one_no_strategy_can_run(array $files): void
    {
        foreach ($files as $file => $contents) {
            $this->write($file, $contents);
        }

        $report = $this->report(['strategy' => Strategies::FALLBACK]);

        $this->assertSame(DeployVerdict::PLACEHOLDER, $report['verdict']);
        $this->assertStringNotContainsString('may build', $report['issue']['message']);
        $this->assertSame([
            'code' => DeployVerdict::CODE_UNRECOGNISED,
            'message' => DeployVerdict::UNRECOGNISED . DeployVerdict::DOTNET_SERVES_NOTHING,
        ], $report['issue']);
        $this->assertSame([], $report['candidates']);
    }

    /** dotnet/maui: its sample servers are passed over for their framework, so "none serves HTTP" would not be true. */
    public function test_a_dotnet_server_passed_over_for_its_framework_is_named_as_the_reason(): void
    {
        $sample = 'src/Essentials/samples/Sample.Server.WebAuthenticator/Essentials.Sample.Server.WebAuthenticator.csproj';
        $this->write('Microsoft.Maui.sln', "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"Sample\", \""
            . str_replace('/', '\\', $sample) . "\", \"{1}\"\nEndProject\n");
        $this->write($sample, '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TargetFramework>$(_MauiDotNetTfm)</TargetFramework></PropertyGroup></Project>');

        $report = $this->report(['strategy' => Strategies::FALLBACK]);

        $this->assertSame(DeployVerdict::PLACEHOLDER, $report['verdict']);
        $this->assertSame(
            DeployVerdict::UNRECOGNISED . sprintf(DeployVerdict::DOTNET_FRAMEWORK_UNRESOLVED, $sample, '$(_MauiDotNetTfm)'),
            $report['issue']['message']
        );
        $this->assertStringNotContainsString('none serves HTTP', $report['issue']['message']);
        $this->assertSame([], $report['candidates']);
    }

    /** An inspection fetches no project file four folders down, so BootstrapAdmin's web project reads as empty. */
    public function test_a_dotnet_project_the_inspection_did_not_read_leaves_the_verdict_open(): void
    {
        $this->write('BootstrapAdmin.slnx', '<Solution><Project Path="src/blazor/admin/BootstrapAdmin.Web/BootstrapAdmin.Web.csproj" />'
            . '<Project Path="test/UnitTest/UnitTest.csproj" /></Solution>');
        $this->write('src/blazor/admin/BootstrapAdmin.Web/BootstrapAdmin.Web.csproj', '');
        $this->write('test/UnitTest/UnitTest.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<TargetFramework>net7.0</TargetFramework></PropertyGroup></Project>');

        $report = $this->report(['strategy' => Strategies::FALLBACK]);

        $this->assertSame(DeployVerdict::UNRECOGNISED . DeployVerdict::RAILPACK_MAY_BUILD, $report['issue']['message']);
        $this->assertSame(['railpack'], array_column($report['candidates'], 'id'));
    }

    /** moeru-ai/airi: a Godot C# engine beside a Node workspace, which Railpack may still build. */
    public function test_a_dotnet_project_serving_nothing_beside_another_toolchain_may_still_be_railpacks(): void
    {
        $this->write('package.json', '{"name":"airi","scripts":{"build":"vite build"}}');
        $this->write('engines/godot/Airi.csproj', '<Project Sdk="Godot.NET.Sdk/4.4.0"><PropertyGroup>'
            . '<TargetFramework>net8.0</TargetFramework></PropertyGroup></Project>');

        $report = $this->report(['strategy' => Strategies::FALLBACK]);

        $this->assertSame(DeployVerdict::UNRECOGNISED . DeployVerdict::RAILPACK_MAY_BUILD, $report['issue']['message']);
    }

    public function test_a_package_railpack_starts_nothing_from_is_the_placeholder_page(): void
    {
        $this->write('package.json', '{"name":"lint-config","scripts":{"lint":"eslint ."},"devDependencies":{"eslint":"^9"}}');

        $report = $this->report(['strategy' => Strategies::RAILPACK, 'label' => 'Railpack']);

        $this->assertSame(DeployVerdict::PLACEHOLDER, $report['verdict']);
        $this->assertFalse($report['deployable']);
        $this->assertSame(['code' => DeployVerdict::CODE_NOTHING_TO_START, 'message' => DeployVerdict::NOTHING_TO_START], $report['issue']);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function packagesRailpackMayStart(): array
    {
        $lint = '{"name":"pkg","scripts":{"lint":"eslint ."}}';

        return [
            'main names the entry' => [['package.json' => '{"name":"lib","main":"lib.js"}', 'lib.js' => '']],
            'a root index.ts' => [['package.json' => $lint, 'index.ts' => '']],
            'a build that may write pages' => [['package.json' => '{"name":"site","scripts":{"build":"gulp"}}']],
            'pages committed under dist/' => [['package.json' => $lint, 'dist/index.html' => '<title>x</title>']],
            'an Nx workspace' => [['package.json' => $lint, 'nx.json' => '{}']],
            'another runtime Railpack builds first' => [['package.json' => $lint, 'deno.json' => '{}', 'main.ts' => '']],
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    #[DataProvider('packagesRailpackMayStart')]
    public function test_a_package_without_a_start_script_that_may_still_start_deploys_with_a_warning(array $files): void
    {
        foreach ($files as $file => $contents) {
            $this->write($file, $contents);
        }

        $report = $this->report(['strategy' => Strategies::RAILPACK, 'label' => 'Railpack']);

        $this->assertSame(DeployVerdict::NO_START_COMMAND, $report['verdict']);
        $this->assertTrue($report['deployable']);
        $this->assertNull($report['issue']);
    }

    public function test_railpack_with_a_start_script_is_deployable(): void
    {
        $this->write('package.json', '{"name":"koa-app","scripts":{"start":"node app.mjs"}}');

        $report = $this->report(['strategy' => Strategies::RAILPACK, 'label' => 'Railpack']);

        $this->assertSame(DeployVerdict::DEPLOYABLE, $report['verdict']);
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
