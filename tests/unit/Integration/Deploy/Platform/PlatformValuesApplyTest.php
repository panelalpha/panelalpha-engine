<?php

namespace Tests\Integration\Deploy\Platform;

use Tests\Support\TestNetwork;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformValues;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * Whole decisions out of {@see PlatformValues::apply()}, keys in order, for the paths that move the app root.
 */
class PlatformValuesApplyTest extends TestCase
{
    protected string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-values-apply-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
        PlatformRegistry::flush();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        PlatformRegistry::flush();
    }

    protected function write(string $relative, string $contents): void
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    /** @param array<string, mixed> $raw */
    protected function manifest(array $raw): PlatformManifest
    {
        return PlatformManifest::fromArray($raw + [
            'id' => 'test',
            'label' => 'Test',
            'priority' => 500,
            'detect' => ['file' => 'nothing-here'],
        ], 'test.yaml');
    }

    /** @return array{0: PlatformManifest, 1: array<string, mixed>} */
    protected function dotnetWithJsTarget(): array
    {
        $this->write('App/App.csproj', '<Project Sdk="Microsoft.NET.Sdk.Web"><PropertyGroup><TargetFramework>net8.0</TargetFramework></PropertyGroup>'
            . '<Target Name="NpmInstall" BeforeTargets="Build"><Exec Command="npm ci" /></Target></Project>');
        $this->write('App/package.json', '{"engines":{"node":">=22"}}');

        return [PlatformRegistry::find('dotnet'), ['strategy' => 'dotnet']];
    }

    /** @return array{0: PlatformManifest, 1: array<string, mixed>} */
    protected function standaloneNextWorkspace(): array
    {
        $this->write('go.mod', "module example.com/app\n\ngo 1.22\n");
        $this->write('web/package.json', (string) json_encode([
            'name' => 'web',
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'dependencies' => ['next' => '16.0.0', 'react' => '19.0.0'],
            'engines' => ['node' => '24.x'],
        ]));
        $this->write('web/package-lock.json', '{"lockfileVersion":3,"packages":{}}');

        return [PlatformRegistry::find('nextjs'), [
            'strategy' => 'nextjs',
            'workspace_relative' => 'web',
            'workspace_package' => 'web',
            'workspace_slug' => 'web',
        ]];
    }

    /** @return array{0: PlatformManifest, 1: array<string, mixed>} */
    protected function nextUnderAppRoot(): array
    {
        $this->write('site/package.json', (string) json_encode(['private' => true, 'workspaces' => ['apps/*']]));
        $this->write('site/package-lock.json', '{"lockfileVersion":3,"packages":{}}');
        $this->write('site/apps/web/package.json', (string) json_encode([
            'name' => '@acme/web',
            'scripts' => ['build' => 'next build'],
            'dependencies' => ['next' => '15.0.0'],
        ]));

        $nextjs = PlatformRegistry::find('nextjs');

        return [$this->manifest([
            'runtime' => 'node',
            'strategy' => $nextjs->strategy,
            'app_root' => 'site',
            'output_from' => 'next-workspace',
            'requires' => ['node' => 'runtime'],
            'commands' => [
                ['id' => 'install', 'stage' => 'build', 'role' => 'dependencies', 'run' => '{{js.install}}'],
                ['id' => 'build', 'stage' => 'build', 'run' => '{{js.build:npm run build}}'],
                ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => '{{js.start:npm start}}'],
            ],
        ]), ['strategy' => $nextjs->strategy]];
    }

    /** @return array{0: PlatformManifest, 1: array<string, mixed>} */
    protected function resolvedCommandProject(): array
    {
        $this->write('requirements.txt', "django==5.0\n");

        return [$this->manifest([
            'runtime' => 'command',
            'image' => 'python:3.12-slim',
            'commands' => [
                ['id' => 'install', 'stage' => 'build', 'role' => 'dependencies', 'run' => 'placeholder'],
                ['id' => 'assets', 'stage' => 'build', 'run' => 'make assets', 'optional' => true],
                ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'python app.py'],
            ],
            'commands_from' => ['install' => 'python.install'],
        ]), ['strategy' => 'test', 'kept' => true]];
    }

    /** @return array<string, mixed> */
    protected function outcome(string $fixture): array
    {
        [$manifest, $decision] = $this->{$fixture}();
        $applied = PlatformValues::apply($manifest, ProjectContext::at($this->dir), $decision);

        return json_decode(str_replace($this->dir, '<dir>', (string) json_encode($applied)), true);
    }

    public function test_a_dotnet_build_whose_target_runs_npm_gets_a_node_build_image(): void
    {
        $this->assertSame([
            'strategy' => 'dotnet',
            'image' => 'mcr.microsoft.com/dotnet/sdk:8.0',
            'requirements' => [
                [
                    'id' => 'dotnet',
                    'version' => '8.0',
                    'constraint' => 'net8.0',
                    'source' => 'TargetFramework',
                    'role' => 'runtime',
                ],
            ],
            'toolchain' => ['dotnet 8.0 (from TargetFramework)', 'node:22-bookworm-slim in the build stage (an MSBuild target runs a JS tool)'],
            'build_images' => [],
            'output_directory' => 'out',
            'runtime_image' => 'mcr.microsoft.com/dotnet/aspnet:8.0',
            'build_node_image' => 'node:22-bookworm-slim',
            'resolved_commands' => [
                'dotnet-publish' => 'echo \'<Project><PropertyGroup><WarningsNotAsErrors>$(WarningsNotAsErrors);NU1900;NU1901;NU1902;NU1903;NU1904</WarningsNotAsErrors></PropertyGroup></Project>\' > /tmp/panelalpha-nuget-audit.targets && dotnet publish \'App/App.csproj\' -c Release -o out --nologo -p:CustomAfterMicrosoftCommonTargets=/tmp/panelalpha-nuget-audit.targets',
                'serve' => 'cfg=$(ls out/*.runtimeconfig.json 2>/dev/null | head -n 1); [ -n "$cfg" ] || { echo "PANELALPHA: dotnet publish produced no runnable assembly in out/"; echo "PANELALPHA: published files: $(ls out 2>/dev/null | head -n 20)"; exit 1; }; cd out && exec dotnet "$(basename "${cfg%.runtimeconfig.json}").dll"',
            ],
            'install_command' => 'echo \'<Project><PropertyGroup><WarningsNotAsErrors>$(WarningsNotAsErrors);NU1900;NU1901;NU1902;NU1903;NU1904</WarningsNotAsErrors></PropertyGroup></Project>\' > /tmp/panelalpha-nuget-audit.targets && dotnet publish \'App/App.csproj\' -c Release -o out --nologo -p:CustomAfterMicrosoftCommonTargets=/tmp/panelalpha-nuget-audit.targets',
            'build_command' => '',
            'start_command' => 'cfg=$(ls out/*.runtimeconfig.json 2>/dev/null | head -n 1); [ -n "$cfg" ] || { echo "PANELALPHA: dotnet publish produced no runnable assembly in out/"; echo "PANELALPHA: published files: $(ls out 2>/dev/null | head -n 20)"; exit 1; }; cd out && exec dotnet "$(basename "${cfg%.runtimeconfig.json}").dll"',
        ], $this->outcome('dotnetWithJsTarget'));
    }

    public function test_a_next_workspace_with_no_root_package_json_becomes_the_app_root(): void
    {
        $this->assertSame([
            'strategy' => 'nextjs',
            'workspace_relative' => '',
            'app_root' => 'web',
            'image' => 'node:24-bookworm-slim',
            'requirements' => [
                [
                    'id' => 'node',
                    'version' => '24',
                    'constraint' => '24.x',
                    'source' => 'package.json engines.node',
                    'role' => 'runtime',
                ],
            ],
            'toolchain' => ['node 24 (from package.json engines.node)'],
            'build_images' => [],
            'output_directory' => '.next',
            'runtime_image' => null,
            'resolved_commands' => [],
            'label' => 'Next.js',
            'runtime' => 'node',
            'port_hint' => 3000,
            'package_manager' => 'npm',
            'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund',
            'build_command' => 'npm run build',
            'start_command' => 'npm start',
            'setup_command' => null,
            'env' => [
                'HOST' => TestNetwork::ANY,
                'HOSTNAME' => TestNetwork::ANY,
                'PORT' => '3000',
                'HUSKY' => '0',
                'CI' => '1',
            ],
        ], $this->outcome('standaloneNextWorkspace'));
    }

    public function test_a_recipe_under_an_app_root_finds_its_next_workspace_there(): void
    {
        $this->assertSame([
            'strategy' => 'nextjs',
            'image' => 'node:22-bookworm-slim',
            'requirements' => [
                [
                    'id' => 'node',
                    'version' => '22',
                    'constraint' => '',
                    'source' => 'engine default',
                    'role' => 'runtime',
                ],
            ],
            'toolchain' => ['node 22 (from engine default)'],
            'build_images' => [],
            'workspace_relative' => 'apps/web',
            'workspace_package' => '@acme/web',
            'workspace_slug' => 'web',
            'output_directory' => 'apps/web/.next',
            'runtime_image' => null,
            'resolved_commands' => [],
            'label' => 'Test',
            'runtime' => 'node',
            'port_hint' => 3000,
            'package_manager' => 'npm',
            'install_command' => 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund',
            'build_command' => '',
            'start_command' => '',
            'setup_command' => null,
            'env' => [
                'HOST' => TestNetwork::ANY,
                'HOSTNAME' => TestNetwork::ANY,
                'PORT' => '3000',
                'HUSKY' => '0',
                'CI' => '1',
            ],
        ], $this->outcome('nextUnderAppRoot'));
    }

    public function test_resolved_commands_are_projected_onto_the_legacy_fields(): void
    {
        $this->assertSame([
            'strategy' => 'test',
            'kept' => true,
            'image' => 'python:3.12-slim',
            'requirements' => [],
            'toolchain' => [],
            'build_images' => [],
            'output_directory' => null,
            'runtime_image' => null,
            'resolved_commands' => [
                'install' => 'python -m venv .venv && .venv/bin/pip install -r requirements.txt',
            ],
            'install_command' => 'python -m venv .venv && .venv/bin/pip install -r requirements.txt',
            'build_command' => '{ make assets; } || true',
            'start_command' => 'python app.py',
        ], $this->outcome('resolvedCommandProject'));
    }

    public function test_a_js_placeholder_is_resolved_with_the_decisions_workspace(): void
    {
        [$manifest, $decision] = $this->nextUnderAppRoot();
        $context = ProjectContext::at($this->dir);
        $decision += ['workspace_package' => '@acme/web', 'workspace_slug' => 'web', 'workspace_relative' => 'apps/web', 'output_directory' => 'apps/web/.next'];

        $this->assertSame(
            ['cd \'apps/web\' && npm run build', 'cd \'apps/web\' && npm start', 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'npm run build', 'plain command'],
            [
                PlatformValues::resolveJsPlaceholder($manifest, $context, '{{js.build:npm run build}}', $decision),
                PlatformValues::resolveJsPlaceholder($manifest, $context, '{{js.start:npm start}}', $decision),
                PlatformValues::resolveJsPlaceholder($manifest, $context, '{{js.install}}', $decision),
                PlatformValues::resolveJsPlaceholder($manifest, $context, '{{js.build:npm run build}}', ['workspace_slug' => '', 'workspace_package' => 7]),
                PlatformValues::resolveJsPlaceholder($manifest, $context, 'plain command', $decision),
            ]
        );
    }

    public function test_a_js_placeholder_without_a_package_json_is_its_default(): void
    {
        $context = ProjectContext::at($this->dir);
        $manifest = $this->manifest(['runtime' => 'node']);

        $this->assertSame(' npm run x', ' ' . PlatformValues::resolveJsPlaceholder($manifest, $context, ' {{js.build: npm run x }} '));
        $this->assertSame('', PlatformValues::resolveJsPlaceholder($manifest, $context, '{{js.install}}'));
    }
}
