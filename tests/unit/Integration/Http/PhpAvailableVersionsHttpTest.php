<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\System;
use App\System\Services\Php;
use Tests\TestCase;

/** GET /php/available-versions answers the versions the host's PHP service lists. */
class PhpAvailableVersionsHttpTest extends TestCase
{
    public function test_the_hosts_php_versions_are_listed(): void
    {
        $this->withoutMiddleware(Authenticate::class);
        $this->app->instance(System::class, new class extends System
        {
            public function php(): Php
            {
                return new class($this) extends Php
                {
                    public function listAvailablePhpVersions(): array
                    {
                        return ['8.2', '8.3', '8.4'];
                    }
                };
            }
        });

        $this->getJson('/api/php/available-versions')->assertOk()->assertExactJson(['data' => ['8.2', '8.3', '8.4']]);
    }
}
