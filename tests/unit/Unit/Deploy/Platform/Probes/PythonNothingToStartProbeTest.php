<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\PythonNothingToStartProbe;

/**
 * Jellysweep: a Go app whose requirements.txt installs only docs tooling was
 * claimed by python (which outranks go) and served the "not configured" page.
 */
class PythonNothingToStartProbeTest extends ProbeTestCase
{
    private const DOCS_TOOLING = "pre-commit\nmdformat\nmdformat-gfm\nmdformat-frontmatter\nzensical\n";

    private function probe(): PythonNothingToStartProbe
    {
        return new PythonNothingToStartProbe();
    }

    public function test_a_go_app_with_a_tooling_requirements_file_is_go(): void
    {
        $this->write('requirements.txt', self::DOCS_TOOLING);
        $this->write('go.mod', "module github.com/jon4hz/jellysweep\n\ngo 1.24\n");
        $this->write('main.go', "package main\n\nfunc main() {}\n");

        $this->assertTrue($this->probe()->evaluate($this->context()));
        $this->assertSame('go', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_a_python_entry_point_keeps_it_python(): void
    {
        $this->write('requirements.txt', "flask\n");
        $this->write('app.py', "from flask import Flask\napp = Flask(__name__)\n");
        $this->write('package.json', '{"name":"assets","scripts":{"build":"tailwindcss -o static/app.css"}}');

        $this->assertFalse($this->probe()->evaluate($this->context()));
        $this->assertSame('python', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    /** With no other stack there is nothing better to offer; python still reports it has no entry point. */
    public function test_python_alone_is_still_python(): void
    {
        $this->write('requirements.txt', self::DOCS_TOOLING);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_rust_app_is_offered_to_rust(): void
    {
        $this->write('requirements.txt', "mkdocs\n");
        $this->write('Cargo.toml', "[package]\nname = \"app\"\nversion = \"0.1.0\"\n");

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }
}
