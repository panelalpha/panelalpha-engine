<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntimeLibraries;
use PHPUnit\Framework\TestCase;

/**
 * A Rust binary compiled in rust:1-bookworm links libraries rust:1-slim-bookworm
 * does not have (#92: focus_flow_cloud's diesel links libpq.so.5). The scripts
 * run here against a fake `ldd` and `ldconfig` that answer from two directories
 * standing in for the two images.
 */
class RustRuntimeLibrariesTest extends TestCase
{
    private string $root;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/rust-libs-' . bin2hex(random_bytes(4));
        $this->project = $this->root . '/project';
        mkdir($this->project . '/target/release/deps', 0o777, true);

        // Two "images": the runtime has libssl, the build image has the closure.
        foreach (['runtime' => ['libssl.so.3'], 'build' => ['libssl.so.3', 'libpq.so.5', 'libgssapi_krb5.so.2']] as $image => $libs) {
            mkdir($this->root . '/' . $image);
            foreach ($libs as $lib) {
                file_put_contents($this->root . '/' . $image . '/' . $lib, $image . ' ' . $lib);
            }
        }

        $bin = $this->root . '/bin';
        mkdir($bin);
        // ldd: the binary's text is the sonames it needs, the closure included,
        // as real ldd prints it. LD_LIBRARY_PATH first, then the image.
        $this->stub($bin . '/ldd', <<<'SH'
            #!/bin/sh
            for so in $(cat "$1"); do
              if [ -n "$LD_LIBRARY_PATH" ] && [ -f "$LD_LIBRARY_PATH/$so" ]; then printf '\t%s => %s (0x0)\n' "$so" "$LD_LIBRARY_PATH/$so";
              elif [ -f "$FAKE_LIBDIR/$so" ]; then printf '\t%s => %s (0x0)\n' "$so" "$FAKE_LIBDIR/$so";
              else printf '\t%s => not found\n' "$so"; fi
            done
            SH);
        $this->stub($bin . '/ldconfig', <<<'SH'
            #!/bin/sh
            echo "2 libs found in cache \`/etc/ld.so.cache'"
            for f in "$FAKE_LIBDIR"/*; do printf '\t%s (libc6,x86-64) => %s\n' "$(basename "$f")" "$f"; done
            SH);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function stub(string $path, string $body): void
    {
        file_put_contents($path, $body . "\n");
        chmod($path, 0o755);
    }

    /** @param list<string> $needs */
    private function binary(string $name, array $needs): void
    {
        $path = $this->project . '/target/release/' . $name;
        file_put_contents($path, implode("\n", $needs) . "\n");
        chmod($path, 0o755);
        // Build by-products the start command also skips.
        file_put_contents($path . '.d', "libnothere.so.9\n");
    }

    /** @return array{int, string} */
    private function runIn(string $image, string $script): array
    {
        $cmd = 'cd ' . escapeshellarg($this->project)
            . ' && env PATH=' . escapeshellarg($this->root . '/bin:/usr/local/bin:/usr/bin:/bin')
            . ' FAKE_LIBDIR=' . escapeshellarg($this->root . '/' . $image)
            . ' sh -c ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $out, $code);

        return [$code, implode("\n", $out)];
    }

    private function inProject(string $path): string
    {
        return $this->project . '/' . $path;
    }

    public function test_a_binary_the_runtime_image_can_run_leaves_nothing_behind(): void
    {
        $this->binary('app', ['libssl.so.3']);
        // A previous deploy's bundle must not outlive the binary that needed it.
        mkdir($this->inProject(RustRuntimeLibraries::DIR));

        [$code] = $this->runIn('runtime', RustRuntimeLibraries::checkScript());

        $this->assertSame(0, $code);
        $this->assertDirectoryDoesNotExist($this->inProject(RustRuntimeLibraries::DIR));
    }

    public function test_the_check_names_what_the_runtime_image_lacks(): void
    {
        $this->binary('app', ['libssl.so.3', 'libpq.so.5', 'libgssapi_krb5.so.2']);

        [$code, $out] = $this->runIn('runtime', RustRuntimeLibraries::checkScript());

        $this->assertSame(0, $code);
        $this->assertSame(
            "libgssapi_krb5.so.2\nlibpq.so.5\n",
            file_get_contents($this->inProject(RustRuntimeLibraries::MISSING_FILE))
        );
        $this->assertStringContainsString('PANELALPHA: the runtime image has no libgssapi_krb5.so.2 libpq.so.5', $out);
    }

    public function test_only_what_the_runtime_image_lacks_is_bundled_and_then_it_resolves(): void
    {
        $this->binary('app', ['libssl.so.3', 'libpq.so.5', 'libgssapi_krb5.so.2']);

        $this->runIn('runtime', RustRuntimeLibraries::checkScript());
        [$code, $out] = $this->runIn('build', RustRuntimeLibraries::bundleScript());
        $this->assertSame(0, $code, $out);

        $dir = $this->inProject(RustRuntimeLibraries::DIR);
        $this->assertSame('build libpq.so.5', file_get_contents($dir . '/libpq.so.5'));
        $this->assertFileExists($dir . '/libgssapi_krb5.so.2');
        $this->assertFileDoesNotExist($dir . '/libssl.so.3', 'the runtime image has its own');

        [$code, $out] = $this->runIn('runtime', RustRuntimeLibraries::verifyScript());
        $this->assertSame(0, $code, $out);
        $this->assertSame(['libgssapi_krb5.so.2', 'libpq.so.5'], array_values(array_diff(scandir($dir), ['.', '..'])));
    }

    public function test_a_library_neither_image_has_fails_the_deploy_by_name(): void
    {
        $this->binary('app', ['libssl.so.3', 'libpq.so.5', 'libduckdb.so']);

        $this->runIn('runtime', RustRuntimeLibraries::checkScript());
        $this->runIn('build', RustRuntimeLibraries::bundleScript());
        [$code, $out] = $this->runIn('runtime', RustRuntimeLibraries::verifyScript());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('PANELALPHA: the Rust binary needs libduckdb.so and neither', $out);
    }

    public function test_the_start_command_puts_the_bundle_on_the_library_path_only_when_there_is_one(): void
    {
        file_put_contents($this->project . '/Cargo.toml', "[package]\nname = \"app\"\n");
        $this->stub($this->project . '/target/release/app', '#!/bin/sh' . "\n" . 'echo "path=${LD_LIBRARY_PATH:-unset}"');
        $start = RustRuntime::startCommand($this->project);

        [$code, $out] = $this->runIn('runtime', $start);
        $this->assertSame(0, $code, $out);
        $this->assertSame('path=unset', $out, 'a binary that needed nothing runs exactly as before');

        mkdir($this->inProject(RustRuntimeLibraries::DIR));
        [$code, $out] = $this->runIn('runtime', $start);
        $this->assertSame(0, $code, $out);
        $this->assertSame('path=' . realpath($this->inProject(RustRuntimeLibraries::DIR)), $out);
    }
}
