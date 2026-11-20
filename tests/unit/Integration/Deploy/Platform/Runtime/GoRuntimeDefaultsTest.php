<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Go's defaults: a go.mod without a `go` directive, a build constraint that cannot be parsed, and a project directory that is gone.
 */
class GoRuntimeDefaultsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-go-defaults-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_go_mod_without_a_go_directive_takes_the_default_minor(): void
    {
        file_put_contents($this->dir.'/go.mod', "module example.com/app\n");

        $requirement = (new GoRuntime)->resolve(ProjectContext::at($this->dir));

        $this->assertNotNull($requirement);
        $this->assertSame(['go', GoRuntime::defaultMinor(), '', 'go.mod (no go directive)'], [$requirement->id, $requirement->version, $requirement->constraint, $requirement->source]);
    }

    public function test_the_default_requirement_and_image_are_the_default_minor(): void
    {
        $runtime = new GoRuntime;
        $default = $runtime->defaultRequirement();

        $this->assertSame(['go', GoRuntime::defaultMinor(), 'engine default'], [$default->id, $default->version, $default->source]);
        $this->assertSame(GoRuntime::imageTag(GoRuntime::defaultMinor()), GoRuntime::defaultImage());
        $this->assertSame(GoRuntime::minors(), $runtime->supportedVersions());
        $this->assertContains(GoRuntime::defaultMinor(), $runtime->supportedVersions());
    }

    public function test_a_build_constraint_that_cannot_be_parsed_counts_the_file(): void
    {
        $this->assertTrue(GoRuntime::constraintsHold("//go:build linux \$\$ ignore\n\npackage main\n"));
        $this->assertFalse(GoRuntime::constraintsHold("//go:build ignore\n\npackage main\n"));
    }

    public function test_a_directory_that_is_gone_has_no_main_package_of_its_own(): void
    {
        $this->assertSame(
            GoRuntime::mainPackage($this->dir.'/missing'),
            GoRuntime::mainPackage($this->dir)
        );
        $this->assertSame('.', GoRuntime::mainPackage($this->dir.'/missing'));
    }
}
