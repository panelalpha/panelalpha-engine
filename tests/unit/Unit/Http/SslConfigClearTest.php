<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Clearing an SSL setting removes it, so it reads back as null, as on a fresh install. */
class SslConfigClearTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    public function test_sending_null_back_leaves_the_settings_unset(): void
    {
        Setting::set('acme_email', 'ops@example.com');

        $this->putJson('/api/system/ssl-config', [
            'sites_base_domain' => null,
            'acme_directory_url' => null,
            'acme_email' => null,
        ])->assertOk()
            ->assertJsonPath('data.sites_base_domain', null);

        foreach (['default_wildcard_domain', 'acme_directory_url', 'acme_email'] as $name) {
            $this->assertNull(Setting::get($name), $name);
        }
    }
}
