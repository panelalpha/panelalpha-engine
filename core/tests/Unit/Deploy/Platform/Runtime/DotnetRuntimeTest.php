<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use PHPUnit\Framework\TestCase;

/**
 * .NET detection and the commands that follow from it.
 *
 * The awkward part of .NET is that nothing has a fixed name: the solution is
 * `Jellyfin.sln`, the project `Jellyfin.Server.csproj`, the built assembly
 * `jellyfin.dll`. So detection is a probe rather than a `detect` rule, and the
 * start command finds the entry assembly by the runtimeconfig the SDK writes
 * beside it rather than by guessing a name.
 */
class DotnetRuntimeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-dotnet-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    private function context(): ProjectContext
    {
        return ProjectContext::make($this->dir, ProjectContext::listRootFiles($this->dir));
    }

    public function test_a_repository_with_no_dotnet_files_is_not_claimed(): void
    {
        $this->write('README.md', '# nothing here');

        $this->assertFalse(DotnetRuntime::hasProject($this->dir));
        $this->assertNull((new DotnetRuntime())->resolve($this->context()));
    }

    private function solution(string ...$projects): string
    {
        $sln = "Microsoft Visual Studio Solution File, Format Version 12.00\n";
        foreach ($projects as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $sln .= "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"{$name}\", \"{$path}\", \"{11111111-2222-3333-4444-555555555555}\"\nEndProject\n";
        }

        return $sln;
    }

    public function test_a_solution_at_the_root_is_recognised(): void
    {
        $this->write('Jellyfin.sln', $this->solution('Jellyfin.Server\\Jellyfin.Server.csproj'));

        $this->assertTrue(DotnetRuntime::hasProject($this->dir));
    }

    /** Seafile: seafile.sln holds only a Visual C++ project, which the .NET SDK cannot build. */
    public function test_a_cpp_only_solution_is_not_claimed(): void
    {
        $this->write('seafile.sln', $this->solution('seafile.vcxproj', 'old\\old.vcproj'));
        $this->write('seafile.vcxproj', '<Project></Project>');

        $this->assertFalse(DotnetRuntime::hasProject($this->dir));
        $this->assertNull((new DotnetRuntime())->resolve($this->context()));
    }

    public function test_a_solution_with_no_projects_is_not_claimed(): void
    {
        $this->write('Empty.sln', $this->solution());

        $this->assertFalse(DotnetRuntime::hasProject($this->dir));
    }

    public function test_a_mixed_cpp_and_csharp_solution_is_recognised(): void
    {
        $this->write('Mixed.sln', $this->solution('native\\native.vcxproj', 'App\\App.csproj'));

        $this->assertTrue(DotnetRuntime::hasProject($this->dir));
    }

    public function test_an_slnx_solution_needs_a_managed_project(): void
    {
        $this->write('Native.slnx', '<Solution><Project Path="native/native.vcxproj" /></Solution>');
        $this->assertFalse(DotnetRuntime::hasProject($this->dir));

        $this->write('App.slnx', '<Solution><Project Path="src/App/App.fsproj" /></Solution>');
        $this->assertTrue(DotnetRuntime::hasProject($this->dir));
    }

    public function test_a_lone_project_file_is_recognised(): void
    {
        $this->write('App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertTrue(DotnetRuntime::hasProject($this->dir));
    }

    /**
     * The common layout: a solution at the top and the projects under a
     * directory. Looking only at the root would miss the target framework.
     */
    public function test_a_project_one_level_down_is_found(): void
    {
        $this->write('Jellyfin.Server/Jellyfin.Server.csproj', '<Project><TargetFramework>net8.0</TargetFramework></Project>');

        $this->assertTrue(DotnetRuntime::hasProject($this->dir));
        $this->assertSame('8.0', DotnetRuntime::targetFramework($this->dir));
    }

    public function test_the_highest_target_framework_in_a_solution_wins(): void
    {
        $this->write('A/A.csproj', '<Project><TargetFramework>net8.0</TargetFramework></Project>');
        $this->write('B/B.csproj', '<Project><TargetFramework>net9.0</TargetFramework></Project>');

        // The SDK that can build the newest can build the rest.
        $this->assertSame('9.0', DotnetRuntime::targetFramework($this->dir));
    }

    /** Library targets say nothing about which SDK to run. */
    public function test_legacy_and_netstandard_targets_are_ignored(): void
    {
        $this->write('Old/Old.csproj', '<Project><TargetFramework>netstandard2.0</TargetFramework></Project>');
        $this->write('Older/Older.csproj', '<Project><TargetFramework>net48</TargetFramework></Project>');

        $this->assertNull(DotnetRuntime::targetFramework($this->dir));
    }

    /** global.json pins an SDK and is meant literally, so it outranks the target. */
    public function test_global_json_outranks_the_target_framework(): void
    {
        $this->write('App/App.csproj', '<Project><TargetFramework>net8.0</TargetFramework></Project>');
        $this->write('global.json', '{"sdk":{"version":"9.0.101"}}');

        $requirement = (new DotnetRuntime())->resolve($this->context());

        $this->assertNotNull($requirement);
        $this->assertSame('9.0', $requirement->version);
        $this->assertSame('global.json sdk.version', $requirement->source);
    }

    public function test_a_project_with_no_readable_target_still_resolves_to_the_default(): void
    {
        $this->write('App.sln', $this->solution('App\\App.csproj'));

        $requirement = (new DotnetRuntime())->resolve($this->context());

        $this->assertNotNull($requirement);
        $this->assertSame(DotnetRuntime::VERSION, $requirement->version);
    }

    public function test_the_build_publishes_into_one_directory(): void
    {
        $build = DotnetRuntime::buildCommand($this->dir);

        $this->assertStringContainsString('dotnet publish', $build);
        $this->assertStringContainsString('-c Release', $build);
        $this->assertStringContainsString('-o ' . DotnetRuntime::PUBLISH_DIR, $build);
    }

    /**
     * Jellyfin's layout, and the failure that found this: publishing the
     * directory publishes Jellyfin.sln, whose 17 xUnit v3 test projects
     * hard-error on the global -p:UseAppHost=false the build used to pass.
     * Every runtime project published fine and the build still failed,
     * because MSBuild's exit code is the build's.
     */
    public function test_it_publishes_the_entry_project_not_the_solution(): void
    {
        $this->write('Jellyfin.sln', "Microsoft Visual Studio Solution File\n");
        $this->write(
            'Jellyfin.Server/Jellyfin.Server.csproj',
            '<Project Sdk="Microsoft.NET.Sdk.Web"><TargetFramework>net10.0</TargetFramework></Project>'
        );
        $this->write(
            'tests/Jellyfin.Api.Tests/Jellyfin.Api.Tests.csproj',
            '<Project Sdk="Microsoft.NET.Sdk"><OutputType>Exe</OutputType></Project>'
        );

        $this->assertSame('Jellyfin.Server/Jellyfin.Server.csproj', DotnetRuntime::entryProject($this->dir));

        $build = DotnetRuntime::buildCommand($this->dir);
        $this->assertStringContainsString('Jellyfin.Server/Jellyfin.Server.csproj', $build);
        $this->assertStringNotContainsString('.sln', $build);
    }

    /**
     * The flag that broke it. It is a global MSBuild property, so it reached
     * every project in the solution, and xUnit v3's targets treat it as an
     * error rather than a preference.
     */
    public function test_the_build_does_not_force_useapphost_off(): void
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertStringNotContainsString('UseAppHost', DotnetRuntime::buildCommand($this->dir));
    }

    /** A test project is an Exe under xUnit v3, so it must never be chosen. */
    public function test_a_test_project_is_never_the_entry_point(): void
    {
        $this->write('tests/Thing.Tests/Thing.Tests.csproj', '<Project><OutputType>Exe</OutputType></Project>');
        $this->write('Thing/Thing.csproj', '<Project Sdk="Microsoft.NET.Sdk"></Project>');

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
    }

    /** A console app with no web SDK is still an application. */
    public function test_an_executable_project_is_the_entry_point(): void
    {
        $this->write('src/Worker/Worker.csproj', '<Project Sdk="Microsoft.NET.Sdk"><OutputType>Exe</OutputType></Project>');

        $this->assertSame('src/Worker/Worker.csproj', DotnetRuntime::entryProject($this->dir));
    }

    public function test_a_project_whose_targets_run_npm_needs_node_in_its_build(): void
    {
        // Memtly.Core/Memtly.Core/Memtly.Core.csproj, verbatim target.
        $this->write('Memtly.Core/Memtly.Core/Memtly.Core.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web">'
            . '<Target Name="NpmInstall" BeforeTargets="BuildAssetsDebug;BuildAssetsProd">'
            . '<Exec Command="npm ci" WorkingDirectory="$(ProjectDir)" /></Target></Project>');
        $this->write('Memtly.Core/Memtly.Core/package.json', '{"engines":{"node":">=22"}}');

        $this->assertSame('node:22-bookworm-slim', DotnetRuntime::nodeBuildImage($this->dir));
    }

    public function test_node_run_from_a_shared_targets_file_counts_too(): void
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');
        $this->write('Directory.Build.targets', '<Project><Target Name="Ui"><Exec Command=" yarn build" /></Target></Project>');

        $this->assertNotNull(DotnetRuntime::nodeBuildImage($this->dir));
    }

    public function test_a_project_running_no_js_tool_needs_no_node(): void
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web">'
            . '<Target Name="Tool"><Exec Command="dotnet tool restore" /></Target></Project>');
        $this->write('App/package.json', '{}');

        $this->assertNull(DotnetRuntime::nodeBuildImage($this->dir));
    }

    /**
     * Prowlarr, Sonarr and Radarr: every project says `<TargetFrameworks>`
     * (plural, one entry), and publish without `-f` fails with NETSDK1129.
     */
    public function test_a_multi_target_project_is_published_for_one_framework(): void
    {
        $this->write(
            'src/NzbDrone.Console/Prowlarr.Console.csproj',
            '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup><OutputType>Exe</OutputType>'
            . '<TargetFrameworks>net8.0</TargetFrameworks></PropertyGroup></Project>'
        );

        $this->assertStringContainsString(
            "dotnet publish 'src/NzbDrone.Console/Prowlarr.Console.csproj' -f net8.0 -c Release",
            DotnetRuntime::buildCommand($this->dir)
        );
    }

    /**
     * Prowlarr's props read `$(SolutionDir)stylecop.json`; publishing only the
     * project left it undefined and every file failed SA1200 as an error.
     */
    public function test_a_project_inside_a_solution_is_published_with_its_solution_dir(): void
    {
        $this->write('src/Prowlarr.sln', $this->solution('NzbDrone.Console\\Prowlarr.Console.csproj'));
        $this->write('src/NzbDrone.Console/Prowlarr.Console.csproj', '<Project Sdk="Microsoft.NET.Sdk"><OutputType>Exe</OutputType></Project>');

        $this->assertSame('src', DotnetRuntime::solutionDir($this->dir, 'src/NzbDrone.Console/Prowlarr.Console.csproj'));
        $this->assertStringContainsString('-p:SolutionDir="$PWD/src/"', DotnetRuntime::buildCommand($this->dir));
    }

    public function test_a_solution_at_the_root_is_the_root(): void
    {
        $this->write('App.sln', $this->solution('App\\App.csproj'));
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertStringContainsString('-p:SolutionDir="$PWD/"', DotnetRuntime::buildCommand($this->dir));
    }

    public function test_a_project_outside_any_solution_sets_no_solution_dir(): void
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertNull(DotnetRuntime::solutionDir($this->dir, 'App/App.csproj'));
        $this->assertStringNotContainsString('SolutionDir', DotnetRuntime::buildCommand($this->dir));
    }

    public function test_the_newest_plain_framework_of_several_is_chosen(): void
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web">'
            . '<TargetFrameworks>netstandard2.0;net8.0;net9.0-windows;net9.0</TargetFrameworks></Project>');

        $this->assertSame('net9.0', DotnetRuntime::publishFramework($this->dir, 'App/App.csproj'));
    }

    public function test_a_single_framework_needs_no_flag(): void
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><TargetFramework>net8.0</TargetFramework></Project>');

        $this->assertNull(DotnetRuntime::publishFramework($this->dir, 'App/App.csproj'));
        $this->assertStringNotContainsString(' -f ', DotnetRuntime::buildCommand($this->dir));
    }

    public function test_frameworks_inherited_from_directory_build_props_are_honoured(): void
    {
        $this->write('src/Directory.Build.props', '<Project><PropertyGroup>'
            . '<TargetFrameworks>net8.0;net10.0</TargetFrameworks></PropertyGroup></Project>');
        $this->write('src/Api/Api.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertSame('net10.0', DotnetRuntime::publishFramework($this->dir, 'src/Api/Api.csproj'));
    }

    /**
     * Sonarr.SignalR uses the web SDK but declares itself a Library, and was
     * published instead of the console host.
     */
    public function test_a_web_sdk_library_is_not_the_entry_point(): void
    {
        $this->write('src/NzbDrone.SignalR/Sonarr.SignalR.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web">'
            . '<PropertyGroup><TargetFrameworks>net10.0</TargetFrameworks><OutputType>Library</OutputType></PropertyGroup></Project>');
        $this->write('src/NzbDrone.Console/Sonarr.Console.csproj', '<Project Sdk="Microsoft.NET.Sdk">'
            . '<PropertyGroup><OutputType>Exe</OutputType><TargetFrameworks>net10.0</TargetFrameworks></PropertyGroup></Project>');

        $this->assertSame('src/NzbDrone.Console/Sonarr.Console.csproj', DotnetRuntime::entryProject($this->dir));
    }

    /** Radarr.csproj is the Windows tray app; the SDK on Linux cannot build it. */
    public function test_a_windows_only_project_is_not_the_entry_point(): void
    {
        $this->write('src/NzbDrone/Radarr.csproj', '<Project Sdk="Microsoft.NET.Sdk.WindowsDesktop">'
            . '<PropertyGroup><OutputType>Exe</OutputType><TargetFrameworks>net8.0-windows</TargetFrameworks></PropertyGroup></Project>');

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
    }

    /**
     * A single-project repository needs no target: publishing the directory
     * is right there, and is what the command falls back to.
     */
    public function test_a_repository_with_no_recognisable_entry_publishes_the_directory(): void
    {
        $this->write('Lib/Lib.csproj', '<Project Sdk="Microsoft.NET.Sdk"></Project>');

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
        $this->assertStringContainsString('dotnet publish -c Release', DotnetRuntime::buildCommand($this->dir));
    }

    /**
     * The entry assembly is found by its runtimeconfig, because a publish
     * directory holds dozens of library DLLs and no naming rule separates
     * them.
     */
    public function test_the_start_command_finds_the_entry_assembly(): void
    {
        $start = DotnetRuntime::startCommand();

        $this->assertStringContainsString('runtimeconfig.json', $start);
        $this->assertStringContainsString('exec dotnet', $start);
        // A publish that produced nothing runnable says so instead of
        // restart-looping behind a 502 with an empty deploy log.
        $this->assertStringContainsString('PANELALPHA:', $start);
        $this->assertStringContainsString('exit 1', $start);
    }

    /** The shell in the start command has to be valid, since sh runs it. */
    public function test_the_start_command_is_valid_shell(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'pa-dotnet-start');
        file_put_contents($script, DotnetRuntime::startCommand());

        exec('sh -n ' . escapeshellarg($script) . ' 2>&1', $output, $status);
        unlink($script);

        $this->assertSame(0, $status, 'start command is not valid POSIX shell: ' . implode("\n", $output));
    }
}
