<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformValues;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use PHPUnit\Framework\TestCase;

/**
 * The Rust resolvers, a fixed runtime image, and a manifest command a resolver blanks out of an app config's build.
 */
class PlatformValuesResolversTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-values-resolvers-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $raw */
    private function manifest(array $raw): PlatformManifest
    {
        return PlatformManifest::fromArray($raw + [
            'id' => 'test',
            'label' => 'Test',
            'priority' => 500,
            'detect' => ['file' => 'nothing-here'],
        ], 'test.yaml');
    }

    public function test_the_rust_resolvers_name_this_crates_packages_build_and_binary(): void
    {
        file_put_contents($this->dir.'/Cargo.toml', "[package]\nname = \"server\"\n");
        file_put_contents($this->dir.'/Cargo.lock', "version = 3\n");

        $resolved = PlatformValues::resolvedCommands($this->manifest([
            'commands' => [
                ['id' => 'system-packages', 'stages' => ['build'], 'run' => 'x'],
                ['id' => 'cargo-build', 'stages' => ['build'], 'run' => 'x'],
                ['id' => 'serve', 'stages' => ['start'], 'run' => 'x', 'serve' => true],
            ],
            'commands_from' => ['system-packages' => 'rust.packages', 'cargo-build' => 'rust.build', 'serve' => 'rust.start'],
        ]), ProjectContext::at($this->dir));

        $this->assertSame([
            'system-packages' => RustRuntime::systemPackages(),
            'cargo-build' => RustRuntime::buildCommand($this->dir),
            'serve' => RustRuntime::startCommand($this->dir),
        ], $resolved);
        $this->assertStringContainsString('target/release/server', $resolved['serve']);
    }

    public function test_a_runtime_image_without_a_version_slot_is_used_as_written(): void
    {
        file_put_contents($this->dir.'/package.json', '{"name":"app"}');

        $decision = PlatformValues::apply($this->manifest([
            'runtime' => 'nginx',
            'requires' => ['node' => 'build'],
            'runtime_image' => 'nginx:1.27-alpine',
        ]), ProjectContext::at($this->dir), []);

        $this->assertSame('nginx:1.27-alpine', $decision['runtime_image']);
    }

    public function test_a_manifest_build_command_resolved_to_nothing_is_left_out_beside_an_app_configs(): void
    {
        $manifest = $this->manifest([
            'commands' => [
                ['id' => 'deps', 'stages' => ['build'], 'run' => 'placeholder', 'role' => 'dependencies'],
                ['id' => 'assets', 'stages' => ['build'], 'run' => 'make assets'],
            ],
        ]);
        $appConfig = AppConfig::fromYaml("commands:\n  - {id: seed, run: 'php seed.php', stage: build}\n");

        $decision = PlatformValues::withAppConfigBuild($manifest, $appConfig, ProjectContext::at($this->dir), [
            'resolved_commands' => ['deps' => ' '],
        ]);

        $this->assertSame('', $decision['install_command']);
        $this->assertStringContainsString('make assets', $decision['build_command']);
        $this->assertStringContainsString('php seed.php', $decision['build_command']);
    }
}
