<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * What a Python repository with no entry point ends up serving.
 *
 * It used to be `python -m http.server` in the project directory, which
 * publishes every file in it with directory indexes. An InvenTree deploy
 * landed there and answered 200 on `/.git/config` over a public name with a
 * trusted certificate, while every engine signal reported success.
 */
class LastResortServerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-pyfallback-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_it_does_not_serve_the_project_directory(): void
    {
        $command = PythonRuntime::startCommand($this->dir);

        // The whole point: a --directory of our own, never the checkout.
        $this->assertStringContainsString('--directory', $command);
        $this->assertStringContainsString('.panelalpha-not-started', $command);
        $this->assertDoesNotMatchRegularExpression(
            '/http\.server\s+\d+\s*$/',
            $command,
            'http.server must not be left serving the working directory'
        );
    }

    public function test_it_still_answers_rather_than_crash_looping(): void
    {
        $command = PythonRuntime::startCommand($this->dir);

        $this->assertStringContainsString('http.server', $command);
        $this->assertStringContainsString((string) PythonRuntime::PORT, $command);
    }

    /** The page says why, so the account is diagnosable from a browser. */
    public function test_it_serves_a_page_that_explains_itself(): void
    {
        $command = PythonRuntime::startCommand($this->dir);

        $this->assertStringContainsString('index.html', $command);
        $this->assertStringContainsString('did not start', $command);
        $this->assertStringContainsString('panelalpha.yaml', $command);
    }

    /**
     * A real entry point is unaffected -- the fallback is the last resort and
     * must not shadow a project that can actually run.
     */
    public function test_a_project_with_an_entry_point_is_untouched(): void
    {
        file_put_contents($this->dir . '/app.py', "print('hi')\n");

        $command = PythonRuntime::startCommand($this->dir);

        $this->assertStringContainsString('app.py', $command);
        $this->assertStringNotContainsString('http.server', $command);

        unlink($this->dir . '/app.py');
    }
}
