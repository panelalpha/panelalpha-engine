<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use App\System;
use Tests\TestCase;

/**
 * A refusal about the engine's state rather than a request field used to be a
 * 422 keyed under `0`. It is a 409 with a message and no field now, as other
 * state refusals are.
 */
class EngineStateRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_disabled_lighthouse_is_a_conflict(): void
    {
        Setting::setRuntimeSettings(['disable-lighthouse' => '1']);

        $this->postJson('/api/lighthouse/generate-report', ['url' => 'https://shop.example/'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'lighthouse service is disabled']);
    }

    public function test_a_webserver_change_already_running_is_a_conflict(): void
    {
        $this->app->instance(System::class, new class () extends System {
            public function __construct()
            {
            }

            public function isChangeWebserverScriptRunning(): bool
            {
                return true;
            }

            public function runChangeWebserverScript(string $newWebserver, ?string $serialNumber = null): void
            {
                throw new \LogicException('a second change must not start');
            }
        });

        $this->putJson('/api/system/change-webserver', ['new_webserver' => 'nginx-proxy'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Change webserver script is already running']);
    }
}
