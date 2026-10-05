<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Which Go package the recipe builds, decided from the tree without running Go.
 */
class GoRuntimeTest extends TestCase
{
    private const MAIN = "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-go-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function write(string $relative, string $contents = self::MAIN): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    public function test_traefik_builds_its_cmd_entrypoint_not_the_generate_stub_at_the_root(): void
    {
        $this->write('go.mod', "module github.com/traefik/traefik/v3\n\ngo 1.24\n");
        // The real root: a go:generate stub whose main does nothing.
        $this->write('generate.go', "//go:generate go run ./internal/\n\npackage main\n\nfunc main() {}\n");
        $this->write('cmd/traefik/traefik.go');
        $this->write('cmd/configuration.go', "package cmd\n");
        $this->write('internal/gendoc.go');
        $this->write('pkg/config/static/static.go', "package static\n");

        $this->assertSame('./cmd/traefik', GoRuntime::mainPackage($this->dir));
        $command = GoRuntime::buildCommand($this->dir);
        $this->assertStringContainsString('go build -o app ./cmd/traefik &&', $command);
        $this->assertStringNotContainsString('several Go main packages', $command);
    }

    public function test_seaweedfs_builds_weed_rather_than_the_first_cmd_directory(): void
    {
        $this->write('go.mod', "module github.com/seaweedfs/seaweedfs\n\ngo 1.24\n");
        $this->write('weed/weed.go');
        $this->write('weed/admin/main.go');
        $this->write('cmd/weed-db/main.go');
        $this->write('cmd/weed-sql/main.go');
        $this->write('test/postgres/main.go');
        $this->write('unmaintained/fix_dat/fix_dat.go');

        $this->assertSame('./weed', GoRuntime::mainPackage($this->dir));
    }

    public function test_a_root_main_package_still_builds_the_root(): void
    {
        $this->write('go.mod', "module example.com/app\n\ngo 1.22\n");
        $this->write('main.go');
        $this->write('cmd/tool/main.go');

        $this->assertSame('.', GoRuntime::mainPackage($this->dir));
    }

    public function test_a_build_ignored_root_file_does_not_count_as_the_program(): void
    {
        $this->write('go.mod', "module example.com/lib\n\ngo 1.22\n");
        $this->write('gen.go', "//go:build ignore\n\n" . self::MAIN);
        $this->write('cmd/server/main.go');

        $this->assertSame('./cmd/server', GoRuntime::mainPackage($this->dir));
    }

    /** Stash: the root's only Go file is the tools.go idiom, the program is cmd/stash. */
    public function test_a_tools_tagged_root_file_does_not_count_as_the_program(): void
    {
        $this->write('go.mod', "module github.com/stashapp/stash\n\ngo 1.24\n");
        $this->write('tools.go', "//go:build tools\n// +build tools\n\npackage main\n\nimport _ \"github.com/99designs/gqlgen\"\n");
        $this->write('cmd/stash/main.go');
        $this->write('internal/api/server.go', "package api\n");

        $this->assertSame('./cmd/stash', GoRuntime::mainPackage($this->dir));
        $this->assertStringContainsString('go build -o app ./cmd/stash &&', GoRuntime::buildCommand($this->dir));
    }

    public function test_build_constraints_are_evaluated_for_a_linux_build_without_cgo(): void
    {
        $holds = static fn (string $header): bool => GoRuntime::constraintsHold($header . "\n\npackage main\n");

        $this->assertTrue($holds(''));
        $this->assertTrue($holds('//go:build linux'));
        $this->assertTrue($holds('//go:build !windows && (linux || darwin)'));
        $this->assertTrue($holds('//go:build !cgo'));
        $this->assertTrue($holds('//go:build go1.21'));
        $this->assertTrue($holds("// +build linux,!cgo darwin"));
        $this->assertFalse($holds('//go:build tools'));
        $this->assertFalse($holds('//go:build ignore'));
        $this->assertFalse($holds('//go:build cgo'));
        $this->assertFalse($holds('//go:build windows'));
        $this->assertFalse($holds('//go:build linux && integration'));
        $this->assertFalse($holds('// +build tools'));
        // Unparseable: counted, as every file was before.
        $this->assertTrue($holds('//go:build linux &&'));
        // A constraint after the package clause is not one.
        $this->assertTrue(GoRuntime::constraintsHold("package main\n\n//go:build tools\n"));
    }

    public function test_a_single_cmd_entrypoint_is_built(): void
    {
        $this->write('go.mod', "module example.com/bar\n\ngo 1.22\n");
        $this->write('cmd/foo/main.go');
        $this->write('lib.go', "package bar\n");

        $this->assertSame('./cmd/foo', GoRuntime::mainPackage($this->dir));
    }

    public function test_the_repository_name_picks_the_entrypoint(): void
    {
        $this->write('go.mod', "module example.com/internal-name\n\ngo 1.22\n");
        $this->write('cmd/other/main.go');
        $this->write('cmd/widget/main.go');

        $this->assertSame(
            './cmd/widget',
            GoRuntime::mainPackage($this->dir, 'https://github.com/acme/widget.git')
        );
    }

    public function test_ambiguous_entrypoints_keep_the_old_pick_and_name_the_candidates(): void
    {
        $this->write('go.mod', "module example.com/widget\n\ngo 1.22\n");
        $this->write('cmd/beta/main.go');
        $this->write('cmd/alpha/main.go');

        // Today's rule: least nested, then shortest, then alphabetical.
        $this->assertSame('./cmd/beta', GoRuntime::mainPackage($this->dir));
        $command = GoRuntime::buildCommand($this->dir);
        $this->assertStringContainsString('several Go main packages (cmd/alpha, cmd/beta); building ./cmd/beta', $command);
        $this->assertStringContainsString('go build -o app ./cmd/beta &&', $command);
    }

    /**
     * Dropserver (#94): `ds-dev` is a development helper beside the server
     * `ds-host`, and won only by being the shorter name.
     */
    public function test_a_development_helper_is_not_picked_over_the_server(): void
    {
        $this->write('go.mod', "module github.com/teleclimber/DropServer\n\ngo 1.22\n");
        $this->write('cmd/ds-dev/ds-dev.go');
        $this->write('cmd/ds-host/ds-host.go');

        $command = GoRuntime::buildCommand($this->dir, 'https://github.com/teleclimber/Dropserver');

        $this->assertStringContainsString('go build -o app ./cmd/ds-host &&', $command);
        // Still a guess, so the candidates are still named.
        $this->assertStringContainsString('several Go main packages (cmd/ds-dev, cmd/ds-host)', $command);
    }

    public function test_only_helpers_still_builds_one_of_them(): void
    {
        $this->write('go.mod', "module example.com/widget\n\ngo 1.22\n");
        $this->write('cmd/schema-gen/main.go');
        $this->write('cmd/api-mock/main.go');

        $this->assertSame('./cmd/api-mock', GoRuntime::mainPackage($this->dir));
    }
}
