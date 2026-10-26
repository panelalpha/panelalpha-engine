<?php

namespace Tests\Unit\Vault;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Each vault verb is throttled on its own budget per address: an unnamed
 * throttle keys a guest on the domain and address alone, which would let
 * loading the form spend the paste's attempts.
 */
class VaultThrottleTest extends VaultTestCase
{
    private const ADDRESS = '198.51.100.7';

    private const OTHER_ADDRESS = '198.51.100.8';

    private const FORM_ATTEMPTS = 20;

    private const PASTE_ATTEMPTS = 10;

    private const DECAY_SECONDS = 600;

    public function test_each_verb_uses_its_own_named_limiter(): void
    {
        $this->assertSame([ThrottleRequests::class . ':vault-form'], $this->throttlesOf('GET'));
        $this->assertSame([ThrottleRequests::class . ':vault-paste'], $this->throttlesOf('POST'));
    }

    /** Both budgets are the ones the routes carried as unnamed throttles. */
    public function test_the_form_allows_twenty_loads_and_the_paste_ten_saves_per_address(): void
    {
        $this->assertLimit('vault-form', self::FORM_ATTEMPTS);
        $this->assertLimit('vault-paste', self::PASTE_ATTEMPTS);
    }

    public function test_loading_the_form_does_not_spend_the_paste_budget(): void
    {
        [$entry, $ref] = $this->entry(['type' => 'some_future_secret']);

        for ($load = 0; $load < self::FORM_ATTEMPTS; $load++) {
            $this->page($ref)->assertOk();
        }

        $this->page($ref)->assertStatus(429);

        for ($save = 0; $save < self::PASTE_ATTEMPTS; $save++) {
            $this->paste($ref, 'pasted_secret')->assertOk();
        }

        $this->paste($ref, 'pasted_secret')->assertStatus(429);

        $entry->refresh();
        $this->assertSame('pasted_secret', $entry->revealSecret());
    }

    public function test_another_address_keeps_its_own_budget(): void
    {
        [, $ref] = $this->entry(['type' => 'some_future_secret']);

        for ($load = 0; $load < self::FORM_ATTEMPTS; $load++) {
            $this->page($ref)->assertOk();
        }

        $this->page($ref)->assertStatus(429);
        $this->page($ref, self::OTHER_ADDRESS)->assertOk();
    }

    private function page(string $ref, string $address = self::ADDRESS): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])->get('/vault/' . $ref);
    }

    private function paste(string $ref, string $secret, string $address = self::ADDRESS): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])->post('/vault/' . $ref, ['secret' => $secret]);
    }

    private function assertLimit(string $name, int $attempts): void
    {
        $limiter = RateLimiter::limiter($name);
        $this->assertNotNull($limiter, "no `{$name}` rate limiter is defined");

        $limit = $limiter(Request::create('/vault/a-token', 'GET', [], [], [], ['REMOTE_ADDR' => self::ADDRESS]));

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame($attempts, $limit->maxAttempts);
        $this->assertSame(self::DECAY_SECONDS, $limit->decaySeconds);
        $this->assertSame(self::ADDRESS, $limit->key);
    }

    /**
     * @return list<string>
     */
    private function throttlesOf(string $method): array
    {
        $route = Route::getRoutes()->match(Request::create('/vault/a-token', $method));

        return array_values(array_filter(
            array_map('strval', $this->app->make('router')->gatherRouteMiddleware($route)),
            static fn (string $middleware): bool => str_starts_with($middleware, ThrottleRequests::class . ':')
        ));
    }
}
