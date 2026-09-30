<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A Python app whose entry point is not app.py, main.py or server.py.
 *
 * SABnzbd starts from `SABnzbd.py`, Quasarr and Music Assistant from a
 * `[project.scripts]` console script. All three served the placeholder with
 * "No start command could be worked out". The fallbacks sit below every
 * existing rule, and neither guesses between several candidates.
 */
class EntryPointFallbackTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-pyentry-' . bin2hex(random_bytes(6));
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

    /** sabnzbd/sabnzbd: requirements.txt, one root script, a tool-only pyproject. */
    public function test_the_only_root_script_with_a_main_guard_is_run(): void
    {
        $this->write('requirements.txt', "cheroot\n");
        $this->write('pyproject.toml', "[tool.black]\nline-length = 120\n");
        $this->write('SABnzbd.py', "import sabnzbd\n\nif __name__ == \"__main__\":\n    main()\n");

        $this->assertSame('.venv/bin/python SABnzbd.py', PythonRuntime::startCommand($this->dir));
    }

    public function test_two_runnable_root_scripts_are_not_guessed_between(): void
    {
        $this->write('requirements.txt', "bottle\n");
        $this->write('Quasarr.py', "if __name__ == \"__main__\":\n    run()\n");
        $this->write('cli_tester.py', "if __name__ == \"__main__\":\n    main()\n");

        $this->assertStringContainsString(
            PythonRuntime::PLACEHOLDER_DIR,
            PythonRuntime::startCommand($this->dir)
        );
    }

    /** Packaging, test and Django files are not the application. */
    public function test_tooling_and_tests_are_not_candidates(): void
    {
        $this->write('requirements.txt', "flask\n");
        foreach (['setup.py', 'conftest.py', 'noxfile.py', 'test_app.py', 'app_test.py'] as $file) {
            $this->write($file, "if __name__ == \"__main__\":\n    pass\n");
        }
        $this->write('daemon.py', "if __name__ == \"__main__\":\n    serve()\n");

        $this->assertSame('.venv/bin/python daemon.py', PythonRuntime::startCommand($this->dir));
    }

    public function test_a_root_module_without_a_main_guard_is_not_run(): void
    {
        $this->write('requirements.txt', "flask\n");
        $this->write('helpers.py', "def helper():\n    pass\n");

        $this->assertStringContainsString(
            PythonRuntime::PLACEHOLDER_DIR,
            PythonRuntime::startCommand($this->dir)
        );
    }

    /**
     * rix1337/Quasarr: a uv project with a build backend and one console
     * script, beside three root scripts that each have a main guard.
     */
    public function test_the_only_console_script_is_run_once_the_project_is_installed(): void
    {
        $this->write('pyproject.toml', self::QUASARR);
        $this->write('uv.lock', "version = 1\n");
        foreach (['Quasarr.py', 'cli_tester.py', 'pre-commit.py'] as $file) {
            $this->write($file, "if __name__ == \"__main__\":\n    run()\n");
        }

        $this->assertSame('.venv/bin/quasarr', PythonRuntime::startCommand($this->dir));
        $this->assertStringNotContainsString(
            '--no-install-project',
            PythonRuntime::installCommand(['pyproject.toml' => true, 'uv.lock' => true], self::QUASARR)
        );
    }

    /** music-assistant/server: PEP 621, no lock, script `mass`; pip builds it. */
    public function test_a_pip_installed_project_runs_its_console_script(): void
    {
        $pyproject = "[project]\nname = \"music_assistant\"\nversion = \"0.0.0\"\n\n"
            . "[project.scripts]\nmass = \"music_assistant.__main__:main\"\n\n[tool.codespell]\nskip = \"*.json\"\n";
        $this->write('pyproject.toml', $pyproject);

        $this->assertSame('.venv/bin/mass', PythonRuntime::startCommand($this->dir));
    }

    public function test_of_several_scripts_the_one_named_after_the_project_is_run(): void
    {
        $this->write('pyproject.toml', "[project]\nname = \"Music-Assistant\"\n\n"
            . "[project.scripts]\nmass-cli = \"a:b\"\nmusic_assistant = \"a:main\"\n");

        $this->assertSame('.venv/bin/music_assistant', PythonRuntime::startCommand($this->dir));
    }

    public function test_several_scripts_none_the_projects_own_are_not_guessed_between(): void
    {
        $this->write('pyproject.toml', "[project]\nname = \"tools\"\n\n"
            . "[project.scripts]\nserve = \"a:b\"\nworker = \"a:c\"\n");

        $this->assertStringContainsString(
            PythonRuntime::PLACEHOLDER_DIR,
            PythonRuntime::startCommand($this->dir)
        );
    }

    /**
     * `pip install -r requirements.txt` never builds the project, so its
     * script never reaches the venv. Running it would crash-loop.
     */
    public function test_a_script_the_install_does_not_produce_is_not_run(): void
    {
        $this->write('requirements.txt', "bottle\n");
        $this->write('pyproject.toml', self::QUASARR);

        $this->assertStringContainsString(
            PythonRuntime::PLACEHOLDER_DIR,
            PythonRuntime::startCommand($this->dir)
        );
    }

    /** Without a build backend uv treats the project as virtual and installs no script. */
    public function test_a_uv_project_without_a_build_backend_has_no_script_to_run(): void
    {
        $this->write('pyproject.toml', "[project]\nname = \"x\"\n\n[project.scripts]\nx = \"x:main\"\n");
        $this->write('uv.lock', "version = 1\n");

        $this->assertStringContainsString(
            PythonRuntime::PLACEHOLDER_DIR,
            PythonRuntime::startCommand($this->dir)
        );
    }

    /** The conventional entry points keep precedence over both fallbacks. */
    public function test_an_existing_entry_point_still_wins(): void
    {
        $this->write('pyproject.toml', self::QUASARR);
        $this->write('main.py', "print('hi')\n");
        $this->write('Other.py', "if __name__ == \"__main__\":\n    run()\n");

        $this->assertSame('.venv/bin/python main.py', PythonRuntime::startCommand($this->dir));
    }

    /** rix1337/Quasarr @ 79573da, trimmed to what the engine reads. */
    private const QUASARR = <<<'TOML'
[project]
name = "quasarr"
dynamic = ["version"]
requires-python = ">=3.12"
dependencies = [
    "bottle>=0.13.4",
]

[project.scripts]
quasarr = "quasarr:run"

[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[dependency-groups]
dev = [
    "ruff>=0.15.0",
]
TOML;
}
