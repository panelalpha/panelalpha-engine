<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Ssl\AcmeIssuer;
use App\Lib\Ssl\Issuers;
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

    public function test_a_directory_url_that_is_not_a_url_is_refused_and_not_stored(): void
    {
        $this->putJson('/api/system/ssl-config', ['acme_directory_url' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acme_directory_url']);

        $this->assertNull(Setting::get('acme_directory_url'));
    }

    public function test_a_url_and_a_shorthand_are_stored_as_given(): void
    {
        $this->putJson('/api/system/ssl-config', ['acme_directory_url' => AcmeIssuer::LETS_ENCRYPT_STAGING])
            ->assertOk();
        $this->assertSame(AcmeIssuer::LETS_ENCRYPT_STAGING, Setting::get('acme_directory_url'));

        $this->putJson('/api/system/ssl-config', ['acme_directory_url' => 'staging'])->assertOk();
        $this->assertSame('staging', Setting::get('acme_directory_url'));
        $this->assertSame(AcmeIssuer::LETS_ENCRYPT_STAGING, Issuers::directoryUrl());
    }
}
