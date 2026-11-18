<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * A FastAPI or Flask application whose object lives below the root, with its server declared.
 *
 * Decade-qiu/CookHero (`app/main.py`), databrickslabs/lakemeter-oss (`backend/app/main.py`) and
 * dualverse-ai/station (`web_interface/app.py`) declare uvicorn or gunicorn and are started as
 * `uvicorn app.main:app` from the root, yet served the placeholder with "No start command could be
 * worked out": only root modules and Django's layout were searched.
 */
class NestedApplicationObjectTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-pyapp-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        parent::tearDown();
    }

    private function remove(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            is_dir($dir.'/'.$entry) ? $this->remove($dir.'/'.$entry) : unlink($dir.'/'.$entry);
        }
        rmdir($dir);
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($this->dir.'/'.$path))) {
            mkdir(dirname($this->dir.'/'.$path), 0o755, true);
        }
        file_put_contents($this->dir.'/'.$path, $contents);
    }

    private function start(string $requirements): string
    {
        $this->write('requirements.txt', $requirements);

        return PythonRuntime::startCommand($this->dir, [$requirements]);
    }

    public function test_a_package_module_is_imported_from_the_root(): void
    {
        $this->write('app/__init__.py', '');
        $this->write('app/main.py', "from fastapi import FastAPI\nfrom app.config import settings\n\napp = FastAPI(\n)\n");

        $this->assertSame(
            '.venv/bin/uvicorn app.main:app --host ' . TestNetwork::ANY . ' --port 8000',
            $this->start("fastapi\nuvicorn[standard]\n")
        );
    }

    public function test_a_plain_directory_importing_its_siblings_is_put_on_the_path(): void
    {
        $this->write('backend/main.py', "from fastapi import FastAPI\nfrom routers import api\n\napp = FastAPI()\n");
        $this->write('backend/routers.py', "api = None\n");

        $this->assertSame(
            '.venv/bin/uvicorn --app-dir=backend main:app --host ' . TestNetwork::ANY . ' --port 8000',
            $this->start("fastapi\nuvicorn\n")
        );
    }

    public function test_a_directory_the_module_imports_by_name_is_part_of_the_module(): void
    {
        $this->write('backend/main.py', "from fastapi import FastAPI\nfrom backend.config import get_settings\n\napp = create_app()\n");

        $this->assertSame(
            '.venv/bin/uvicorn backend.main:app --host ' . TestNetwork::ANY . ' --port 8000',
            $this->start("fastapi\nuvicorn\n")
        );
    }

    public function test_a_package_below_a_plain_directory_is_imported_from_that_directory(): void
    {
        $this->write('backend/app/__init__.py', '');
        $this->write('backend/app/main.py', "from fastapi import FastAPI\nfrom app.api import router\n\napp = FastAPI()\n");

        $this->assertSame(
            '.venv/bin/uvicorn --app-dir=backend app.main:app --host ' . TestNetwork::ANY . ' --port 8000',
            $this->start("fastapi\nuvicorn\n")
        );
    }

    public function test_a_flask_application_is_served_by_gunicorn(): void
    {
        $this->write('web_interface/app.py', "from flask import Flask\n\napp = Flask(__name__)\n");

        $this->assertSame(
            '.venv/bin/gunicorn --bind ' . TestNetwork::ANY . ':8000 --timeout 120 --pythonpath=web_interface app:app',
            $this->start("flask\ngunicorn\nuvicorn\n")
        );
    }

    public function test_a_package_init_is_imported_as_the_package(): void
    {
        $this->write('web/admin/__init__.py', "from flask import Flask\n\napp = Flask(__name__)\n");

        $this->assertSame(
            '.venv/bin/gunicorn --bind ' . TestNetwork::ANY . ':8000 --timeout 120 --pythonpath=web admin:app',
            $this->start("flask\ngunicorn\n")
        );
    }

    public function test_an_asgi_application_is_not_handed_to_gunicorn(): void
    {
        $this->write('app/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, $this->start("fastapi\ngunicorn\n"));
    }

    public function test_nothing_is_served_without_a_declared_server(): void
    {
        $this->write('app/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, $this->start("fastapi\n"));
    }

    public function test_two_equally_likely_applications_are_not_guessed_between(): void
    {
        $this->write('services/admin/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");
        $this->write('services/api/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, $this->start("fastapi\nuvicorn\n"));
    }

    public function test_the_shallower_and_conventionally_named_module_wins(): void
    {
        $this->write('core/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");
        $this->write('core/studio_main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");
        $this->write('services/runtime/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");

        $this->assertSame(
            '.venv/bin/uvicorn --app-dir=core main:app --host ' . TestNetwork::ANY . ' --port 8000',
            $this->start("fastapi\nuvicorn\n")
        );
    }

    public function test_tests_and_examples_are_not_the_application(): void
    {
        $this->write('tests/app.py', "from flask import Flask\n\napp = Flask(__name__)\n");
        $this->write('examples/demo/app.py', "from flask import Flask\n\napp = Flask(__name__)\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, $this->start("flask\ngunicorn\n"));
    }

    public function test_an_application_bound_inside_a_function_is_not_module_level(): void
    {
        $this->write('api/server.py', "from fastapi import FastAPI\n\ndef build():\n    app = FastAPI()\n    return app\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, $this->start("fastapi\nuvicorn\n"));
    }

    public function test_a_runnable_root_script_still_comes_first(): void
    {
        $this->write('run.py', "if __name__ == \"__main__\":\n    serve()\n");
        $this->write('app/main.py', "from fastapi import FastAPI\n\napp = FastAPI()\n");

        $this->assertSame('.venv/bin/python run.py', $this->start("fastapi\nuvicorn\n"));
    }
}
