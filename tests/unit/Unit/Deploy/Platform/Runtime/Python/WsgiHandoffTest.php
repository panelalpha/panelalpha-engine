<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A file called wsgi.py names a callable for a server to import; it is not a
 * program. CKAN's assigns `application` and binds nothing, and the engine ran
 * `python wsgi.py` -- which imported the module, exited, and left the account
 * restart-looping with the port unbound.
 *
 * Two things were wrong. The callable was looked for under the name `app`
 * only, so the WSGI spelling that Django generates was invisible; and when
 * that lookup failed the file was run as a script anyway.
 */
class WsgiHandoffTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-pywsgi-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    /** What Django generates, and what CKAN writes. */
    public function test_the_wsgi_spelling_of_the_callable_is_found(): void
    {
        $this->write('wsgi.py', "application = get_wsgi_application()\n");

        $command = PythonRuntime::startCommand($this->dir, ["gunicorn==21.2.0\n"]);

        $this->assertStringContainsString('gunicorn', $command);
        $this->assertStringContainsString('wsgi:application', $command);
        $this->assertStringContainsString('--bind 0.0.0.0:8000', $command);
    }

    public function test_an_asgi_callable_is_found_the_same_way(): void
    {
        $this->write('asgi.py', "application = get_asgi_application()\n");

        $command = PythonRuntime::startCommand($this->dir, ["uvicorn\n"]);

        $this->assertStringContainsString('uvicorn asgi:application', $command);
        $this->assertStringContainsString('--host 0.0.0.0', $command);
    }

    /**
     * CKAN's case: a WSGI module and no server in requirements.txt. Running it
     * cannot work, so the placeholder is the honest answer -- it binds the
     * port, publishes nothing of the checkout, and `not-placeholder` reports
     * it unhealthy instead of the deploy claiming success.
     */
    public function test_a_wsgi_module_with_no_server_is_not_run_as_a_script(): void
    {
        $this->write('wsgi.py', "from ckan.config.middleware import make_app\napplication = make_app(config)\n");

        $command = PythonRuntime::startCommand($this->dir);

        $this->assertStringNotContainsString('python wsgi.py', $command);
        $this->assertStringContainsString('.panelalpha-not-started', $command);
    }

    /** A wsgi.py that says it is also a script is taken at its word. */
    public function test_a_main_guard_makes_it_runnable_again(): void
    {
        $this->write('wsgi.py', "application = x\nif __name__ == '__main__':\n    application.run()\n");

        $this->assertStringContainsString('python wsgi.py', PythonRuntime::startCommand($this->dir));
    }

    /** The `app` spelling still wins where a project uses it. */
    public function test_the_app_spelling_still_works(): void
    {
        $this->write('app.py', "app = Flask(__name__)\n");

        $this->assertStringContainsString('app:app', PythonRuntime::startCommand($this->dir, ["gunicorn\n"]));
    }

    /** An ordinary script with no declared server is still just run. */
    public function test_a_plain_entry_point_is_untouched(): void
    {
        $this->write('main.py', "print('hi')\n");

        $this->assertStringContainsString('python main.py', PythonRuntime::startCommand($this->dir));
    }
}
