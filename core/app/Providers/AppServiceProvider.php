<?php

namespace App\Providers;

use App\Integrations\GeoLocation\DbIp;
use App\Integrations\GeoLocation\GeoLocation;
use App\Integrations\Statistics\Awstats;
use App\Integrations\Statistics\Statistics;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Models\PersonalAccessToken;
use App\System;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
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
                && !$token->isRevoked()
        );

        // One per request, which is one per deploy: the plan a deploy request
        // carried has to be readable from inside the pipeline, and the
        // pipeline builds a fresh project object on every call. See
        // {@see DeployPlanContext}.
        $this->app->singleton(DeployPlanContext::class);

        // The same lifetime for the same reason, one question earlier: not
        // which commands this deploy runs, but which recipe it runs them from.
        $this->app->singleton(RecipeChoiceContext::class);

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
                $root . '/awstats-data',
                $root . '/awstats-config',
                $app->make(GeoLocation::class),
            );
        });
    }

    public function boot()
    {
        // WP-CLI arguments are passed through verbatim, and an empty one is
        // meaningful: `wp rewrite structure ''` is how the plain permalink
        // structure is set. The global ConvertEmptyStringsToNull would turn it
        // into null, which the controller then rejects as a non-string.
        //
        // Both prefixes, because routes/api.php registers the project route
        // group under `projects` and `users` alike -- matching only the old one
        // left this broken on the path new clients and the generated wp_cli_run
        // MCP tool actually call.
        ConvertEmptyStringsToNull::skipWhen(static function (Request $request): bool {
            return $request->is('api/projects/*/wp-cli/command')
                || $request->is('api/users/*/wp-cli/command');
        });
    }
}
