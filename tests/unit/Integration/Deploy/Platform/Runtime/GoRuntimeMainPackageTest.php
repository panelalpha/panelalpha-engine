<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The package a Go build compiles and the exact build command, pinned for each tree shape.
 */
class GoRuntimeMainPackageTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-characterise-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function tree(array $files): string
    {
        foreach ($files as $relative => $contents) {
            $path = $this->dir.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }

        return $this->dir;
    }

    public static function trees(): array
    {
        return [
            'root main' => [['go.mod' => "module example.com/app\n\ngo 1.22\n", 'main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, '.', '{ apk add --no-cache git || true; } && go build -o app . && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'two cmd entrypoints' => [['go.mod' => "module example.com/widget\n\ngo 1.22\n", 'cmd/beta/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'cmd/alpha/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './cmd/beta', 'echo \'PANELALPHA: several Go main packages (cmd/alpha, cmd/beta); building ./cmd/beta. Set the build command to choose another.\' && { apk add --no-cache git || true; } && go build -o app ./cmd/beta && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'skipped directories' => [['go.mod' => "module example.com/app\n\ngo 1.22\n", 'vendor/x/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'testdata/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'node_modules/m/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", '.hidden/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", '_skip/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'main_test.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'cmd/app/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], 'https://github.com/acme/app.git', './cmd/app', '{ apk add --no-cache git || true; } && go build -o app ./cmd/app && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'helper filtered before the shortest' => [['go.mod' => "module example.com/foo\n\ngo 1.22\n", 'cmd/foo-dev/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'cmd/foo-server/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'cmd/bar/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './cmd/bar', 'echo \'PANELALPHA: several Go main packages (cmd/bar, cmd/foo-dev, cmd/foo-server); building ./cmd/bar. Set the build command to choose another.\' && { apk add --no-cache git || true; } && go build -o app ./cmd/bar && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'nested only' => [['go.mod' => "module example.com/zz\n\ngo 1.22\n", 'a/b/c/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'x/y/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './x/y', 'echo \'PANELALPHA: several Go main packages (a/b/c, x/y); building ./x/y. Set the build command to choose another.\' && { apk add --no-cache git || true; } && go build -o app ./x/y && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'two programs under cmd named after the module, written b first' => [['go.mod' => "module example.com/zz\n\ngo 1.22\n", 'b/cmd/zz/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'cmd/x/zz/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './b/cmd/zz', null],
            'two programs under cmd named after the module, written cmd first' => [['go.mod' => "module example.com/zz\n\ngo 1.22\n", 'cmd/x/zz/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'b/cmd/zz/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './b/cmd/zz', null],
            'stub root beside a program' => [['go.mod' => "module example.com/svc\n\ngo 1.22\n", 'gen.go' => "package main\n\nfunc main() {}\n", 'server/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './server', '{ apk add --no-cache git || true; } && go build -o app ./server && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'contained name' => [['go.mod' => "module github.com/acme/seaweedfs\n\ngo 1.22\n", 'weed/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'other/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], 'https://github.com/acme/seaweedfs', './weed', '{ apk add --no-cache git || true; } && go build -o app ./weed && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'no main at all' => [['go.mod' => "module example.com/lib\n\ngo 1.22\n", 'lib.go' => "package lib\n"], null, '.', '{ apk add --no-cache git || true; } && go build -o app . && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'a nested module is not the root module\'s program' => [['go.mod' => "module github.com/acme/kloud\n\ngo 1.22\n", 'build-sys/go.mod' => "module github.com/acme/kloud/build-sys\n\ngo 1.22\n", 'build-sys/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n", 'cmd/kloud/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './cmd/kloud', '{ apk add --no-cache git || true; } && go build -o app ./cmd/kloud && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
            'a go.work joins the nested module' => [['go.mod' => "module example.com/ws\n\ngo 1.22\n", 'go.work' => "go 1.22\n\nuse (\n\t.\n\t./server\n)\n", 'server/go.mod' => "module example.com/ws/server\n\ngo 1.22\n", 'server/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './server', null],
            'constrained out' => [['go.mod' => "module example.com/app\n\ngo 1.22\n", 'tools.go' => "//go:build tools\n\npackage main\n\nfunc main() { println() }\n", 'cmd/app/main.go' => "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n"], null, './cmd/app', '{ apk add --no-cache git || true; } && go build -o app ./cmd/app && { [ -x app ] || { echo "PANELALPHA: no runnable Go program found"; exit 1; }; }'],
        ];
    }

    /** @param array<string, string> $files */
    #[DataProvider('trees')]
    public function test_the_package_and_build_command_are_pinned(array $files, ?string $sourceUrl, string $package, ?string $command): void
    {
        $dir = $this->tree($files);

        $this->assertSame($package, GoRuntime::mainPackage($dir, $sourceUrl));
        if ($command !== null) {
            $this->assertSame($command, GoRuntime::buildCommand($dir, $sourceUrl));
        }
    }

    public function test_nested_candidates_are_named_in_sorted_order_whatever_the_listing_order(): void
    {
        $names = array_map(static fn (int $n): string => sprintf('p%02d/main', $n), range(0, 11));
        $files = ['go.mod' => "module example.com/zz\n\ngo 1.22\n"];
        foreach (array_reverse($names) as $name) {
            $files[$name.'/main.go'] = "package main\n\nfunc main() {\n\tprintln(\"up\")\n}\n";
        }

        $this->assertStringStartsWith(
            "echo 'PANELALPHA: several Go main packages (".implode(', ', $names).'); building ./p00/main.',
            GoRuntime::buildCommand($this->tree($files))
        );
    }

    public static function legacyConstraints(): array
    {
        return [
            'two legacy lines both hold' => ["// +build linux\n// +build !cgo", true],
            'second legacy line fails' => ["// +build linux\n// +build windows", false],
            'or of and terms' => ['// +build windows,amd64 darwin', false],
            'or with one holding term' => ['// +build windows linux,!cgo', true],
            'negated tag' => ['// +build !linux', false],
        ];
    }

    #[DataProvider('legacyConstraints')]
    public function test_legacy_build_lines_are_pinned(string $header, bool $holds): void
    {
        $this->assertSame($holds, GoRuntime::constraintsHold($header."\n\npackage main\n"));
    }
}
