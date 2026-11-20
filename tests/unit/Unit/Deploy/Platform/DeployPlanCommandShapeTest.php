<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\ManifestException;
use PHPUnit\Framework\TestCase;

/**
 * A stage command written as a bare string is refused with where it was written.
 */
class DeployPlanCommandShapeTest extends TestCase
{
    public function test_a_command_that_is_not_an_object_is_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("stages.upgrade[0]: must be an object with a 'run'");

        DeployPlan::fromArray(['upgrade' => ['php artisan migrate']]);
    }
}
