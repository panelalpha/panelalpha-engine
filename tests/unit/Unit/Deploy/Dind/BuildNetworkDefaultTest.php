<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\BuildNetwork;
use PHPUnit\Framework\TestCase;

/**
 * An unset build network is the engine's own, unlike an empty one, which opts out.
 */
class BuildNetworkDefaultTest extends TestCase
{
    public function test_an_unset_network_is_the_default_one(): void
    {
        $this->assertSame(BuildNetwork::DEFAULT_NAME, BuildNetwork::resolve(null));
        $this->assertNull(BuildNetwork::resolve(''));
    }
}
