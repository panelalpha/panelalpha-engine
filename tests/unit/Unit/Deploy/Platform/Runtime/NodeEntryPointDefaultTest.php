<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A recipe's `node index.js` default is a guess, and `package.json`'s `main`
 * is the answer.
 *
 * CNCjs declares `main: ./dist/cncjs/server-cli.js` and no `start` script, so
 * the guess was used verbatim and the container restarted for ever on
 * `Cannot find module '/app/index.js'`. CapRover is the same shape.
 */
class NodeEntryPointDefaultTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/node-entry-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * The partial as `PlatformValues::applyJs()` builds it from a JS recipe:
     * the manifest's `{{js.start:…}}` already stripped to its default.
     */
    private function partial(string $defaultStart = 'node index.js'): array
    {
        return [
            'strategy' => 'express',
            'label' => 'Express',
            'runtime' => 'node',
            'port_hint' => 3000,
            'output_directory' => 'dist',
            'default_start' => $defaultStart,
            'default_build' => 'npm run build',
        ];
    }

    public function test_a_declared_main_replaces_the_index_js_guess(): void
    {
        $this->write('package.json', json_encode([
            'name' => 'svc',
            'main' => './dist/server.js',
        ]));
        $this->write('dist/server.js', '// entry');

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node dist/server.js', $resolved['start_command']);
    }

    /**
     * `bin` outranks `main`, and the bin named after the package outranks the
     * rest of the map.
     *
     * CNCjs's `main` exports a `launchServer` function and does nothing when
     * run, while `bin/cncjs` is what starts the server — which is what this
     * file's own docblock and the commit that added it both say. Picking the
     * first JSON key instead gave `bin/cnc`, and only JSON key order decided
     * it: a package whose extra CLI happens to sort first would have been
     * started instead of its server.
     */
    public function test_the_bin_named_after_the_package_outranks_main(): void
    {
        $this->write('package.json', json_encode([
            'name' => 'cncjs',
            'main' => './dist/cncjs/server-cli.js',
            'bin' => ['cnc' => './bin/cnc', 'cncjs' => './bin/cncjs'],
        ]));

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node bin/cncjs', $resolved['start_command']);
    }

    /** With no entry named for the package, the first is still the answer. */
    public function test_a_single_bin_is_taken_whatever_it_is_called(): void
    {
        $this->write('package.json', json_encode([
            'name' => 'acme-server',
            'main' => './lib/index.js',
            'bin' => ['serve' => './bin/serve'],
        ]));

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node bin/serve', $resolved['start_command']);
    }

    /**
     * A file that is really there beats a field that merely says something.
     *
     * The premise for replacing `node index.js` is "no start script *and no
     * index.js*". A plain express app that has one and also carries a stale
     * `main` — left from a package that no longer ships that file — booted
     * before this default existed and got MODULE_NOT_FOUND after.
     */
    public function test_an_existing_index_js_is_not_replaced_by_a_stale_main(): void
    {
        $this->write('package.json', json_encode(['name' => 'app', 'main' => 'lib/index.js']));
        $this->write('index.js', "require('http').createServer().listen(3000)\n");

        $resolved = NodeRuntime::finalizeProject(
            $this->partial(),
            $this->tmpDir,
            ['package.json' => true, 'index.js' => true]
        );

        $this->assertSame('node index.js', $resolved['start_command']);
    }

    /** The single-string `bin` spelling is the same statement. */
    public function test_a_string_bin_is_honoured(): void
    {
        $this->write('package.json', json_encode([
            'name' => 'x',
            'bin' => './cli.js',
        ]));

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node cli.js', $resolved['start_command']);
    }

    public function test_a_root_server_js_is_used_when_main_is_absent(): void
    {
        $this->write('package.json', json_encode(['name' => 'bare']));
        $this->write('server.js', '// entry');

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node server.js', $resolved['start_command']);
    }

    /** A project's own `start` script always wins, `main` or not. */
    public function test_a_start_script_still_beats_main(): void
    {
        $this->write('package.json', json_encode([
            'name' => 'wiki',
            'main' => 'wiki.js',
            'scripts' => ['start' => 'node server'],
        ]));
        $this->write('wiki.js', '// entry');

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('npm start', $resolved['start_command']);
    }

    /**
     * `main` is honoured even when the file is not in the checkout yet. It is
     * routinely something the *build* produces — CNCjs declares
     * `main: ./dist/cncjs/server-cli.js` and `dist/` does not exist at detection
     * time — so an existence check would reject the one correct answer.
     */
    public function test_a_main_the_build_will_produce_is_still_honoured(): void
    {
        $this->write('package.json', json_encode(['name' => 'x', 'main' => 'lib/entry.js']));

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node lib/entry.js', $resolved['start_command']);
    }

    /** `main` pointing out of the application is refused, not followed. */
    public function test_a_main_that_escapes_the_application_is_ignored(): void
    {
        $this->write('package.json', json_encode(['name' => 'x', 'main' => '../secrets.js']));

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node index.js', $resolved['start_command']);
    }

    /** An absolute `main` is not a path inside the application either. */
    public function test_an_absolute_main_is_ignored(): void
    {
        $this->write('package.json', json_encode(['name' => 'x', 'main' => '/etc/passwd']));

        $resolved = NodeRuntime::finalizeProject($this->partial(), $this->tmpDir, ['package.json' => true]);

        $this->assertSame('node index.js', $resolved['start_command']);
    }

    /** A recipe that names a literal command is not second-guessed. */
    public function test_a_manifest_default_that_is_not_the_guess_is_left_alone(): void
    {
        $this->write('package.json', json_encode(['name' => 'x', 'main' => 'app.js']));
        $this->write('app.js', '// entry');

        $resolved = NodeRuntime::finalizeProject(
            $this->partial('node server.mjs'),
            $this->tmpDir,
            ['package.json' => true]
        );

        $this->assertSame('node server.mjs', $resolved['start_command']);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->tmpDir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
