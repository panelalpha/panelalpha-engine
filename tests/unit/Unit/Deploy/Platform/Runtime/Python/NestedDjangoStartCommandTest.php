<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * NetBox, and the whole family of Django projects laid out the way
 * `django-admin startproject` writes them into a subdirectory.
 *
 * The start command was not resolved at all: detection reported `deployable:
 * false`, "No start command could be worked out", and the deploy served the
 * PanelAlpha placeholder instead of the application. The reason was a
 * root-only search for `wsgi.py`.
 *
 * NetBox ships `netbox/manage.py` beside `netbox/netbox/wsgi.py` -- there is
 * no `wsgi.py` at the repository root and never was one -- so the root lookup
 * found nothing, no module was named, and the project fell through to the
 * last-resort server. `gunicorn` is a declared dependency in its
 * requirements.txt, so the server to import the module *was* there; only the
 * module was invisible.
 *
 * The fix is the search Django's own layout implies: the package directory
 * holding `wsgi.py` sits beside the `manage.py` that runs it, and the module is
 * named from where that package actually is -- `netbox.wsgi` with `netbox/` on
 * the import path, exactly what NetBox's own systemd unit passes
 * (`--pythonpath /opt/netbox/netbox netbox.wsgi`).
 */
class NestedDjangoStartCommandTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function project(array $files): string
    {
        $dir = sys_get_temp_dir() . '/pa-pydjango-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    /** The WSGI module Django generates. */
    private const WSGI = <<<'PY'
import os

from django.core.wsgi import get_wsgi_application

os.environ.setdefault("DJANGO_SETTINGS_MODULE", "netbox.settings")

application = get_wsgi_application()
PY;

    /**
     * The exact layout and the exact failure: NetBox's `netbox/manage.py`
     * beside `netbox/netbox/wsgi.py`, with gunicorn in requirements.txt.
     *
     * `--pythonpath netbox` is what makes `netbox.wsgi` importable at all --
     * the outer `netbox/` carries no `__init__.py`, so from the repository root
     * the module does not resolve as `netbox.wsgi`.
     */
    public function test_a_wsgi_module_in_a_subdirectory_is_found(): void
    {
        $dir = $this->project([
            'netbox/manage.py' => "#!/usr/bin/env python3\n",
            'netbox/netbox/__init__.py' => '',
            'netbox/netbox/wsgi.py' => self::WSGI,
        ]);

        $command = PythonRuntime::startCommand($dir, ["Django==6.1\ngunicorn==26.2.0\n"]);

        $this->assertStringContainsString('gunicorn', $command);
        $this->assertStringContainsString('netbox.wsgi:application', $command);
        $this->assertStringContainsString("--pythonpath 'netbox'", $command);
        $this->assertStringContainsString('--bind 0.0.0.0:' . PythonRuntime::PORT, $command);
    }

    /**
     * The same depth in the classic shape: `manage.py` at the root beside
     * `proj/wsgi.py`. Already worked, and must keep working -- but note the
     * module is `proj.wsgi`, not `wsgi`, because the package is what makes the
     * import target unique.
     */
    public function test_the_root_level_django_layout_is_unchanged(): void
    {
        $dir = $this->project([
            'manage.py' => "#!/usr/bin/env python3\n",
            'proj/__init__.py' => '',
            'proj/wsgi.py' => "application = get_wsgi_application()\n",
        ]);

        $command = PythonRuntime::startCommand($dir, ["gunicorn\n"]);

        $this->assertStringContainsString('proj.wsgi:application', $command);
        // Importable from the project root already, so no search-path flag.
        $this->assertStringNotContainsString('--pythonpath', $command);
    }

    /**
     * The ASGI spelling gets uvicorn's flag for the same job. A project that
     * ships both is served by gunicorn (see SystemPackagesTest); one that ships
     * only asgi.py with uvicorn is served through `--app-dir`.
     */
    public function test_a_nested_asgi_project_uses_uvicorn_and_app_dir(): void
    {
        $dir = $this->project([
            'svc/manage.py' => "#!/usr/bin/env python3\n",
            'svc/svc/__init__.py' => '',
            'svc/svc/asgi.py' => "application = get_asgi_application()\n",
        ]);

        $command = PythonRuntime::startCommand($dir, ["uvicorn\n"]);

        $this->assertStringContainsString('svc.asgi:application', $command);
        $this->assertStringContainsString("--app-dir 'svc'", $command);
        $this->assertStringContainsString('--host 0.0.0.0', $command);
    }

    /**
     * The conservative half, unchanged and still the point of the placeholder:
     * a nested WSGI module with no server to run it is *not* an entry point.
     * Naming one would send the project down the run-it-as-a-script path, which
     * imports and exits, and the account would crash-loop with nothing bound --
     * worse than the page that says what is missing.
     */
    public function test_a_nested_wsgi_module_with_no_server_still_is_not_runnable(): void
    {
        $dir = $this->project([
            'app/manage.py' => "#!/usr/bin/env python3\n",
            'app/app/__init__.py' => '',
            'app/app/wsgi.py' => "application = 1\n",
        ]);

        $command = PythonRuntime::startCommand($dir);

        $this->assertStringNotContainsString('gunicorn', $command);
        $this->assertStringContainsString('.panelalpha-not-started', $command);
    }

    /**
     * A `wsgi.py` inside a package with no `manage.py` anywhere is not a
     * project -- it is a library's own test fixture, or a vendored app. Django
     * never writes one without its manage.py beside it, and treating this as an
     * entry point is exactly how a wrong module gets handed to gunicorn.
     */
    public function test_a_wsgi_module_without_a_manage_py_is_not_a_project(): void
    {
        $dir = $this->project([
            'vendor/lib/__init__.py' => '',
            'vendor/lib/wsgi.py' => "application = 1\n",
        ]);

        $command = PythonRuntime::startCommand($dir, ["gunicorn\n"]);

        $this->assertStringNotContainsString('gunicorn', $command);
        $this->assertStringContainsString('.panelalpha-not-started', $command);
    }

    /**
     * A root entry point still wins over a nested one: a project that ships
     * `main.py` at its root is running that, whatever else is in the tree.
     */
    public function test_a_root_entry_point_still_wins_over_a_nested_project(): void
    {
        $dir = $this->project([
            'main.py' => "print('hi')\n",
            'app/manage.py' => "#!/usr/bin/env python3\n",
            'app/app/__init__.py' => '',
            'app/app/wsgi.py' => "application = 1\n",
        ]);

        $this->assertStringContainsString('python main.py', PythonRuntime::startCommand($dir, ["gunicorn\n"]));
    }
}
