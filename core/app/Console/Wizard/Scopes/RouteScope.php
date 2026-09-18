<?php

namespace App\Console\Wizard\Scopes;

use App\Auth\ApiSurface;
use App\Auth\TokenAbilities;
use App\Models\PersonalAccessToken;

/**
 * Software's REST operations.
 *
 * The universe is every operation this engine has, taken from the router, so
 * a route added tomorrow can be granted without anything here being told
 * about it.
 */
class RouteScope implements Scope
{
    public function universe(): array
    {
        return ApiSurface::byGroup();
    }

    public function current(TokenAbilities $abilities): ?array
    {
        return $abilities->routes();
    }

    public function abilitiesFor(PersonalAccessToken $token, ?array $keys): array
    {
        return TokenAbilities::of($token)->withRoutes($keys);
    }

    public function noun(): array
    {
        return ['operation', 'operations'];
    }

    /**
     * Nothing. The verb is already the first word of the row, and `GET` next
     * to `read` is the same fact twice — where a command's name carries no
     * such hint and the note earns its column.
     */
    public function note(string $key): string
    {
        return '';
    }

    public function width(): int
    {
        return 1;
    }
}
