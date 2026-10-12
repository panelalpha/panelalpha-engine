<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\System;
use Tests\TestCase;

/**
 * A second update while one is running is a conflict, not a bad field: the
 * request was well formed, and answering 422 under field 0 told the caller
 * nothing it could correct.
 */
class SystemUpdateConflictTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
        $this->app->instance(System::class, new class extends System {
            public function isUpdateScriptRunning(): bool
            {
                return true;
            }
        });
    }

    public function test_a_running_update_is_answered_with_conflict(): void
    {
        $this->putJson('/api/system/update')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Update script is already running');
    }
}
