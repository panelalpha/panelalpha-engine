<?php

namespace App\Providers;

use App\Integrations\GeoLocation\DbIp;
use App\Integrations\GeoLocation\GeoLocation;
use App\Integrations\Statistics\Awstats;
use App\Integrations\Statistics\Statistics;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\DeployHook\CheckoutSync;
use App\Lib\DeployHook\GitCheckoutSync;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\System;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // `revoked_at` is ours and Sanctum's validity check knows only
        // `expires_at`, so without this a revoked token was refused at /mcp
        // and kept working against the whole REST API.
        Sanctum::authenticateAccessTokensUsing(
            static fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && ! $token->isRevoked()
        );

        // One per request, which is one per deploy: the plan a deploy request
        // carried has to be readable from inside the pipeline, and the
        // pipeline builds a fresh project object on every call. See
        // {@see DeployPlanContext}.
        $this->app->singleton(DeployPlanContext::class);

        // The same lifetime for the same reason, one question earlier: not
        // which commands this deploy runs, but which recipe it runs them from.
        $this->app->singleton(RecipeChoiceContext::class);

        // What a queued Hook Delivery does to the checkout. An interface so
        // the code around it can be tested without a Docker daemon.
        $this->app->bind(CheckoutSync::class, GitCheckoutSync::class);

        $this->app->singleton(GeoLocation::class, function (Application $app) {
            $driver = (string) config('geolocation.driver', 'dbip');

            return match ($driver) {
                'dbip' => new DbIp($app->make(System::class)->engineDirPath()),
                default => throw new InvalidArgumentException(
                    "Unknown geolocation driver [{$driver}].",
                ),
            };
        });

        $this->app->singleton(Statistics::class, function (Application $app) {
            $root = $app->make(System::class)->engineDirPath();

            return new Awstats(
                $root.'/awstats-data',
                $root.'/awstats-config',
                $app->make(GeoLocation::class),
            );
        });
    }

    public function boot()
    {
        // WP-CLI arguments and WordPress values are passed through verbatim.
        // An empty one is meaningful (`wp rewrite structure ''` sets plain
        // permalinks, `wp option update blogname ''` clears an option), and so
        // is surrounding whitespace. The global TrimStrings and
        // ConvertEmptyStringsToNull would change both; ConvertEmptyStringsToNull
        // turns '' into null, which the request then rejects as a non-string.
        // MCP tools dispatch in-process and never pass these middleware.
        TrimStrings::skipWhen(static fn (Request $request): bool => self::takesVerbatimWordPressInput($request));
        ConvertEmptyStringsToNull::skipWhen(static fn (Request $request): bool => self::takesVerbatimWordPressInput($request));

        // A queue worker holds the settings table in a static for the life of the
        // process, and an allowance is written by the CLI or the API in another
        // one. Without this, the first apply after an allowance reads a snapshot
        // taken before it and delivers nothing.
        Event::listen(JobProcessing::class, static function (): void {
            Setting::clearRuntimeSettings();
        });
    }

    /**
     * Raw WP-CLI (`wp/command` and the legacy `wp-cli/command`), and the named
     * operations that write a value: config constants and variables, a new
     * wp-config.php (its database password), options, search-replace. Both
     * prefixes, because routes/api.php registers the project route group under
     * `projects` and `users` alike.
     */
    private static function takesVerbatimWordPressInput(Request $request): bool
    {
        foreach (['projects', 'users'] as $prefix) {
            if ($request->is(
                "api/{$prefix}/*/wp/command",
                "api/{$prefix}/*/wp-cli/command",
                "api/{$prefix}/*/wp/config",
                "api/{$prefix}/*/wp/config/*",
                "api/{$prefix}/*/wp/options/*",
                "api/{$prefix}/*/wp/search-replace",
            )) {
                return true;
            }
        }

        return false;
    }
}
