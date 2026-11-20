<?php

namespace Tests\Support;

use App\Auth\TokenAbilities;
use App\Console\Wizard\Scopes\Scope;
use App\Models\PersonalAccessToken;

/** A token scope over a fixed handful of MCP commands, so a test can tick every row by hand. */
final class ListedScope implements Scope
{
    /** @param array<string, list<string>> $universe */
    public function __construct(private readonly array $universe = [
        'alpha' => ['alpha_list', 'alpha_create'],
        'beta' => ['beta_list'],
    ]) {}

    public function universe(): array
    {
        return $this->universe;
    }

    public function current(TokenAbilities $abilities): ?array
    {
        return $abilities->commands();
    }

    public function abilitiesFor(PersonalAccessToken $token, ?array $keys): array
    {
        return TokenAbilities::of($token)->withCommands($keys);
    }

    public function noun(): array
    {
        return ['command', 'commands'];
    }

    public function note(string $key): string
    {
        return str_ends_with($key, '_list') ? 'read' : 'write';
    }

    public function width(): int
    {
        return 12;
    }
}
