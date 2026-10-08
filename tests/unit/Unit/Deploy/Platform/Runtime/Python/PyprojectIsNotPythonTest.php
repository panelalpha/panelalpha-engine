<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The Railpack gate agrees with the manifest: a tool-only pyproject is not
 * Python.
 *
 * Detection reaches the Python platform through python.yaml, and reaches
 * Railpack through `RuntimeRegistry::anyRecognises()`, which asks every
 * runtime whether it recognises the project. `PythonRuntime::resolve()` used
 * to answer yes whenever a pyproject.toml existed, so the same false positive
 * that claimed Dolibarr for the Python platform also claimed it for Railpack
 * -- two doors into one wrong answer, and fixing only the manifest would have
 * left the second open.
 */
class PyprojectIsNotPythonTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pyproject-gate-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents);
    }

    private function context(): ProjectContext
    {
        $files = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return ProjectContext::make($this->dir, $files);
    }

    /** The predicate itself, in the three shapes that matter. */
    public function test_declares_project_reads_the_two_project_tables(): void
    {
        $this->assertTrue(PythonRuntime::declaresProject("[project]\nname = \"app\"\n"));
        $this->assertTrue(PythonRuntime::declaresProject("[tool.poetry]\nname = \"app\"\n"));
        $this->assertFalse(PythonRuntime::declaresProject("[tool.ruff]\nline-length = 88\n"));
        $this->assertFalse(PythonRuntime::declaresProject("[build-system]\nbuild-backend = \"setuptools.build_meta\"\n"));
        $this->assertFalse(PythonRuntime::declaresProject(null));
    }

    public function test_a_tool_only_pyproject_does_not_resolve_python(): void
    {
        $this->write('pyproject.toml', "[tool.codespell]\nskip = \"*\"\n\n[build-system]\nbuild-backend = \"setuptools.build_meta\"\n");

        $this->assertNull(RuntimeRegistry::get('python')->resolve($this->context()));
        $this->assertFalse(RuntimeRegistry::anyRecognises($this->context()));
    }

    public function test_a_genuine_pyproject_still_resolves_python(): void
    {
        $this->write('pyproject.toml', "[project]\nname = \"app\"\nrequires-python = \">=3.13\"\n");

        $requirement = RuntimeRegistry::get('python')->resolve($this->context());
        $this->assertNotNull($requirement);
        $this->assertSame('3.13', $requirement->version);
        $this->assertTrue(RuntimeRegistry::anyRecognises($this->context()));
    }

    /** A poetry project is Python, and its `package-mode` still reaches the install. */
    public function test_a_poetry_pyproject_still_resolves_python(): void
    {
        $this->write('pyproject.toml', "[tool.poetry]\nname = \"aw\"\nversion = \"0.14.0\"\npackage-mode = false\n");

        $this->assertNotNull(RuntimeRegistry::get('python')->resolve($this->context()));
    }

    /**
     * requirements.txt, Pipfile, setup.py and manage.py keep their meaning
     * unchanged: a tool-only pyproject beside any of them does not undo it.
     */
    public function test_the_other_manifests_still_resolve_python(): void
    {
        $this->write('requirements.txt', "django\n");
        $this->assertNotNull(RuntimeRegistry::get('python')->resolve($this->context()));

        unlink($this->dir . '/requirements.txt');
        $this->write('Pipfile', "[packages]\nflask = \"*\"\n");
        $this->assertNotNull(RuntimeRegistry::get('python')->resolve($this->context()));

        unlink($this->dir . '/Pipfile');
        $this->write('setup.py', "from setuptools import setup\nsetup()\n");
        $this->assertNotNull(RuntimeRegistry::get('python')->resolve($this->context()));

        unlink($this->dir . '/setup.py');
        $this->write('manage.py', "#!/usr/bin/env python\n");
        $this->assertNotNull(RuntimeRegistry::get('python')->resolve($this->context()));
    }

    /**
     * A genuine Python project whose pyproject is tool config beside a
     * manage.py is still Python -- manage.py is its own evidence, exactly as
     * before.
     */
    public function test_manage_py_beside_a_tool_only_pyproject_is_still_python(): void
    {
        $this->write('pyproject.toml', "[tool.ruff]\nline-length = 100\n");
        $this->write('manage.py', "#!/usr/bin/env python\n");

        $this->assertNotNull(RuntimeRegistry::get('python')->resolve($this->context()));
    }
}
