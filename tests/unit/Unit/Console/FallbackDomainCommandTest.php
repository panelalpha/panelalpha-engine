<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The CLI ships no unimplemented `system:domain:fallback` stub. */
class FallbackDomainCommandTest extends TestCase
{
    public function test_no_unimplemented_fallback_command_is_registered(): void
    {
        $commands = Artisan::all();

        foreach (['system:domain:fallback', 'system:fallback-domain'] as $name) {
            $this->assertArrayNotHasKey($name, $commands, "{$name} does nothing but print TODO.");
        }
    }
}
