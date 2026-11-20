<?php

namespace Tests\Integration\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Python's defaults, and a project whose several console scripts cannot be told apart because it names itself nowhere.
 */
class PythonRuntimeDefaultsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-python-defaults-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_the_default_requirement_and_image_are_the_default_version(): void
    {
        $runtime = new PythonRuntime;
        $default = $runtime->defaultRequirement();

        $this->assertSame(['python', PythonRuntime::defaultVersion(), '', 'engine default'], [$default->id, $default->version, $default->constraint, $default->source]);
        $this->assertSame(PythonRuntime::imageTag(PythonRuntime::defaultVersion()), PythonRuntime::defaultImage());
        $this->assertSame(PythonRuntime::versions(), $runtime->supportedVersions());
    }

    public function test_several_scripts_of_a_project_with_no_name_are_not_guessed_between(): void
    {
        file_put_contents($this->dir.'/pyproject.toml', "[project]\nversion = \"1.0\"\n\n[project.scripts]\nserve = \"a:b\"\nworker = \"a:c\"\n");

        $this->assertStringContainsString(PythonRuntime::PLACEHOLDER_DIR, PythonRuntime::startCommand($this->dir));
    }
}
