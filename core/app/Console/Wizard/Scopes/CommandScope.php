<?php

namespace App\Console\Wizard\Scopes;

use App\Auth\TokenAbilities;
use App\Mcp\ToolExposure;
use App\Mcp\ToolPolicy;
use App\Mcp\ToolRegistry;
use App\Models\PersonalAccessToken;

/**
 * An assistant's commands.
 *
 * The universe is narrower than every command the engine has: it is what the
 * engine currently offers, because a token cannot be given something the
 * engine is not serving. Widening that is the global scope's business.
 */
class CommandScope implements Scope
{
    public function universe(): array
    {
        $offered = array_flip(ToolExposure::current()->exposedNames());
        $policy = new ToolPolicy();
        $groups = [];

        foreach (ToolRegistry::byToolset($policy) as $toolset => $classes) {
            $names = array_values(array_filter(
                array_map(fn (string $c): string => $policy->nameOf($c), $classes),
                fn (string $n): bool => isset($offered[$n])
            ));

            if ($names !== []) {
                $groups[$toolset] = $names;
            }
        }

        return $groups;
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
        $policy = new ToolPolicy();

        foreach (ToolRegistry::all() as $class) {
            if ($policy->nameOf($class) === $key) {
                return $policy->accessOf($class);
            }
        }

        return '';
    }

    public function width(): int
    {
        return 34;
    }
}
