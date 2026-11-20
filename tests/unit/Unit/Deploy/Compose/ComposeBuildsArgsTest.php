<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeBuilds;
use PHPUnit\Framework\TestCase;

/** A build arg with no value, or no name, is the builder's own default and not part of the build's identity. */
class ComposeBuildsArgsTest extends TestCase
{
    public function test_args_without_a_value_or_a_name_are_left_out(): void
    {
        $builds = ComposeBuilds::of(['services' => ['app' => ['build' => [
            'context' => '.',
            'args' => ['NODE_ENV', 'APP_VERSION=1.2', '=orphan'],
        ]]]]);

        $this->assertSame(['APP_VERSION' => '1.2'], $builds['app']['args']);
    }
}
