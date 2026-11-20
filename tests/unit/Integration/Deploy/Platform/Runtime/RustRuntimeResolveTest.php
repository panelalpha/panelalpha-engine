<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use PHPUnit\Framework\TestCase;

/**
 * What a Cargo project resolves to: always the rolling toolchain, with its declared `rust-version` carried for the log.
 */
class RustRuntimeResolveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-rust-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string}|null */
    private function resolved(): ?array
    {
        $requirement = (new RustRuntime)->resolve(ProjectContext::at($this->dir));

        return $requirement === null ? null : [$requirement->id, $requirement->version, $requirement->constraint, $requirement->source];
    }

    public function test_a_project_without_a_cargo_manifest_is_not_rust(): void
    {
        $this->assertNull($this->resolved());
    }

    public function test_a_declared_rust_version_is_carried_as_the_constraint(): void
    {
        file_put_contents($this->dir.'/Cargo.toml', "[package]\nname = \"app\"\nrust-version = \"1.74\"\n");

        $this->assertSame(['rust', RustRuntime::VERSION, '1.74', 'Cargo.toml rust-version'], $this->resolved());
    }

    public function test_a_manifest_without_a_rust_version_takes_the_rolling_toolchain(): void
    {
        file_put_contents($this->dir.'/Cargo.toml', "[package]\nname = \"app\"\n");

        $this->assertSame(['rust', RustRuntime::VERSION, '', 'Cargo.toml'], $this->resolved());
    }

    public function test_every_requirement_builds_on_the_one_rolling_image(): void
    {
        $runtime = new RustRuntime;
        $default = $runtime->defaultRequirement();

        $this->assertSame(['rust', RustRuntime::VERSION, '', 'engine default'], [$default->id, $default->version, $default->constraint, $default->source]);
        $this->assertSame(RustRuntime::defaultImage(), $runtime->image($default));
        $this->assertSame(RustRuntime::imageTag(RustRuntime::VERSION), RustRuntime::defaultImage());
    }

    public function test_a_manifest_naming_no_crate_has_no_binary_name(): void
    {
        file_put_contents($this->dir.'/Cargo.toml', "[workspace]\nmembers = [\"a\"]\n");

        $this->assertNull(RustRuntime::binaryName($this->dir));
        $this->assertSame('target/release/app', RustRuntime::binaryPath($this->dir));
    }
}
