<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\System;
use Tests\TestCase;

/** Asking for the webserver already active must not recreate sites-http and rebuild every project. */
class ChangeWebserverAlreadyActiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_the_active_webserver_answers_at_once_without_running_the_switch(): void
    {
        $system = $this->system(active: 'nginx-proxy');
        $this->app->instance(System::class, $system);

        $this->putJson('/api/system/change-webserver', ['new_webserver' => 'nginx-proxy'])
            ->assertOk()
            ->assertExactJson([
                'message' => "Webserver 'nginx-proxy' is already active; nothing was changed",
                'data' => ['exit_code' => 0],
            ]);
        $this->assertSame([], $system->switches);
    }

    public function test_a_webserver_that_is_not_active_is_switched(): void
    {
        $system = $this->system(active: null);
        $this->app->instance(System::class, $system);

        $this->putJson('/api/system/change-webserver', ['new_webserver' => 'nginx-proxy'])
            ->assertOk()
            ->assertExactJson(['data' => ['exit_code' => 0]]);
        $this->assertSame(['nginx-proxy'], $system->switches);
    }

    private function system(?string $active): System
    {
        return new class ($active) extends System {
            /** @var list<string> */
            public array $switches = [];

            public function __construct(private ?string $active)
            {
            }

            public function isChangeWebserverScriptRunning(): bool
            {
                return false;
            }

            public function isWebserverActive(string $webserver): bool
            {
                return $webserver === $this->active;
            }

            public function runChangeWebserverScript(string $newWebserver, ?string $serialNumber = null): void
            {
                $this->switches[] = $newWebserver;
            }

            public function getLatestChangeWebserverInfo(): ?array
            {
                return ['exit_code' => 0];
            }
        };
    }
}
