<?php

namespace Tests\Unit\Http;

use Tests\TestCase;

/** A project named under cert_domain shares a site with :2011, so the engine's session cookie must be one no other host can set. */
class EngineSessionCookieTest extends TestCase
{
    public function test_the_session_cookie_meets_the_host_prefix_rules(): void
    {
        $this->assertStringStartsWith('__Host-', (string) config('session.cookie'));
        $this->assertTrue((bool) config('session.secure'));
        $this->assertSame('/', config('session.path'));
        $this->assertNull(config('session.domain'));
    }
}
