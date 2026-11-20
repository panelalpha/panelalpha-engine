<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Gemfile `ruby` constraints the resolver cannot read or cannot move a stated version to, and Ruby's defaults.
 */
class RubyRuntimeGemfileConstraintsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-ruby-constraints-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir.'/.ruby-version', "3.3.0\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function resolved(string $gemfile): array
    {
        file_put_contents($this->dir.'/Gemfile', "source 'https://rubygems.org'\n{$gemfile}\n");
        $requirement = (new RubyRuntime)->resolve(ProjectContext::at($this->dir));
        $this->assertNotNull($requirement);

        return [$requirement->version, $requirement->constraint, $requirement->source];
    }

    public function test_a_constraint_it_cannot_read_leaves_the_stated_version(): void
    {
        $this->assertSame(['3.3.0', '3.3.0, jruby-9.4', '.ruby-version'], $this->resolved("ruby 'jruby-9.4'"));
    }

    public function test_a_ceiling_below_the_stated_version_has_no_floor_to_move_to(): void
    {
        $this->assertSame(['3.3.0', '3.3.0, < 2.0', '.ruby-version'], $this->resolved("ruby '< 2.0'"));
    }

    public function test_the_floor_is_taken_from_the_first_constraint_that_names_one(): void
    {
        $this->assertSame(['3.4', '< 4.0, >= 3.4.0', 'Gemfile ruby directive'], $this->resolved("ruby '< 4.0', '>= 3.4.0'"));
    }

    public function test_the_default_requirement_is_the_default_minor(): void
    {
        $runtime = new RubyRuntime;
        $default = $runtime->defaultRequirement();

        $this->assertSame(['ruby', RubyRuntime::defaultMinor(), '', 'engine default'], [$default->id, $default->version, $default->constraint, $default->source]);
        $this->assertSame(RubyRuntime::minors(), $runtime->supportedVersions());
    }
}
