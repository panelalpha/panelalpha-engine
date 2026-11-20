<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\AngularOutputProbe;
use PHPUnit\Framework\TestCase;

/**
 * A build command the project chose itself is kept; only the engine's default `ng build` is narrowed to one project.
 */
class AngularBuildCommandTest extends TestCase
{
    public function test_a_build_command_other_than_the_default_is_kept(): void
    {
        $this->assertSame('npm run build:prod', AngularOutputProbe::buildCommand('/nonexistent/pa-angular', 'npm run build:prod'));
    }
}
