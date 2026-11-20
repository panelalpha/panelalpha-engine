<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;
use Tests\Support\SystemPackagesFixtures;
use Tests\Support\TestNetwork;

/**
 * What a Python image needs installed before pip can build anything.
 *
 * The case that made this necessary: a Django project with `psycopg2-binary` in
 * requirements.txt died on `Error: pg_config executable not found` — pip had no
 * wheel for that interpreter, fell back to the C source, and python:3.12-slim
 * has neither a compiler nor libpq headers.
 */
class SystemPackagesTest extends TestCase
{
    use SystemPackagesFixtures;

    // -------------------------------------------------------------------------
    // How the application is started
    // -------------------------------------------------------------------------

    /**
     * The bug this fixes: a Flask quickstart's app.py ends in a bare
     * `app.run()`, which binds loopback port 5000. Started that way the container
     * comes up, stays up, and answers nothing on the port the recipe published
     * -- reported `partial`, with an application that looks healthy in `docker
     * ps`. Azure's Flask quickstart ships gunicorn for exactly this reason.
     */
    public function test_a_declared_gunicorn_is_preferred_over_running_the_module(): void
    {
        $dir = $this->project([
            'app.py' => "from flask import Flask\napp = Flask(__name__)\n\nif __name__ == '__main__':\n    app.run()\n",
        ]);

        $command = PythonRuntime::startCommand($dir, ["Flask==3.1.0\ngunicorn\n"]);

        $this->assertStringContainsString('gunicorn', $command);
        $this->assertStringContainsString('--bind ' . TestNetwork::ANY . ':' . PythonRuntime::PORT, $command);
        $this->assertStringContainsString('app:app', $command);
    }

    public function test_uvicorn_is_used_for_an_asgi_project(): void
    {
        $dir = $this->project(['main.py' => "from fastapi import FastAPI\napp = FastAPI()\n"]);

        $command = PythonRuntime::startCommand($dir, ["fastapi\nuvicorn\n"]);

        $this->assertStringContainsString('uvicorn main:app', $command);
        $this->assertStringContainsString('--host ' . TestNetwork::ANY, $command);
    }

    /**
     * gunicorn wins when both are listed: that project is normally serving a
     * WSGI app through gunicorn with a uvicorn worker class, and gunicorn is
     * the one that takes the bind address.
     */
    public function test_gunicorn_wins_when_both_are_declared(): void
    {
        $dir = $this->project(['app.py' => "app = 1\n"]);

        $this->assertStringContainsString(
            'gunicorn',
            PythonRuntime::startCommand($dir, ["gunicorn\nuvicorn\n"])
        );
    }

    /**
     * Without a server the project ships, running the module is still the
     * right answer -- the engine does not install one on its behalf.
     */
    public function test_a_project_with_no_declared_server_still_runs_its_module(): void
    {
        $dir = $this->project(['app.py' => "app = 1\n"]);

        $this->assertSame(
            PythonRuntime::VENV . '/bin/python app.py',
            PythonRuntime::startCommand($dir, ["flask\n"])
        );
    }

    /**
     * The conservative half, and the one that matters most: `module:app` names
     * an import target, so claiming one that does not exist turns a working
     * `python app.py` into an immediate crash — worse than the binding problem
     * this feature exists to fix.
     */
    public function test_no_server_is_used_when_there_is_no_app_object_to_point_at(): void
    {
        $dir = $this->project(['app.py' => "def main():\n    pass\n"]);

        $command = PythonRuntime::startCommand($dir, ["gunicorn\n"]);

        $this->assertStringNotContainsString('gunicorn', $command);
        $this->assertSame(PythonRuntime::VENV . '/bin/python app.py', $command);
    }

    public function test_a_project_with_nothing_recognisable_falls_back(): void
    {
        $dir = $this->project([]);

        $this->assertStringContainsString('http.server', PythonRuntime::startCommand($dir, []));
    }

    /**
     * @param array<string, string> $files
     */
    private function project(array $files): string
    {
        $dir = sys_get_temp_dir() . '/pa-pystart-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }
        $this->dirs[] = $dir;

        return $dir;
    }
}
