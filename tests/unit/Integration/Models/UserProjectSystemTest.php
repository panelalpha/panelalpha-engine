<?php

namespace Tests\Integration\Models;

use App\Models\User;
use App\System;
use App\System\EnginePaths;
use Tests\TestCase;

/**
 * A project looked up without a System is on the one the container hands out, so a test or the dind-test harness
 * that binds its own keeps every such lookup on it instead of the engine's /opt/panelalpha/shared-hosting.
 */
class UserProjectSystemTest extends TestCase
{
    public function test_without_a_binding_it_is_the_engines_own_system(): void
    {
        $system = $this->user()->project()->system();

        $this->assertSame(System::class, $system::class);
        $this->assertSame(EnginePaths::ENGINE_DIR, $system->engineDirPath());
    }

    public function test_a_bound_system_is_the_one_a_lookup_without_one_gets(): void
    {
        $bound = new System;
        $this->app->instance(System::class, $bound);

        $this->assertSame($bound, $this->user()->project()->system());
    }

    public function test_a_system_passed_in_wins_over_the_bound_one(): void
    {
        $this->app->instance(System::class, new System);
        $passed = new System;

        $this->assertSame($passed, $this->user()->project($passed)->system());
    }

    private function user(): User
    {
        $user = new User;
        $user->username = 'acct';

        return $user;
    }
}
