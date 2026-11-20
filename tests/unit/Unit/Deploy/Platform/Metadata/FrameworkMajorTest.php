<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\Framework;
use PHPUnit\Framework\TestCase;

/**
 * A framework's major version: from the resolved version first, else the constraint, and none when neither has a number.
 */
class FrameworkMajorTest extends TestCase
{
    public function test_the_resolved_version_wins_over_the_constraint(): void
    {
        $this->assertSame(11, (new Framework('laravel', 'Laravel', '^10.0', '11.2.0', 'composer.lock'))->major());
        $this->assertSame(10, (new Framework('laravel', 'Laravel', '^10.0', null, 'composer.json'))->major());
    }

    public function test_a_constraint_without_a_number_has_no_major(): void
    {
        $this->assertNull((new Framework('laravel', 'Laravel', 'dev-main', null, 'composer.json'))->major());
    }
}
