<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * What `requires-python` resolves to when it names no minor, and when it names one newer than every known version.
 */
class RequiresPythonResolutionTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/requires-python-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function resolve(string $pyproject): array
    {
        file_put_contents($this->dir.'/pyproject.toml', $pyproject);
        $requirement = (new PythonRuntime)->resolve(ProjectContext::make($this->dir, ['pyproject.toml' => true]));
        $this->assertNotNull($requirement);

        return [$requirement->version, $requirement->constraint, $requirement->source];
    }

    public function test_a_constraint_without_a_minor_gets_the_engine_default(): void
    {
        $this->assertSame(
            [PythonRuntime::defaultVersion(), '*', 'engine default'],
            $this->resolve("[project]\nname = \"app\"\nrequires-python = \"*\"\n")
        );
    }

    public function test_a_minor_newer_than_every_known_version_is_taken_as_written(): void
    {
        $this->assertSame(
            ['3.99', '>=3.99', 'pyproject.toml requires-python'],
            $this->resolve("[project]\nname = \"app\"\nrequires-python = \">=3.99\"\n")
        );
    }

    public function test_a_constraint_resolves_to_the_oldest_known_version_it_allows(): void
    {
        $this->assertSame(
            ['3.11', '>=3.11,<4', 'pyproject.toml requires-python'],
            $this->resolve("[project]\nname = \"app\"\nrequires-python = \">=3.11,<4\"\n")
        );
    }

    public function test_a_tool_only_pyproject_without_manage_py_is_not_python(): void
    {
        file_put_contents($this->dir.'/pyproject.toml', "[tool.ruff]\nline-length = 100\n");

        $this->assertNull((new PythonRuntime)->resolve(ProjectContext::make($this->dir, ['pyproject.toml' => true])));
    }
}
