<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Detect\DeployRefusal;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\DotnetProjectProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** ArgoZhang/BootstrapAdmin: the web project is four folders down, and only BootstrapAdmin.slnx names it. */
    public function test_a_web_project_deeper_down_is_found_through_the_root_solution(): void
    {
        $this->write('BootstrapAdmin.slnx', '<Solution><Folder Name="/src/blazor/admin/">'
            . '<Project Path="src/blazor/admin/BootstrapAdmin.Caching/BootstrapAdmin.Caching.csproj" />'
            . '<Project Path="src/blazor/admin/BootstrapAdmin.Web/BootstrapAdmin.Web.csproj" />'
            . '</Folder><File Path="README.md" /></Solution>');
        $this->write('src/blazor/admin/BootstrapAdmin.Caching/BootstrapAdmin.Caching.csproj', '<Project Sdk="Microsoft.NET.Sdk"></Project>');
        $this->write('src/blazor/admin/BootstrapAdmin.Web/BootstrapAdmin.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');
        $this->write('test/UnitTest/UnitTest.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<TargetFramework>net7.0</TargetFramework></PropertyGroup></Project>');

        $this->assertSame('src/blazor/admin/BootstrapAdmin.Web/BootstrapAdmin.Web.csproj', DotnetRuntime::entryProject($this->dir));
        $this->assertTrue((new DotnetProjectProbe())->evaluate($this->context()));
        $this->assertStringContainsString(
            "dotnet publish 'src/blazor/admin/BootstrapAdmin.Web/BootstrapAdmin.Web.csproj' -p:SolutionDir=\"\$PWD/\"",
            DotnetRuntime::buildCommand($this->dir)
        );
    }

    public function test_a_backslashed_solution_path_is_read_as_a_path(): void
    {
        $this->write('Shop.sln', $this->solution('src\\server\\web\\Shop.Web\\Shop.Web.csproj'));
        $this->write('src/server/web/Shop.Web/Shop.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertSame('src/server/web/Shop.Web/Shop.Web.csproj', DotnetRuntime::entryProject($this->dir));
    }

    public function test_a_solution_path_leading_out_of_the_repository_is_not_followed(): void
    {
        $this->write('repo/App.sln', $this->solution('..\\outside\\Evil.Web\\Evil.Web.csproj', 'a\\..\\..\\outside\\Evil.Web\\Evil.Web.csproj'));
        $this->write('outside/Evil.Web/Evil.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertSame(['App.sln'], DotnetRuntime::findProjectFiles($this->dir . '/repo'));
        $this->assertNull(DotnetRuntime::entryProject($this->dir . '/repo'));
    }

    public function test_a_project_within_reach_stays_ahead_of_a_deeper_one_the_solution_lists(): void
    {
        $this->write('App.sln', $this->solution('aaa\\samples\\web\\Sample.Web\\Sample.Web.csproj', 'src\\App.Web\\App.Web.csproj'));
        $this->write('aaa/samples/web/Sample.Web/Sample.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');
        $this->write('src/App.Web/App.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertSame('src/App.Web/App.Web.csproj', DotnetRuntime::entryProject($this->dir));
    }

    /**
     * dotnet/maui: its solution lists a sample server whose framework only the repository's own build sets
     * (`$(_MauiDotNetTfm)`), so the 8.0 default SDK would have built a framework repository's sample.
     */
    public function test_a_web_project_targeting_only_a_property_is_not_the_entry_point(): void
    {
        $this->write('Microsoft.Maui.sln', $this->solution(
            'src\\Essentials\\samples\\Sample.Server.WebAuthenticator\\Essentials.Sample.Server.WebAuthenticator.csproj',
            'src\\Essentials\\samples\\Samples.Server.Passkeys\\Essentials.Samples.Server.Passkeys.csproj',
        ));
        $this->write(
            'src/Essentials/samples/Sample.Server.WebAuthenticator/Essentials.Sample.Server.WebAuthenticator.csproj',
            '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFramework>$(_MauiDotNetTfm)</TargetFramework></PropertyGroup></Project>'
        );
        $this->write(
            'src/Essentials/samples/Samples.Server.Passkeys/Essentials.Samples.Server.Passkeys.csproj',
            '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFrameworks>net$(_MauiDotNetVersion);$(MauiPlatforms)</TargetFrameworks></PropertyGroup></Project>'
        );

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
        $this->assertSame([
            'file' => 'src/Essentials/samples/Sample.Server.WebAuthenticator/Essentials.Sample.Server.WebAuthenticator.csproj',
            'framework' => '$(_MauiDotNetTfm)',
        ], DotnetRuntime::unresolvedFrameworkProject($this->dir));
    }

    public function test_a_library_or_window_targeting_only_a_property_is_no_server_passed_over(): void
    {
        $this->write('src/Core/Core.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<TargetFrameworks>$(MauiPlatforms)</TargetFrameworks></PropertyGroup></Project>');
        $this->write('src/Sample/Sample.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>WinExe</OutputType><TargetFramework>$(_MauiDotNetTfm)</TargetFramework></PropertyGroup></Project>');

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertNull(DotnetRuntime::unresolvedFrameworkProject($this->dir));
    }

    public function test_a_web_project_naming_a_framework_beside_a_property_is_the_entry_point(): void
    {
        $this->write('Web/Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TargetFrameworks>net8.0;$(ExtraFrameworks)</TargetFrameworks></PropertyGroup></Project>');

        $this->assertSame('Web/Web.csproj', DotnetRuntime::entryProject($this->dir));
    }

    /** vpnhood/VpnHood: its browser UI is a WebAssembly page the app's own server hands out, not a server. */
    public function test_a_webassembly_project_is_not_the_entry_point(): void
    {
        $this->write('src/VpnHood.AppUi.Browser/VpnHood.AppUi.Browser.csproj', '<Project Sdk="Microsoft.NET.Sdk.WebAssembly">'
            . '<PropertyGroup><TargetFramework>net10.0-browser</TargetFramework></PropertyGroup></Project>');

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
    }

    public function test_the_web_sdk_is_recognised_however_the_project_names_it(): void
    {
        $this->write('A/A.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web/9.0.100"></Project>');
        $this->assertSame('A/A.csproj', DotnetRuntime::entryProject($this->dir));

        unlink($this->dir . '/A/A.csproj');
        $this->write('B/B.csproj', '<Project><Sdk Name="Microsoft.NET.Sdk.Web" /></Project>');
        $this->assertSame('B/B.csproj', DotnetRuntime::entryProject($this->dir));
    }

    public function test_the_highest_target_framework_in_a_solution_wins(): void
    {
        $this->write('A/A.csproj', '<Project><TargetFramework>net8.0</TargetFramework></Project>');
        $this->write('B/B.csproj', '<Project><TargetFramework>net9.0</TargetFramework></Project>');

        // The SDK that can build the newest can build the rest.
        $this->assertSame('9.0', DotnetRuntime::targetFramework($this->dir));
    }

    /**
     * ArgoZhang/BootstrapAdmin: its web project names no framework and builds for src/Directory.Build.props'
     * net10.0, so the stale net7.0 test project alone picked an SDK that cannot build it.
     */
    public function test_a_project_naming_no_framework_builds_for_its_directory_build_props_one(): void
    {
        $this->write('test/UnitTest/UnitTest.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<TargetFramework>net7.0</TargetFramework></PropertyGroup></Project>');
        $this->write('src/Directory.Build.props', '<Project><PropertyGroup><TargetFramework>net10.0</TargetFramework></PropertyGroup></Project>');
        $this->write('src/Admin.Web/Admin.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"></Project>');

        $this->assertSame('10.0', DotnetRuntime::targetFramework($this->dir));
        $this->assertSame('10.0', (new DotnetRuntime())->resolve($this->context())?->version);
    }

    public function test_a_classic_project_does_not_take_a_framework_from_directory_build_props(): void
    {
        $this->write('Directory.Build.props', '<Project><PropertyGroup><TargetFramework>net9.0</TargetFramework></PropertyGroup></Project>');
        $this->write('Old/Old.csproj', '<Project ToolsVersion="15.0"><PropertyGroup>'
            . '<TargetFrameworkVersion>v4.8</TargetFrameworkVersion></PropertyGroup></Project>');

        $this->assertNull(DotnetRuntime::targetFramework($this->dir));
    }

    /** Library targets say nothing about which SDK to run. */
    public function test_legacy_and_netstandard_targets_are_ignored(): void
    {
        $this->write('Old/Old.csproj', '<Project><TargetFramework>netstandard2.0</TargetFramework></Project>');
        $this->write('Older/Older.csproj', '<Project><TargetFramework>net48</TargetFramework></Project>');

        $this->assertNull(DotnetRuntime::targetFramework($this->dir));
    }

    /** Emby: a NuGet HintPath `sqlite3.net45.1.1.11` is not a target framework (it read as sdk:45.1). */
    public function test_only_target_framework_elements_are_read(): void
    {
        $this->write('Server/Server.csproj', <<<'XML'
<Project ToolsVersion="15.0">
  <PropertyGroup><TargetFrameworkVersion>v4.7</TargetFrameworkVersion></PropertyGroup>
  <Reference Include="SQLitePCLRaw.provider.sqlite3">
    <HintPath>..\packages\SQLitePCLRaw.provider.sqlite3.net45.1.1.11\lib\net45\SQLitePCLRaw.provider.sqlite3.dll</HintPath>
  </Reference>
</Project>
XML);
        $this->write('Lib/Lib.csproj', '<Project Sdk="Microsoft.NET.Sdk"><TargetFrameworks>netstandard2.0;net8.0-windows;net9.0</TargetFrameworks></Project>');

        $this->assertSame('9.0', DotnetRuntime::targetFramework($this->dir));

        unlink($this->dir . '/Lib/Lib.csproj');
        $this->assertNull(DotnetRuntime::targetFramework($this->dir));
        $this->assertSame(DotnetRuntime::VERSION, (new DotnetRuntime())->resolve($this->context())?->version);
    }

    /** A classic .NET Framework app cannot be published by the Linux SDK; that is said before the build. */
    public function test_a_classic_dotnet_framework_entry_project_is_refused(): void
    {
        $this->write('Server/Server.csproj', '<Project ToolsVersion="15.0"><PropertyGroup>'
            . '<OutputType>Exe</OutputType><TargetFrameworkVersion>v4.7</TargetFrameworkVersion></PropertyGroup></Project>');

        $this->assertSame(
            ['file' => 'Server/Server.csproj', 'framework' => 'v4.7', 'sdk_style' => false],
            DotnetRuntime::legacyFrameworkEntry($this->dir)
        );

        try {
            DeployabilityCheck::assert(['strategy' => Strategies::DOTNET], $this->dir);
            $this->fail('A .NET Framework entry project was not refused.');
        } catch (DeployRefusal $refusal) {
            $this->assertSame(DeployabilityCheck::DOTNET_PROJECT_LEGACY, $refusal->refusal);
            $this->assertStringStartsWith('Server/Server.csproj targets .NET Framework v4.7 (a <TargetFrameworkVersion> project).', $refusal->getMessage());
            $this->assertSame(['file' => 'Server/Server.csproj'], $refusal->facts);
        }
    }

    public function test_an_sdk_style_entry_project_is_deployable(): void
    {
        $this->write('Server/Server.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TargetFramework>net8.0</TargetFramework></PropertyGroup></Project>');

        $this->assertNull(DotnetRuntime::legacyFrameworkEntry($this->dir));
        DeployabilityCheck::assert(['strategy' => Strategies::DOTNET], $this->dir);
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

    /** dotnet/maui: an Arcade build pins its SDK under tools.dotnet and leaves sdk.version out. */
    public function test_global_json_tools_dotnet_pins_the_sdk_when_sdk_version_is_absent(): void
    {
        $this->write('Microsoft.Maui.sln', $this->solution('src\\Core\\Core.csproj'));
        $this->write('src/Core/Core.csproj', '<Project><PropertyGroup><TargetFramework>$(_MauiDotNetTfm)</TargetFramework></PropertyGroup></Project>');
        $this->write('global.json', '{"tools":{"dotnet":"11.0.100-rtm.26514.118"},"sdk":{"paths":[".dotnet","$host$"]}}');

        $requirement = (new DotnetRuntime())->resolve($this->context());

        $this->assertNotNull($requirement);
        $this->assertSame('11.0', $requirement->version);
        $this->assertSame('11.0.100-rtm.26514.118', $requirement->constraint);
        $this->assertSame('global.json tools.dotnet', $requirement->source);
    }

    public function test_global_json_sdk_version_outranks_tools_dotnet(): void
    {
        $this->write('App/App.csproj', '<Project><TargetFramework>net8.0</TargetFramework></Project>');
        $this->write('global.json', '{"sdk":{"version":"9.0.101"},"tools":{"dotnet":"10.0.100"}}');

        $this->assertSame('global.json sdk.version', (new DotnetRuntime())->resolve($this->context())?->source);
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
     * Flink sets TreatWarningsAsErrors and restore died on `error NU1903: Warning
     * As Error` for a transitive package. The audit codes are appended to the
     * project's own WarningsNotAsErrors from a targets file; `-p:WarningsNotAsErrors`
     * would replace that list (measured: the project's CS0618 became an error again).
     */
    public function test_nuget_audit_warnings_are_not_errors_and_the_projects_own_list_is_kept(): void
    {
        $this->write('Flink/Flink.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TreatWarningsAsErrors>true</TreatWarningsAsErrors></PropertyGroup></Project>');

        $build = DotnetRuntime::buildCommand($this->dir);

        $this->assertStringStartsWith(
            "echo '<Project><PropertyGroup><WarningsNotAsErrors>\$(WarningsNotAsErrors);NU1900;NU1901;NU1902;NU1903;NU1904"
                . "</WarningsNotAsErrors></PropertyGroup></Project>' > /tmp/panelalpha-nuget-audit.targets && dotnet publish 'Flink/Flink.csproj'",
            $build
        );
        $this->assertStringEndsWith(' -p:CustomAfterMicrosoftCommonTargets=/tmp/panelalpha-nuget-audit.targets', $build);
        $this->assertStringNotContainsString('-p:WarningsNotAsErrors', $build);
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

    /** A console app hosting ASP.NET Core without the web SDK is still the application. */
    public function test_an_executable_project_is_the_entry_point(): void
    {
        $this->write('src/Worker/Worker.csproj', '<Project Sdk="Microsoft.NET.Sdk"><OutputType>Exe</OutputType>'
            . '<ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>');

        $this->assertSame('src/Worker/Worker.csproj', DotnetRuntime::entryProject($this->dir));
    }

    /**
     * dockersamples/example-voting-app: worker/Worker.csproj, verbatim. It moves
     * votes from Redis to Postgres and listens on nothing, so publishing it as the
     * app deployed something that could never answer.
     */
    public function test_a_console_worker_is_not_the_entry_point_and_the_repo_is_not_claimed(): void
    {
        $this->write('worker/Worker.csproj', '<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <OutputType>Exe</OutputType>
    <TargetFramework>net7.0</TargetFramework>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="StackExchange.Redis" Version="2.2.4" />
    <PackageReference Include="Npgsql" Version="4.1.9" />
    <PackageReference Include="Newtonsoft.Json" Version="13.0.1" />
  </ItemGroup>
</Project>');

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
    }

    /**
     * Fictiverse/Redream: a WinForms app the Linux SDK cannot build. Claimed, the
     * build ran `dotnet publish` with no project and failed on the solution.
     */
    public function test_a_windows_desktop_app_is_not_claimed(): void
    {
        $this->write('Redream.sln', $this->solution('Redream\\Redream.csproj'));
        $this->write('Redream/Redream.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>WinExe</OutputType><TargetFramework>net6.0-windows</TargetFramework>'
            . '<UseWindowsForms>true</UseWindowsForms></PropertyGroup></Project>');

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
    }

    /** Avalonia builds on Linux, but a window still has no port to answer on. */
    public function test_a_cross_platform_desktop_app_is_not_claimed(): void
    {
        $this->write('YoutubeDownloader/YoutubeDownloader.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>WinExe</OutputType><TargetFramework>net10.0</TargetFramework></PropertyGroup>'
            . '<ItemGroup><PackageReference Include="Avalonia" Version="11.3.8" /></ItemGroup></Project>');

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
    }

    /**
     * kitsuned/PotatoNV: a .NET Framework WPF app is refused with the reason, not built, once nothing else claims
     * the repository.
     */
    public function test_a_classic_dotnet_framework_desktop_app_is_refused(): void
    {
        $this->write('PotatoNV-next.sln', $this->solution('PotatoNV-next\\PotatoNV-next.csproj'));
        $this->write('PotatoNV-next/PotatoNV-next.csproj', '<Project ToolsVersion="15.0"><PropertyGroup>'
            . '<OutputType>WinExe</OutputType><TargetFrameworkVersion>v4.7.2</TargetFrameworkVersion></PropertyGroup></Project>');

        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
        $this->assertSame(
            ['file' => 'PotatoNV-next/PotatoNV-next.csproj', 'framework' => 'v4.7.2', 'sdk_style' => false],
            DotnetRuntime::legacyFrameworkEntry($this->dir),
        );

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame(Strategies::FALLBACK, $decision['strategy']);
        try {
            DeployabilityCheck::assert($decision, $this->dir);
            $this->fail('A .NET Framework desktop app nothing else claims was not refused.');
        } catch (DeployRefusal $refusal) {
            $this->assertSame(DeployabilityCheck::DOTNET_PROJECT_LEGACY, $refusal->refusal);
            $this->assertSame(['file' => 'PotatoNV-next/PotatoNV-next.csproj'], $refusal->facts);
        }
    }

    /** sqlkata/querybuilder: a library and its tests, with nothing to start. */
    public function test_a_repository_of_libraries_is_not_claimed(): void
    {
        $this->write('SqlKata.sln', $this->solution('QueryBuilder\\QueryBuilder.csproj', 'QueryBuilder.Tests\\QueryBuilder.Tests.csproj'));
        $this->write('QueryBuilder/QueryBuilder.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<TargetFrameworks>netstandard2.0;net8.0</TargetFrameworks></PropertyGroup></Project>');
        $this->write('QueryBuilder.Tests/QueryBuilder.Tests.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<TargetFramework>net8.0</TargetFramework></PropertyGroup></Project>');

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertFalse((new DotnetProjectProbe())->evaluate($this->context()));
    }

    private function classicAspNetProject(string $version, string $import): string
    {
        return '<Project ToolsVersion="12.0" DefaultTargets="Build"><PropertyGroup>'
            . '<ProjectTypeGuids>{349c5851-65df-11da-9384-00065b846f21};{fae04ec0-301f-11d3-bf4b-00c04f79efbc}</ProjectTypeGuids>'
            . "<OutputType>Library</OutputType><TargetFrameworkVersion>{$version}</TargetFrameworkVersion></PropertyGroup>"
            . '<ItemGroup><Reference Include="System.Web" /></ItemGroup>'
            . "<Import Project=\"{$import}\" Condition=\"'\$(VSToolsPath)' != ''\" /></Project>";
    }

    /**
     * jakubgarfield/Bonobo-Git-Server: an ASP.NET MVC 5 app is a Library IIS loads, so it
     * got the placeholder page instead of the reason it cannot run here.
     */
    public function test_a_classic_aspnet_app_is_refused_with_the_dotnet_framework_reason(): void
    {
        $this->write('Bonobo.Git.Server.sln', $this->solution('Bonobo.Git.Server\\Bonobo.Git.Server.csproj'));
        $this->write('Bonobo.Git.Server/Bonobo.Git.Server.csproj', $this->classicAspNetProject(
            'v4.6',
            '$(VSToolsPath)\\WebApplications\\Microsoft.WebApplication.targets'
        ));

        $this->assertSame(
            ['file' => 'Bonobo.Git.Server/Bonobo.Git.Server.csproj', 'framework' => 'v4.6', 'sdk_style' => false],
            DotnetRuntime::legacyFrameworkEntry($this->dir),
        );

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame(Strategies::FALLBACK, $decision['strategy']);

        try {
            DeployabilityCheck::assert($decision, $this->dir);
            $this->fail('A classic ASP.NET app was not refused.');
        } catch (DeployRefusal $refusal) {
            $this->assertSame(DeployabilityCheck::DOTNET_PROJECT_LEGACY, $refusal->refusal);
            $this->assertStringStartsWith('Bonobo.Git.Server/Bonobo.Git.Server.csproj targets .NET Framework v4.6', $refusal->getMessage());
            $this->assertSame(['file' => 'Bonobo.Git.Server/Bonobo.Git.Server.csproj'], $refusal->facts);
        }
    }

    /** OrchardCMS/Orchard: Orchard.Framework uses System.Web too, but only Orchard.Web is the application. */
    public function test_a_dotnet_framework_library_using_system_web_is_not_the_classic_app(): void
    {
        $this->write('src/Orchard/Orchard.Framework.csproj', '<Project ToolsVersion="12.0"><PropertyGroup>'
            . '<OutputType>Library</OutputType><TargetFrameworkVersion>v4.8</TargetFrameworkVersion></PropertyGroup>'
            . '<ItemGroup><Reference Include="System.Web" /></ItemGroup></Project>');
        $this->assertNull(DotnetRuntime::legacyFrameworkEntry($this->dir));

        $this->write('src/Orchard.Web/Orchard.Web.csproj', $this->classicAspNetProject(
            'v4.8',
            '$(MSBuildExtensionsPath32)\\Microsoft\\VisualStudio\\v10.0\\WebApplications\\Microsoft.WebApplication.targets'
        ));
        $this->assertSame(
            ['file' => 'src/Orchard.Web/Orchard.Web.csproj', 'framework' => 'v4.8', 'sdk_style' => false],
            DotnetRuntime::legacyFrameworkEntry($this->dir)
        );
    }

    /** @return array<string, array{0: string}> */
    public static function sdkStyleFrameworkWebProjects(): array
    {
        return [
            'MSBuild.SDK.SystemWeb' => ['<Project Sdk="MSBuild.SDK.SystemWeb/4.0.88"><PropertyGroup>'
                . '<TargetFramework>net48</TargetFramework></PropertyGroup></Project>'],
            'its SDK named in an element' => ['<Project><Sdk Name="MSBuild.SDK.SystemWeb" Version="4.0.88" /><PropertyGroup>'
                . '<TargetFrameworks>net472;net48</TargetFrameworks></PropertyGroup></Project>'],
            'ASP.NET Core on .NET Framework' => ['<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
                . '<TargetFramework>net48</TargetFramework></PropertyGroup></Project>'],
        ];
    }

    /** An SDK-style web project for .NET Framework has no <TargetFrameworkVersion> and no WebApplication.targets import. */
    #[DataProvider('sdkStyleFrameworkWebProjects')]
    public function test_an_sdk_style_dotnet_framework_web_app_is_refused_with_the_dotnet_framework_reason(string $project): void
    {
        $this->write('Shop.sln', $this->solution('src\\Shop.Web\\Shop.Web.csproj'));
        $this->write('src/Shop.Web/Shop.Web.csproj', $project);

        $this->assertNull(DotnetRuntime::entryProject($this->dir));
        $this->assertSame(
            ['file' => 'src/Shop.Web/Shop.Web.csproj', 'framework' => 'v4.8', 'sdk_style' => true],
            DotnetRuntime::legacyFrameworkEntry($this->dir),
        );

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame(Strategies::FALLBACK, $decision['strategy']);

        try {
            DeployabilityCheck::assert($decision, $this->dir);
            $this->fail('An SDK-style .NET Framework web app was not refused.');
        } catch (DeployRefusal $refusal) {
            $this->assertSame(DeployabilityCheck::DOTNET_PROJECT_LEGACY, $refusal->refusal);
            $this->assertStringStartsWith('src/Shop.Web/Shop.Web.csproj targets .NET Framework v4.8. The .NET SDK', $refusal->getMessage());
            $this->assertSame(['file' => 'src/Shop.Web/Shop.Web.csproj'], $refusal->facts);
        }
    }

    public function test_an_sdk_style_dotnet_framework_web_app_beside_a_dotnet_one_changes_nothing(): void
    {
        $this->write('Legacy.Web/Legacy.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TargetFramework>net461</TargetFramework></PropertyGroup></Project>');
        $this->write('Shop.Web/Shop.Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TargetFrameworks>net48;net8.0</TargetFrameworks></PropertyGroup></Project>');

        $this->assertSame('Shop.Web/Shop.Web.csproj', DotnetRuntime::entryProject($this->dir));
        $this->assertNull(DotnetRuntime::legacyFrameworkEntry($this->dir));
    }

    /** iterate-ch/cyberduck: an SDK-style .NET Framework window beside a Java app serves nothing and is not claimed. */
    public function test_an_sdk_style_dotnet_framework_desktop_app_is_left_to_later_strategies(): void
    {
        $this->write('windows/src/main/csharp/Cyberduck.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>WinExe</OutputType><TargetFramework>net472</TargetFramework></PropertyGroup></Project>');
        $this->write('Cyberduck.sln', $this->solution('windows\\src\\main\\csharp\\Cyberduck.csproj'));

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertNull(DotnetRuntime::legacyFrameworkEntry($this->dir));
    }

    /** prometheus-net keeps a .NET Framework sample beside the ASP.NET Core one it deploys. */
    public function test_a_classic_aspnet_app_beside_a_web_entry_changes_nothing(): void
    {
        $this->write('Sample.Grpc/Sample.Grpc.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup>'
            . '<TargetFramework>net8.0</TargetFramework></PropertyGroup></Project>');
        $this->write('Sample.Web.NetFramework/Sample.Web.NetFramework.csproj', $this->classicAspNetProject(
            'v4.6.2',
            '$(VSToolsPath)\\WebApplications\\Microsoft.WebApplication.targets'
        ));

        $this->assertSame('Sample.Grpc/Sample.Grpc.csproj', DotnetRuntime::entryProject($this->dir));
        $this->assertNull(DotnetRuntime::legacyFrameworkEntry($this->dir));
        DeployabilityCheck::assert(['strategy' => Strategies::DOTNET], $this->dir);
    }

    /** Prowlarr.Console is a plain Exe; ASP.NET Core comes in through Prowlarr.Host. */
    public function test_an_executable_reaching_aspnet_through_a_project_reference_is_the_entry_point(): void
    {
        $this->write('src/NzbDrone.Console/Prowlarr.Console.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>Exe</OutputType></PropertyGroup><ItemGroup>'
            . '<ProjectReference Include="..\\NzbDrone.Host\\Prowlarr.Host.csproj" /></ItemGroup></Project>');
        $this->write('src/NzbDrone.Host/Prowlarr.Host.csproj', '<Project Sdk="Microsoft.NET.Sdk"><ItemGroup>'
            . '<ProjectReference Include="..\\Prowlarr.Http\\Prowlarr.Http.csproj" /></ItemGroup></Project>');
        $this->write('src/Prowlarr.Http/Prowlarr.Http.csproj', '<Project Sdk="Microsoft.NET.Sdk"><ItemGroup>'
            . '<PackageReference Include="Microsoft.AspNetCore.SignalR.Client" Version="8.0.0" /></ItemGroup></Project>');

        $this->assertSame('src/NzbDrone.Console/Prowlarr.Console.csproj', DotnetRuntime::entryProject($this->dir));
        $this->assertTrue((new DotnetProjectProbe())->evaluate($this->context()));
    }

    public function test_a_console_app_with_its_own_http_listener_is_the_entry_point(): void
    {
        $this->write('Server/Server.csproj', '<Project Sdk="Microsoft.NET.Sdk"><OutputType>Exe</OutputType></Project>');
        $this->write('Server/Program.cs', "var listener = new System.Net.HttpListener();\nlistener.Start();\n");

        $this->assertSame('Server/Server.csproj', DotnetRuntime::entryProject($this->dir));
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
            . '<TargetFrameworks>net8.0</TargetFrameworks></PropertyGroup><ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>'
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
        $this->write('src/NzbDrone.Console/Prowlarr.Console.csproj', '<Project Sdk="Microsoft.NET.Sdk"><OutputType>Exe</OutputType>'
            . '<ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>');

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
            . '<PropertyGroup><OutputType>Exe</OutputType><TargetFrameworks>net10.0</TargetFrameworks></PropertyGroup>'
            . '<ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>');

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

    /** Kavita: its BenchmarkDotNet harness is an Exe too, and sorts before the server. */
    public function test_a_benchmark_project_is_never_the_entry_project(): void
    {
        $this->write('Kavita.Benchmark/Kavita.Benchmark.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>Exe</OutputType></PropertyGroup></Project>');
        $this->write('Kavita.Server/Kavita.Server.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>Exe</OutputType></PropertyGroup>'
            . '<ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>');

        $this->assertSame('Kavita.Server/Kavita.Server.csproj', DotnetRuntime::entryProject($this->dir));

        // Named anything, a BenchmarkDotNet reference gives it away.
        rename($this->dir . '/Kavita.Benchmark', $this->dir . '/Perf');
        rename($this->dir . '/Perf/Kavita.Benchmark.csproj', $this->dir . '/Perf/Perf.csproj');
        file_put_contents($this->dir . '/Perf/Perf.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup><OutputType>Exe</OutputType>'
            . '</PropertyGroup><ItemGroup><PackageReference Include="BenchmarkDotNet" Version="0.15.8" /></ItemGroup></Project>');
        $this->write('Kavita.Server/Kavita.Server.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup>'
            . '<OutputType>Exe</OutputType></PropertyGroup>'
            . '<ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>');

        $this->assertSame('Kavita.Server/Kavita.Server.csproj', DotnetRuntime::entryProject($this->dir));
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

    /** ASP.NET looks for wwwroot under the working directory, so the app starts from out/. */
    public function test_the_start_command_runs_the_assembly_from_the_publish_directory(): void
    {
        $this->write('out/Memtly.runtimeconfig.json', '{}');
        $this->write('out/Memtly.dll', '');
        $this->write('bin/dotnet', "#!/bin/sh\necho \"$(pwd) $*\"\n");
        chmod($this->dir . '/bin/dotnet', 0755);

        $command = 'cd ' . escapeshellarg($this->dir) . ' && PATH=' . escapeshellarg($this->dir . '/bin') . ':$PATH sh -c '
            . escapeshellarg(DotnetRuntime::startCommand());
        exec($command . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame([realpath($this->dir) . '/out Memtly.dll'], $output);
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
