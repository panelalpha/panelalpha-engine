<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Domain;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `PUT /projects/{project}/domains/{domain}` with `redirect_enabled` and no
 * URL: a 422 that says what to do, refused before the vhost is rebuilt.
 * Nothing here touches the host.
 */
class DomainRedirectHttpTest extends TestCase
{
    use InMemoryDatabase;

    private Domain $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);

        $this->shop = $this->makeMainDomain($this->makeUser('alice', ['template' => 'wordpress'], 'shop.test'), 'shop.test');
    }

    public function test_a_redirect_with_no_url_is_refused_with_a_sentence(): void
    {
        $this->putJson('/api/projects/alice/domains/shop.test', ['redirect_enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter the URL to redirect the domain to.')
            ->assertJsonPath('errors.redirect_url', ['Enter the URL to redirect the domain to.']);

        $this->assertFalse((bool) $this->shop->fresh()?->redirectEnabled());
    }

    public function test_the_empty_url_a_form_sends_is_the_same_refusal(): void
    {
        foreach (['', null] as $url) {
            $this->putJson('/api/projects/alice/domains/shop.test', ['redirect_enabled' => true, 'redirect_url' => $url])
                ->assertStatus(422)
                ->assertJsonPath('errors.redirect_url', ['Enter the URL to redirect the domain to.']);
        }

        $this->assertNull($this->shop->fresh()?->getRedirectUrl());
    }
}
