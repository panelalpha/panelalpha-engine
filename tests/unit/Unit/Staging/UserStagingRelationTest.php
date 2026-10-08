<?php

namespace Tests\Unit\Staging;

use App\Models\User;
use Tests\TestCase;

class UserStagingRelationTest extends TestCase
{
    public function test_is_staging_when_staging_is_set(): void
    {
        $user = new User();
        $user->staging = 12;
        $this->assertTrue($user->isStaging());

        $live = new User();
        $live->staging = null;
        $this->assertFalse($live->isStaging());
    }
}
