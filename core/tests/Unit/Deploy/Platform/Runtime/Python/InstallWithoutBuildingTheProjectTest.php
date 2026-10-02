<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * An application is not a distribution: install what it depends on, not what
 * it is.
 *
 * Every failure this covers is the same build backend being asked to produce
 * a wheel of a project that is not a package. setuptools refuses a flat root
 * with sibling packages ("Multiple top-level packages discovered"), hatchling
 * refuses a PEP 621 project with no version, and a root that is only
 * configuration refuses to have a project at all. None of those projects is
 * wrong -- none of them is a library -- and all of them still have to get
 * their dependencies.
 *
 * The rule: read the resolution the project committed (uv.lock, poetry.lock)
 * or the requirement list (requirements.txt) and install that, and build the
 * project itself only when a project is actually declared and either no lock
 * states its dependencies or its uv lock sits beside a build backend -- the
 * cases where a console entry point can exist.
 */
class InstallWithoutBuildingTheProjectTest extends TestCase
{
    public function test_a_uv_project_installs_its_lock_without_building_itself(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true, 'uv.lock' => true],
            self::BITCART
        );

        $this->assertStringContainsString('uv sync --frozen --no-install-project', $command);
        $this->assertStringNotContainsString('pip install .', $command);
        // The interpreter the start command runs through is the venv uv was
        // pointed at; without VIRTUAL_ENV uv makes its own and nothing serves.
        $this->assertStringContainsString('VIRTUAL_ENV="$PWD/.venv"', $command);
        // Not `--no-dev`: bitcart's fastapi and uvicorn are in a `web` group
        // and its gunicorn in `production`, reachable only through the `dev`
        // umbrella, so a no-dev sync installs two packages and the app cannot
        // start. uv's default is the project's own groups.
        $this->assertStringNotContainsString('--no-dev', $command);
    }

    /**
     * ajslater/codex names `uv_build` as its backend, and its `bin/manage.py`
     * imports the `codex` package: without the project installed every deploy
     * died with `ModuleNotFoundError: No module named 'codex'` (issue #2076).
     */
    public function test_a_uv_project_with_a_build_backend_installs_itself(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true, 'uv.lock' => true],
            "[project]\nname = \"codex\"\nversion = \"1.8.0\"\n\n"
                . "[project.scripts]\ncodex = \"codex.run:main\"\n\n"
                . "[build-system]\nrequires = [\"uv_build~=0.12.0\"]\nbuild-backend = \"uv_build\"\n"
        );

        $this->assertStringEndsWith('uv sync --frozen', $command);
    }

    /**
     * uv honours .python-version and, when the image's Python differs, links
     * .venv to a CPython it downloads outside /app (index-tts: `.venv/bin/indextts:
     * not found` at run time). The sync is pinned to the image's interpreter.
     */
    public function test_uv_sync_stays_on_the_image_interpreter(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true, 'uv.lock' => true],
            "[project]\nname = \"indextts\"\nversion = \"2.0.0\"\n\n[build-system]\nbuild-backend = \"hatchling.build\"\n"
        );

        $this->assertStringContainsString(
            'UV_PYTHON_DOWNLOADS=never UV_PYTHON="$(command -v python)" VIRTUAL_ENV="$PWD/.venv" .venv/bin/uv sync --frozen',
            $command
        );
    }

    /**
     * bitcart's root is `api/`, `conf/`, `daemons/`, `modules/`, `migrations/`
     * and `static/` -- six sibling packages, which is exactly the flat layout
     * setuptools refuses to guess a distribution out of. The app is started
     * straight from the checkout (`gunicorn main:app`), so the root never
     * needed to be importable.
     */
    public function test_a_flat_layout_root_is_not_built(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true, 'uv.lock' => true],
            self::BITCART
        );

        $this->assertStringNotContainsString('pip install .', $command);
    }

    /**
     * Composio's root pyproject has no `[project]` table at all -- the
     * packages live in the `python/` members the uv workspace lists. The
     * lockfile already resolves them, and `--no-install-project` is what keeps
     * uv from trying to build the root.
     */
    public function test_a_workspace_root_with_no_project_table_installs_from_the_lock(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true, 'uv.lock' => true],
            "[tool.uv.workspace]\nmembers = [\"python\"]\n"
        );

        $this->assertStringContainsString('uv sync --frozen --no-install-project', $command);
    }

    /**
     * A pyproject holding only tool configuration or a `[build-system]` has no
     * project in it to install. Dolibarr, Frigate, Mail-in-a-Box and Mailu all
     * reach the Python platform through such a file, and `pip install .` on it
     * dies in setuptools with
     *
     *   configuration error: `project` must contain ['version'] properties
     *
     * The venv is still made, because the start command -- the placeholder
     * included -- runs through `.venv/bin/python`.
     */
    public function test_a_pyproject_with_no_project_and_no_dependencies_only_creates_the_venv(): void
    {
        foreach ([self::DOLIBARR, self::FRIGATE, self::MAILINABOX, self::MAILU] as $pyproject) {
            $command = PythonRuntime::installCommand(['pyproject.toml' => true], $pyproject);

            $this->assertSame('python -m venv .venv', $command, $pyproject);
        }
    }

    /**
     * `[project.scripts]` is a table of its own, not the `[project]` table
     * that declares the package -- a match on a prefix would read mopidy as a
     * project with no dependencies and skip the install its entry point needs.
     */
    public function test_a_subtable_does_not_count_as_the_project_table(): void
    {
        // A prefix match would read `[project.scripts]` as `[project]` and
        // leave a scripts-only document looking like a declared package. It is
        // not one -- there is no `[project]` to install -- and neither is
        // Poetry's own `[tool.poetry.scripts]` the `[tool.poetry]` table.
        $this->assertTrue(PythonRuntime::declaresNothingToInstall(
            ['pyproject.toml' => true],
            "[project.scripts]\nmopidy = \"mopidy.__main__:main\"\n"
        ));
        $this->assertTrue(PythonRuntime::declaresNothingToInstall(
            ['pyproject.toml' => true],
            "[tool.poetry.scripts]\nmopidy = \"mopidy.__main__:main\"\n"
        ));
    }

    /** mopidy's real pyproject: a `[project]` declaring the package and a script. */
    public function test_a_project_declaring_itself_reaches_the_pip_install(): void
    {
        $this->assertFalse(PythonRuntime::declaresNothingToInstall(
            ['pyproject.toml' => true],
            "[project]\nname = \"mopidy\"\n\n[project.scripts]\nmopidy = \"mopidy.__main__:main\"\n"
        ));
    }

    /**
     * The dependencies can still live in setup.py/setup.cfg beside a tool-only
     * pyproject. This reader does not parse either, so it must not conclude
     * from the pyproject alone that there is nothing to install.
     */
    public function test_a_setup_manifest_beside_the_pyproject_keeps_the_install(): void
    {
        $this->assertFalse(PythonRuntime::declaresNothingToInstall(
            ['pyproject.toml' => true, 'setup.py' => true],
            "[tool.ruff]\nline-length = 100\n"
        ));
        $this->assertFalse(PythonRuntime::declaresNothingToInstall(
            ['pyproject.toml' => true, 'setup.cfg' => true],
            "[tool.ruff]\nline-length = 100\n"
        ));
    }

    /**
     * A project that states a PEP 621 package and has no lockfile keeps the
     * pip install: there is no lock to read its dependencies from, and the
     * project is genuinely installable. Newspapers is this shape and it
     * deploys -- the build produces the wheel its console entry point needs.
     */
    public function test_a_pep621_project_with_no_lock_still_installs_itself(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true],
            "[project]\nname = \"newspipe\"\nversion = \"12.2.2\"\ndependencies = [\"Flask\"]\n"
        );

        $this->assertSame('python -m venv .venv && .venv/bin/pip install .', $command);
    }

    /** A console script is a declared package's reason to be built. */
    public function test_a_project_declaring_a_script_is_still_built(): void
    {
        $command = PythonRuntime::installCommand(
            ['pyproject.toml' => true],
            "[project]\nname = \"fava\"\nversion = \"1.0.0\"\ndynamic = [\"version\"]\n"
                . "[project.scripts]\nfava = \"fava.cli:main\"\n"
        );

        $this->assertSame('python -m venv .venv && .venv/bin/pip install .', $command);
    }

    /** requirements.txt is a plain list and keeps winning over everything. */
    public function test_requirements_txt_still_takes_precedence_over_a_lock(): void
    {
        $command = PythonRuntime::installCommand(
            ['requirements.txt' => true, 'pyproject.toml' => true, 'uv.lock' => true],
            self::BITCART
        );

        $this->assertStringContainsString('pip install -r requirements.txt', $command);
        $this->assertStringNotContainsString('uv sync', $command);
    }

    /**
     * A Poetry application in non-package mode is still the `poetry install
     * --no-root` case -- the lockfile decides, not uv, and the fix that
     * shipped first is not displaced by this one.
     */
    public function test_non_package_poetry_still_wins_over_everything_below_it(): void
    {
        $poetry = "[tool.poetry]\nname = \"activitywatch\"\nversion = \"0.14.0\"\npackage-mode = false\n";

        $command = PythonRuntime::installCommand(['pyproject.toml' => true], $poetry);

        $this->assertStringContainsString('poetry install --no-root', $command);
        $this->assertStringNotContainsString('pip install .', $command);
    }

    /** bitcart/bitcart @ bf710da: PEP 621 with a flat root, and a uv.lock. */
    private const BITCART = <<<'TOML'
[project]
name = "bitcart-api"
version = "1.0.0"
requires-python = ">=3.12"
dependencies = ["python-decouple", "rust-just>=1.46.0"]
TOML;

    /** Dolibarr's pyproject is codespell/yamlfix/sqlfluff config; the app is PHP. */
    private const DOLIBARR = <<<'TOML'
[build-system]
requires = ["setuptools>=61.2"]
build-backend = "setuptools.build_meta"

[tool.codespell]
skip = "*/.*/*,*/langs/*"

[tool.setuptools]
include-package-data = false
TOML;

    /** Frigate's is ruff config; the application is an embedded Go/Python image. */
    private const FRIGATE = <<<'TOML'
[tool.ruff]
target-version = "py311"

[tool.ruff.lint]
ignore = ["E501","E711"]
TOML;

    /** Mail-in-a-Box's is ruff config; the application is a system installer. */
    private const MAILINABOX = <<<'TOML'
[tool.ruff]
line-length = 320

[tool.ruff.lint]
select = ["F", "E4"]
TOML;

    /** Mailu's is towncrier config; the application is a compose stack. */
    private const MAILU = <<<'TOML'
[tool.towncrier]
    package = "towncrier"
    title_format = "v{version} - {project_date}"
TOML;
}
