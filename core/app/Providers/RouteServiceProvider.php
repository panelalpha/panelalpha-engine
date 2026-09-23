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

            if (file_exists(base_path('routes/tests.php'))) {
                Route::middleware('web')
                    ->group(base_path('routes/tests.php'));
            }
        });

        Route::middleware([JsonMiddleware::class, 'auth:sanctum', 'throttle:mcp', 'mcp.auth'])
            ->group(base_path('routes/mcp.php'));
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(60)->by(
                optional($request->user()?->currentAccessToken())->id ?: $request->ip()
            );
        });
    }
}
