<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\NotAWebAppProbe;

/**
 * A Node package that is an editor extension rather than a server.
 *
 * These are ordinary npm packages, so every file-based rule says "Node app".
 * They build cleanly and then crash on `require('vscode')`, which reads to a
 * user as a broken deploy rather than as a project that was never a web app.
 */
class NotAWebAppProbeTest extends ProbeTestCase
{
    private function probe(): NotAWebAppProbe
    {
        return new NotAWebAppProbe();
    }

    public function test_an_engines_vscode_constraint_is_the_tell(): void
    {
        $this->writeJson('package.json', [
            'name' => 'my-extension',
            'engines' => ['vscode' => '^1.85.0'],
        ]);

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    public function test_an_extension_entrypoint_is_the_tell(): void
    {
        $this->writeJson('package.json', [
            'name' => 'my-extension',
            'main' => './out/extension.js',
        ]);

        $this->assertTrue($this->probe()->evaluate($this->context()));
    }

    public function test_an_ordinary_server_package_is_a_web_app(): void
    {
        $this->writeJson('package.json', [
            'name' => 'api',
            'main' => 'server.js',
            'engines' => ['node' => '>=20'],
            'dependencies' => ['express' => '^4.18.0'],
        ]);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_main_that_merely_ends_in_js_is_not_an_extension(): void
    {
        // `extension.js` has to be the file itself, not a substring: an app
        // whose entrypoint is `src/my-extension.js` is still a server.
        $this->writeJson('package.json', ['main' => 'src/my-extension.js']);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_an_empty_vscode_constraint_does_not_count(): void
    {
        $this->writeJson('package.json', ['engines' => ['vscode' => '']]);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_project_with_no_package_json_is_not_an_extension(): void
    {
        $this->write('main.go', 'package main');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }
}
