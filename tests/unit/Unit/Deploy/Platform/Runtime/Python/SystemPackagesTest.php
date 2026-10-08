<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\Platform\Runtime\Python\SystemPackages;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

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
    public function test_a_compiler_is_always_present(): void
    {
        $packages = SystemPackages::for(["flask\n"]);

        $this->assertContains('build-essential', $packages);
        // Many setup.py builds shell out to pkg-config, and its absence reads
        // as a missing library rather than a missing tool.
        $this->assertContains('pkg-config', $packages);
    }

    /** The exact failure this fixes. */
    public function test_psycopg2_binary_gets_the_postgres_headers(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for(["Django\npsycopg2-binary==2.9.9\n"]));
    }

    public function test_psycopg2_from_source_gets_them_too(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for(["psycopg2==2.9.9\n"]));
    }

    public function test_a_mysql_project_gets_the_mysql_headers(): void
    {
        $packages = SystemPackages::for(["Django\nmysqlclient\n"]);

        $this->assertContains('default-libmysqlclient-dev', $packages);
    }

    public function test_pillow_gets_its_image_libraries(): void
    {
        $packages = SystemPackages::for(["Pillow==11.0.0\n"]);

        $this->assertContains('libjpeg-dev', $packages);
        $this->assertContains('zlib1g-dev', $packages);
    }

    /**
     * Postgres is the guess when nothing is recognised — Django's default, and
     * an unused -dev package costs host build time once rather than a failed
     * deploy in an account.
     */
    public function test_an_unrecognised_project_still_gets_a_database_client(): void
    {
        $this->assertContains('libpq-dev', SystemPackages::for(["flask\ngunicorn\n"]));
    }

    /**
     * Distribution names are substrings of each other constantly, so matching
     * is bounded. `psycopg` must not fire inside `psycopg2-binary` and add a
     * package the more specific entry already covers, and a comment naming an
     * unrelated project must not add anything at all.
     */
    public function test_matching_is_bounded_to_whole_distribution_names(): void
    {
        $packages = SystemPackages::for(["# see https://example.com/not-pillow-really\nflask\n"]);

        $this->assertNotContains('libjpeg-dev', $packages);
    }

    public function test_the_set_is_deterministic_regardless_of_manifest_order(): void
    {
        $a = SystemPackages::for(["psycopg2-binary\nPillow\n"]);
        $b = SystemPackages::for(["Pillow\npsycopg2-binary\n"]);

        $this->assertSame(
            PythonBaseImage::normalizePackages($a),
            PythonBaseImage::normalizePackages($b)
        );
    }

    // -------------------------------------------------------------------------
    // The image built from them
    // -------------------------------------------------------------------------

    public function test_the_tag_is_derived_from_the_official_python_tag(): void
    {
        $tag = PythonBaseImage::tag('python:3.12-slim', ['build-essential', 'libpq-dev']);

        $this->assertNotNull($tag);
        $this->assertStringStartsWith(PythonBaseImage::repository() . ':3.12-slim-pa', $tag);
    }

    public function test_a_changed_package_set_changes_the_tag(): void
    {
        $this->assertNotSame(
            PythonBaseImage::tag('python:3.12-slim', ['build-essential']),
            PythonBaseImage::tag('python:3.12-slim', ['build-essential', 'libpq-dev'])
        );
    }

    public function test_no_tag_for_images_we_do_not_build(): void
    {
        $this->assertNull(PythonBaseImage::tag('ghcr.io/acme/python:3.12', ['build-essential']));
        $this->assertNull(PythonBaseImage::tag('python:3.12-slim', []));
        // A long tail of unusual libraries is not worth another image on the
        // host; that project installs its own.
        $this->assertNull(PythonBaseImage::tag('python:3.12-slim', array_map(
            static fn (int $i): string => "lib{$i}-dev",
            range(1, PythonBaseImage::MAX_PACKAGES + 1)
        )));
    }

    /**
     * Nothing publishes `panelalpha/python`, so a tag we fail to recognise as
     * ours goes to a registry that 404s and then to a pull inside the
     * account's nested NAT.
     */
    public function test_the_built_tag_is_recognised_as_ours(): void
    {
        $tag = (string) PythonBaseImage::tag('python:3.12-slim', ['build-essential', 'libpq-dev']);

        $this->assertTrue(BuiltImage::isOurs($tag));
        $this->assertSame('python', BuiltImage::runtimeFor($tag));
        $this->assertSame('python:3.12-slim', BuiltImage::sourceImage($tag));
        $this->assertSame('python:3.12-slim', PythonBaseImage::sourceImage($tag));
    }

    public function test_a_ruby_tag_is_not_a_python_one(): void
    {
        $this->assertNull(PythonBaseImage::sourceImage('panelalpha/ruby:3.3-slim-bookworm-pa0c53db49'));
    }

    public function test_the_dockerfile_installs_what_was_asked_for(): void
    {
        $dockerfile = PythonBaseImage::dockerfile('python:3.12-slim', ['libpq-dev', 'build-essential']);

        $this->assertStringContainsString('FROM python:3.12-slim', $dockerfile);
        $this->assertStringContainsString('build-essential libpq-dev', $dockerfile);
    }

    // -------------------------------------------------------------------------
    // How the application is started
    // -------------------------------------------------------------------------

    /**
     * The bug this fixes: a Flask quickstart's app.py ends in a bare
     * `app.run()`, which binds 127.0.0.1:5000. Started that way the container
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
        $this->assertStringContainsString('--bind 0.0.0.0:' . PythonRuntime::PORT, $command);
        $this->assertStringContainsString('app:app', $command);
    }

    public function test_uvicorn_is_used_for_an_asgi_project(): void
    {
        $dir = $this->project(['main.py' => "from fastapi import FastAPI\napp = FastAPI()\n"]);

        $command = PythonRuntime::startCommand($dir, ["fastapi\nuvicorn\n"]);

        $this->assertStringContainsString('uvicorn main:app', $command);
        $this->assertStringContainsString('--host 0.0.0.0', $command);
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

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($dir . '/' . $entry);
                }
            }
            rmdir($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }
}
