<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Project files .NET detection cannot read, reference loops, empty framework entries and the defaults it falls back to.
 */
class DotnetRuntimeProjectEdgesTest extends TestCase
{
    private const EXE = '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup><OutputType>Exe</OutputType><TargetFramework>net8.0</TargetFramework></PropertyGroup>';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-dotnet-edges-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    public function test_the_engine_default_is_dotnet_8(): void
    {
        $runtime = new DotnetRuntime;

        $this->assertSame(DotnetRuntime::imageTag('8.0'), DotnetRuntime::defaultImage());
        $this->assertSame(['dotnet', '8.0', '', 'engine default'], [
            $runtime->defaultRequirement()->id,
            $runtime->defaultRequirement()->version,
            $runtime->defaultRequirement()->constraint,
            $runtime->defaultRequirement()->source,
        ]);
        $this->assertSame(['8.0', '9.0'], $runtime->supportedVersions());
    }

    public function test_a_folder_named_like_a_project_is_passed_over(): void
    {
        mkdir($this->dir.'/Broken.csproj');
        $this->write('src/Web/Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFramework>net9.0</TargetFramework></PropertyGroup></Project>');

        $this->assertSame('src/Web/Web.csproj', DotnetRuntime::entryProject($this->dir));
        $this->assertSame('9.0', DotnetRuntime::targetFramework($this->dir));
    }

    public function test_a_reference_that_cannot_be_read_does_not_serve(): void
    {
        $this->write('App/App.csproj', self::EXE.'<ItemGroup><ProjectReference Include="../Host.csproj" /></ItemGroup></Project>');
        mkdir($this->dir.'/Host.csproj');

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
    }

    public function test_projects_referencing_each_other_end_the_search(): void
    {
        $this->write('A/A.csproj', self::EXE.'<ItemGroup><ProjectReference Include="../B/B.csproj" /></ItemGroup></Project>');
        $this->write('B/B.csproj', '<Project Sdk="Microsoft.NET.Sdk"><ItemGroup><ProjectReference Include="..\\A\\A.csproj" /></ItemGroup></Project>');

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
    }

    public function test_a_reference_through_dot_segments_still_reaches_the_server(): void
    {
        $this->write('App/App.csproj', self::EXE.'<ItemGroup><ProjectReference Include=".//../Host/./Host.csproj" /></ItemGroup></Project>');
        $this->write('Host/Host.csproj', '<Project Sdk="Microsoft.NET.Sdk"><ItemGroup><FrameworkReference Include="Microsoft.AspNetCore.App" /></ItemGroup></Project>');

        $this->assertSame('App/App.csproj', DotnetRuntime::entryProject($this->dir));
    }

    public function test_a_console_tool_with_more_sources_than_are_searched_serves_nothing(): void
    {
        $this->write('Tool/Tool.csproj', self::EXE.'</Project>');
        for ($i = 0; $i < 201; $i++) {
            $this->write(sprintf('Tool/Src/F%03d.cs', $i), 'class F {}');
        }

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
    }

    public function test_a_serving_executable_naming_its_framework_only_through_a_property_is_reported(): void
    {
        $this->write('Sample/Sample.csproj', '<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup><OutputType>Exe</OutputType>'
            .'<TargetFrameworks>;$(SampleTfm)</TargetFrameworks></PropertyGroup>'
            .'<ItemGroup><PackageReference Include="Microsoft.AspNetCore.Owin" Version="8.0.0" /></ItemGroup></Project>');

        $this->assertTrue(DotnetRuntime::servesNothing($this->dir));
        $this->assertSame(['file' => 'Sample/Sample.csproj', 'framework' => ';$(SampleTfm)'], DotnetRuntime::unresolvedFrameworkProject($this->dir));
    }

    public function test_an_empty_entry_in_a_framework_list_is_skipped(): void
    {
        $this->write('Legacy/Legacy.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFrameworks>net472;;net48</TargetFrameworks></PropertyGroup></Project>');

        $this->assertSame(['file' => 'Legacy/Legacy.csproj', 'framework' => 'v4.8', 'sdk_style' => true], DotnetRuntime::legacyFrameworkEntry($this->dir));
    }

    public function test_no_root_has_no_node_build(): void
    {
        $this->assertNull(DotnetRuntime::nodeBuildImage(''));
    }

    public function test_a_node_build_is_found_past_the_solution(): void
    {
        $this->write('App.sln', "Project(\"{FAE04EC0}\") = \"Web\", \"Web\\Web.csproj\", \"{1}\"\nEndProject\n");
        $this->write('Web/Web.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><Target Name="Ui"><Exec Command="npm ci" /></Target></Project>');

        $this->assertNotNull(DotnetRuntime::nodeBuildImage($this->dir));
    }

    public function test_a_project_that_is_not_there_has_no_publish_framework(): void
    {
        $this->assertNull(DotnetRuntime::publishFramework($this->dir, 'Missing/Missing.csproj'));
    }
}
