<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A Poetry project in non-package mode is an application, not a package.
 *
 * `pip install .` asks poetry-core to build a wheel of the project itself, and
 * poetry-core refuses by design:
 *
 *   RuntimeError: Building a package is not possible in non-package mode.
 *
 * That was the install step of an entire family of apps — ActivityWatch
 * among them — and nothing about the project was wrong: only the "build
 * a distribution of the root" half of the install was impossible. The engine
 * now installs such a project with `poetry install --no-root`, which resolves
 * the lockfile and installs the dependencies while skipping the project.
 */
class NonPackagePoetryInstallTest extends TestCase
{
    /** The pyproject that made ActivityWatch fail. */
    private const ACTIVITYWATCH = <<<'TOML'
[tool.poetry]
name = "activitywatch"
version = "0.14.0"
package-mode = false

[tool.poetry.dependencies]
python = "^3.9"

[build-system]
requires = ["poetry-core"]
build-backend = "poetry.core.masonry.api"
TOML;

    public function test_a_non_package_poetry_project_installs_its_dependencies_without_itself(): void
    {
        $command = PythonRuntime::installCommand(['pyproject.toml' => true], self::ACTIVITYWATCH);

        $this->assertStringContainsString('poetry install --no-root', $command);
        $this->assertStringNotContainsString('pip install .', $command);
        // The project interpreter has to be the venv the runtime starts
        // through; poetry would otherwise create its own and install into a
        // tree no serve command ever looks at.
        $this->assertStringContainsString('VIRTUAL_ENV="$PWD/.venv"', $command);
    }

    public function test_poetry_itself_is_installed_into_the_venv(): void
    {
        $command = PythonRuntime::installCommand(['pyproject.toml' => true], self::ACTIVITYWATCH);

        $this->assertStringContainsString('.venv/bin/pip install poetry', $command);
        $this->assertStringContainsString('.venv/bin/poetry install', $command);
    }

    /**
     * A Poetry *package* is still installed as one: `pip install .` is right
     * for it, and choosing `poetry install` there would resolve a different
     * dependency set through a different resolver.
     */
    public function test_a_packaged_poetry_project_keeps_the_pip_install(): void
    {
        $packaged = "[tool.poetry]\nname = \"widget\"\nversion = \"1.0.0\"\n";

        $command = PythonRuntime::installCommand(['pyproject.toml' => true], $packaged);

        $this->assertSame('python -m venv .venv && .venv/bin/pip install .', $command);
    }

    /**
     * The word `package-mode` elsewhere in the file says nothing. The marker
     * is only meaningful inside `[tool.poetry]`, so a PEP 621 `[project]`
     * that mentions it (a comment, a description, a tool table) is not
     * mistaken for a poetry application.
     */
    public function test_package_mode_outside_the_poetry_table_is_not_a_match(): void
    {
        $pep621 = "[project]\nname = \"widget\"\ndescription = \"not package-mode = false\"\n";

        $command = PythonRuntime::installCommand(['pyproject.toml' => true], $pep621);

        $this->assertSame('python -m venv .venv && .venv/bin/pip install .', $command);
    }

    public function test_package_mode_true_is_an_ordinary_package(): void
    {
        $packaged = "[tool.poetry]\nname = \"widget\"\npackage-mode = true\n";

        $this->assertSame(
            'python -m venv .venv && .venv/bin/pip install .',
            PythonRuntime::installCommand(['pyproject.toml' => true], $packaged)
        );
    }

    /** requirements.txt still wins, whatever a pyproject beside it says. */
    public function test_requirements_txt_still_takes_precedence(): void
    {
        $command = PythonRuntime::installCommand(
            ['requirements.txt' => true, 'pyproject.toml' => true],
            self::ACTIVITYWATCH
        );

        $this->assertStringContainsString('pip install -r requirements.txt', $command);
        $this->assertStringNotContainsString('poetry', $command);
    }

    public function test_no_pyproject_is_no_poetry(): void
    {
        $this->assertFalse(PythonRuntime::isNonPackagePoetry(null));
        $this->assertTrue(PythonRuntime::isNonPackagePoetry(self::ACTIVITYWATCH));
    }
}
