<?php

namespace App\Console\Wizard\Scopes;

use App\Auth\TokenAbilities;
use App\Models\PersonalAccessToken;

/**
 * What one kind of token can be narrowed to.
 *
 * The screens are the same for either surface, so {@see \App\Console\Wizard\TokenScopeEditor}
 * owns those and a Scope owns the vocabulary: commands for MCP, routes for REST.
 */
interface Scope
{
    /** What this engine offers, grouped: group name => keys. */
    public function universe(): array;

    /** The keys this token is limited to, or null when it is not limited. */
    public function current(TokenAbilities $abilities): ?array;

/**
     * The whole ability list this token should have to be limited to `$keys`,
     * or to nothing at all when `$keys` is null.
     *
     * @param array<int, string>|null $keys
     * @return array<int, string>
     */
    public function abilitiesFor(PersonalAccessToken $token, ?array $keys): array;

    /** Singular and plural: ['command', 'commands']. */
    public function noun(): array;

    /** What one key does to the server, for the row it is ticked on. */
    public function note(string $key): string;

    /** The width a key's label is padded to. */
    public function width(): int;
}
