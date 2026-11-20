<?php

namespace Tests\Integration\Http;

use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The site password gate nginx-proxy asks on every request to a protected site: `check` lets a valid cookie or Basic
 * password through, `gate` serves the form, `login` checks the password and sets the cookie.
 */
class SitePasswordGateHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const PASSWORD = 'open-sesame';

    private User $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->shop = $this->makeUser('shop', [
            SitePasswordProtection::DETAIL_ENABLED => true,
            SitePasswordProtection::DETAIL_HASH => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            SitePasswordProtection::DETAIL_VERSION => 3,
        ]);
        $this->makeUser('open');
    }

    public function test_an_unprotected_or_unknown_project_is_let_through(): void
    {
        $this->get('/api/internal/site-password/open/check')->assertOk();
        $this->get('/api/internal/site-password/nobody/check')->assertOk();
    }

    public function test_a_protected_site_without_credentials_is_refused(): void
    {
        $this->get('/api/internal/site-password/shop/check')->assertUnauthorized();
        $this->get('/api/internal/site-password/shop/check', ['Authorization' => 'Basic ' . base64_encode('any:wrong')])->assertUnauthorized();
    }

    public function test_the_basic_password_or_the_signed_cookie_lets_a_request_through(): void
    {
        $this->get('/api/internal/site-password/shop/check', ['Authorization' => 'Basic ' . base64_encode(':' . self::PASSWORD)])->assertOk();

        $this->withUnencryptedCookie(SitePasswordProtection::COOKIE_NAME, SitePasswordProtection::cookieValue($this->shop))
            ->get('/api/internal/site-password/shop/check')
            ->assertOk();
    }

    public function test_a_cookie_of_an_earlier_password_is_refused(): void
    {
        $this->withUnencryptedCookie(SitePasswordProtection::COOKIE_NAME, '2.' . str_repeat('a', 64))
            ->get('/api/internal/site-password/shop/check')
            ->assertUnauthorized();
    }

    public function test_the_gate_serves_the_form_and_flags_a_failed_attempt(): void
    {
        $form = $this->get('/api/internal/site-password/shop/gate');
        $form->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('<title>Password required</title>', (string) $form->getContent());
        $this->assertStringNotContainsString('Incorrect password.', (string) $form->getContent());

        $this->assertStringContainsString('Incorrect password.', (string) $this->get('/api/internal/site-password/shop/gate?error=1')->getContent());

        $this->get('/api/internal/site-password/open/gate')->assertNotFound();
    }

    public function test_a_wrong_password_answers_the_form_again(): void
    {
        $response = $this->post('/api/internal/site-password/shop/login', ['password' => 'nope', 'redirect' => '/shop']);

        $response->assertUnauthorized();
        $this->assertStringContainsString('Incorrect password.', (string) $response->getContent());
        $response->assertCookieMissing(SitePasswordProtection::COOKIE_NAME);
    }

    public function test_the_right_password_sets_the_cookie_and_returns_to_the_page(): void
    {
        $response = $this->post('/api/internal/site-password/shop/login', ['password' => self::PASSWORD, 'redirect' => '/shop/cart?x=1'], ['X-Forwarded-Proto' => 'https']);

        $response->assertRedirect('/shop/cart?x=1');
        $response->assertCookie(SitePasswordProtection::COOKIE_NAME, SitePasswordProtection::cookieValue($this->shop), false);
        $this->assertTrue($response->getCookie(SitePasswordProtection::COOKIE_NAME, false)?->isSecure());
    }

    public function test_a_redirect_off_the_site_goes_to_the_home_page(): void
    {
        $this->post('/api/internal/site-password/shop/login', ['password' => self::PASSWORD, 'redirect' => '//evil.example'])
            ->assertRedirect('/');
        $this->post('/api/internal/site-password/shop/login', ['password' => self::PASSWORD, 'redirect' => 'https://evil.example'])
            ->assertRedirect('/');
    }

    public function test_a_login_to_an_unprotected_project_is_not_found(): void
    {
        $this->post('/api/internal/site-password/open/login', ['password' => self::PASSWORD])->assertNotFound();
    }
}
