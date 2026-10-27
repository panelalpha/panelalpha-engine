<?php

namespace App\Lib\Hub;

use App\Auth\TokenAbilities;

/**
 * What a Hub token may call: a few routes of the regular API, named one by
 * one. The engine is the authority here: the Hub sends grant names, and a name
 * this engine does not know is refused, never guessed.
 */
final class HubGrants
{
    public const ADMIN_SSO = 'admin_sso';

    /** Always granted: the Hub's read-only view plus its own token lifecycle. */
    public const BASE_ROUTES = [
        'GET /system/info',
        'GET /metrics/current',
        'GET /metrics/history',
        'GET /projects/all',
        'GET /projects/{username}/backups',
        'GET /projects/{username}/metrics/history',
        'GET /projects/{username}/containers/metrics',
        'POST /tokens/current/rotate',
        'DELETE /tokens/current',
    ];

    /** @var array<string, array<int, string>> */
    public const OPTIONAL_ROUTES = [
        self::ADMIN_SSO => [
            'GET /projects/{username}/app/users',
            'POST /projects/{username}/app/users/{userId}/sso',
        ],
    ];

    /** What the operator is shown, in the Hub's words. */
    private const BASE_TEXT = [
        'Read the engine version, host details and resource usage',
        'Read the list of applications and their status',
        'Read usage statistics',
        "Read your projects' backup history",
    ];

    private const OPTIONAL_TEXT = [
        self::ADMIN_SSO => 'Create one-time admin login links to your apps',
    ];

    /**
     * @param  array<int, mixed>  $grants
     * @return array<int, mixed> grant names this engine does not know
     */
    public static function unknown(array $grants): array
    {
        return array_values(array_filter(
            $grants,
            fn (mixed $g): bool => ! is_string($g) || ! isset(self::OPTIONAL_ROUTES[$g])
        ));
    }

    /**
     * Always the explicit list: `api` with no route ability means no limit.
     *
     * @param  array<int, string>  $grants
     * @return array<int, string>
     *
     * @throws HubException on a grant this engine does not know
     */
    public static function abilities(array $grants): array
    {
        if (self::unknown($grants) !== []) {
            throw new HubException('Unknown Hub grant: '.implode(', ', array_map('strval', self::unknown($grants))));
        }

        $routes = self::BASE_ROUTES;
        foreach (array_unique($grants) as $grant) {
            $routes = array_merge($routes, self::OPTIONAL_ROUTES[$grant]);
        }

        return TokenAbilities::build(api: true, mcp: false, routes: $routes);
    }

    /**
     * Exactly `api` plus routes this mapping can grant, and at least one of
     * them. Anything else -- `*`, `mcp`, another route -- is not a Hub token.
     *
     * @param  array<int, mixed>  $abilities
     */
    public static function isHubScoped(array $abilities): bool
    {
        $grantable = array_merge(self::BASE_ROUTES, ...array_values(self::OPTIONAL_ROUTES));
        $routes = 0;

        foreach ($abilities as $ability) {
            if ($ability === TokenAbilities::API) {
                continue;
            }
            if (! is_string($ability) || ! str_starts_with($ability, TokenAbilities::ROUTE_PREFIX)
                || ! in_array(substr($ability, strlen(TokenAbilities::ROUTE_PREFIX)), $grantable, true)) {
                return false;
            }
            $routes++;
        }

        return $routes > 0 && in_array(TokenAbilities::API, $abilities, true);
    }

    /**
     * @param  array<int, string>  $grants
     * @return array<int, string>
     */
    public static function describe(array $grants): array
    {
        $lines = self::BASE_TEXT;
        foreach (self::OPTIONAL_TEXT as $grant => $text) {
            if (in_array($grant, $grants, true)) {
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
