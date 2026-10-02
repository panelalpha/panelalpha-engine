<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\CacheManager\RustBuildToolsImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Platform\Runtime\RustBuildTools;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\SharedBaseImages;
use Tests\TestCase;

/**
 * rust:1-bookworm has no protoc, cmake, libclang or mold, and a host compile
 * cannot apt-get them: chirpstack (prost-build, bindgen), fedimint (aws-lc-sys
 * via cmake), vector (zstd-sys via bindgen) and veloren (-fuse-ld=mold) failed.
 */
class HostCompileRustToolsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/rust-tools-hc-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/.cargo', 0777, true);
        file_put_contents($this->dir . '/Cargo.toml', "[package]\nname = \"app\"\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function lock(string ...$crates): void
    {
        $body = "version = 4\n";
        foreach ($crates as $crate) {
            $body .= "\n[[package]]\nname = \"{$crate}\"\nversion = \"1.0.0\"\n";
        }
        file_put_contents($this->dir . '/Cargo.lock', $body);
    }

    public function test_plain_project_needs_nothing(): void
    {
        $this->lock('serde', 'tokio', 'cc', 'openssl-sys');

        $this->assertSame([], RustBuildTools::packages($this->dir));
    }

    public function test_protobuf_cmake_and_bindgen_crates_name_their_tools(): void
    {
        // chirpstack's Cargo.lock, reduced to the crates that matter.
        $this->lock('prost-build', 'tonic-build', 'cmake', 'bindgen', 'clang-sys', 'serde');

        $this->assertSame(
            ['clang', 'cmake', 'libclang-dev', 'libprotobuf-dev', 'protobuf-compiler'],
            RustBuildTools::packages($this->dir)
        );
    }

    public function test_cargo_config_linker_choice_is_installed(): void
    {
        // veloren's .cargo/config.toml
        file_put_contents(
            $this->dir . '/.cargo/config.toml',
            "[target.x86_64-unknown-linux-gnu]\nrustflags = [\"-C\", \"link-arg=-fuse-ld=mold\"]\n"
        );
        file_put_contents($this->dir . '/.cargo/config', "[build]\nrustflags = [\"-C\", \"link-arg=-fuse-ld=lld\"]\nlinker = \"clang\"\n");

        $this->assertSame(['clang', 'lld', 'mold'], RustBuildTools::packages($this->dir));
    }

    public function test_image_installs_the_packages_on_the_build_image(): void
    {
        $dockerfile = (string) RustBuildToolsImage::dockerfile('rust:1-bookworm', ['protobuf-compiler', 'cmake']);

        $this->assertStringStartsWith("FROM rust:1-bookworm\n", $dockerfile);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends cmake protobuf-compiler', $dockerfile);
        $this->assertStringStartsWith('panelalpha/build-rust:rust-1-bookworm-pa', (string) RustBuildToolsImage::tag('rust:1-bookworm', ['cmake']));
        $this->assertNotSame(
            RustBuildToolsImage::tag('rust:1-bookworm', ['cmake']),
            RustBuildToolsImage::tag('rust:1-bookworm', ['mold'])
        );
        $this->assertNull(RustBuildToolsImage::dockerfile('rust:1-bookworm', ['cmake; rm -rf /']));
        $this->assertNull(RustBuildToolsImage::dockerfile('rust:1-bookworm', []));
    }

    public function test_rust_compile_runs_in_the_tools_image(): void
    {
        $this->lock('prost-build');
        $tag = (string) RustBuildToolsImage::tag('rust:1-bookworm', ['libprotobuf-dev', 'protobuf-compiler']);

        $this->assertSame($tag, $this->compileImage($tag, ['libprotobuf-dev', 'protobuf-compiler']));
    }

    public function test_rust_compile_keeps_the_build_image_when_the_tools_image_fails(): void
    {
        $this->lock('cmake');

        $this->assertSame('rust:1-bookworm', $this->compileImage(null, ['cmake']));
    }

    public function test_plain_rust_compile_does_not_build_an_image(): void
    {
        $this->lock('serde');

        $this->assertSame('rust:1-bookworm', $this->compileImage(false));
    }

    public function test_tools_image_is_built_on_the_host_when_missing(): void
    {
        [$bases, $commands] = $this->bases(hostHasImage: false);

        $tag = $bases->ensureRustBuild('rust:1-bookworm', ['cmake']);

        $this->assertSame(RustBuildToolsImage::tag('rust:1-bookworm', ['cmake']), $tag);
        $this->assertCount(1, $commands->built);
        $this->assertStringContainsString('docker build --pull', $commands->built[0]);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends cmake', $commands->built[0]);
    }

    public function test_tools_image_already_on_the_host_is_not_rebuilt(): void
    {
        [$bases, $commands] = $this->bases(hostHasImage: true);

        $this->assertNotNull($bases->ensureRustBuild('rust:1-bookworm', ['cmake']));
        $this->assertSame([], $commands->built);
    }

    public function test_failed_tools_image_answers_null(): void
    {
        [$bases] = $this->bases(hostHasImage: false, buildFails: true);

        $this->assertNull($bases->ensureRustBuild('rust:1-bookworm', ['cmake']));
    }

    /**
     * @param string|false|null $tag what ensureRustBuild() answers; false = must not be asked
     * @param list<string> $packages
     */
    private function compileImage(string|false|null $tag, array $packages = []): string
    {
        $bases = $this->createMock(SharedBaseImages::class);
        if ($tag === false) {
            $bases->expects($this->never())->method('ensureRustBuild');
        } else {
            $bases->expects($this->once())->method('ensureRustBuild')
                ->with('rust:1-bookworm', $packages)
                ->willReturn($tag);
        }
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('bases')->willReturn($bases);
        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($inner);

        $method = new \ReflectionMethod(HostCompile::class, 'commandRuntimeImage');

        return $method->invoke(
            new HostCompile($project),
            ['strategy' => 'rust', 'image' => 'rust:1-slim-bookworm'],
            $this->dir
        );
    }

    /** @return array{SharedBaseImages, object} */
    private function bases(bool $hostHasImage, bool $buildFails = false): array
    {
        $commands = new class {
            /** @var list<string> */
            public array $built = [];
        };

        $system = $this->createStub(System::class);
        if ($hostHasImage) {
            $system->method('execOnHost')->willReturn('');
        } else {
            $system->method('execOnHost')->willThrowException(new \Exception('No such image'));
        }
        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);

        $inner = $this->createStub(InnerDocker::class);
        $host = $this->createStub(HostCommands::class);
        $host->method('cancellable')->willReturnCallback(
            function (array|string $cmd) use ($commands, $buildFails): void {
                if ($buildFails) {
                    throw new \Exception('E: Unable to locate package cmake');
                }
                $commands->built[] = (string) $cmd;
            }
        );
        $inner->method('host')->willReturn($host);
        $inner->method('imageStore')->willReturn(new DindImageStore());
        $inner->method('dind')->willReturn($project);

        return [new SharedBaseImages($inner), $commands];
    }
}
