<?php

namespace App\Providers;

use App\Http\Middleware\JsonMiddleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    private const VAULT_FORM_ATTEMPTS = 20;

    private const VAULT_PASTE_ATTEMPTS = 10;

    private const VAULT_DECAY_MINUTES = 10;

    /** Unauthenticated routes whose link or token is the capability, each on its own budget. */
    public const CAPABILITY_LINK_LIMITERS = ['plugin-package-download', 'app-sso-token', 'phpmyadmin-sso-token'];

    private const CAPABILITY_LINK_ATTEMPTS_PER_MINUTE = 60;

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Deliberately not in `web`: a git host's POST has no session and
            // no CSRF token. See the file for what guards it instead.
            Route::group([], base_path('routes/hooks.php'));
        });

        Route::middleware([JsonMiddleware::class, 'auth:sanctum', 'throttle:mcp', 'mcp.auth'])
            ->group(base_path('routes/mcp.php'));
    }

    /**
     * Configure the rate limiters for the application.
     *
     * Every route with its own budget gets a named limiter: an unnamed throttle keys
     * a guest on the domain and address alone, so all of them would share one counter.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // A floor against a runaway or leaked token, not a quota; the numbers
        // and why they are that high are in config/auth.php.
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $tokenId = is_object($token) ? ($token->id ?? null) : null;

            [$limit, $key] = $tokenId !== null
                ? [(int) config('auth.api_rate_limit.per_token'), 'token:'.$tokenId]
                : [(int) config('auth.api_rate_limit.per_ip'), 'ip:'.$request->ip()];

            return $limit > 0 ? Limit::perMinute($limit)->by($key) : Limit::none();
        });

        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(60)->by(
                optional($request->user()?->currentAccessToken())->id ?: $request->ip()
            );
        });

        $this->configureVaultLimiters();
        $this->configureCapabilityLinkLimiters();
    }

    private function configureVaultLimiters(): void
    {
        RateLimiter::for('vault-form', function (Request $request) {
            return Limit::perMinutes(self::VAULT_DECAY_MINUTES, self::VAULT_FORM_ATTEMPTS)
                ->by($request->ip());
        });

        RateLimiter::for('vault-paste', function (Request $request) {
            return Limit::perMinutes(self::VAULT_DECAY_MINUTES, self::VAULT_PASTE_ATTEMPTS)
                ->by($request->ip());
        });
    }

    /** Keyed like an unnamed throttle: the signed-in user when there is one, else the address. */
    private function configureCapabilityLinkLimiters(): void
    {
        foreach (self::CAPABILITY_LINK_LIMITERS as $name) {
            RateLimiter::for($name, function (Request $request) {
                $user = $request->user();

                return Limit::perMinute(self::CAPABILITY_LINK_ATTEMPTS_PER_MINUTE)->by(
                    $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip()
                );
            });
        }
    }
}
