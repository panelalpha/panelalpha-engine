<?php

namespace App\Lib\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\Strategies;

/**
 * The stack an app config is written for: the recipe it declares and the one it extends, else its own compose file.
 * A deploy that runs another stack, as a pinned recipe can, goes without its build commands and app script.
 */
final class AppConfigStack
{
    /**
     * @param  array<string, mixed>  $decision  what detection chose: its `platform` and `strategy`
     */
    public static function fits(AppConfig $appConfig, array $decision): bool
    {
        $recipe = $appConfig->manifest();
        if ($recipe !== null) {
            $writtenFor = array_filter([$recipe['id'] ?? null, $recipe[AppConfig::EXTENDS_KEY] ?? null], 'is_string');

            return in_array($decision['platform'] ?? null, $writtenFor, true);
        }

        return $appConfig->replacingCompose() === null
            || in_array($decision['strategy'] ?? null, [Strategies::COMPOSE, Strategies::PAEMD], true);
    }

    /**
     * The app config when it is written for the stack the decision runs, else null.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function for(array $decision, ?AppConfig $appConfig): ?AppConfig
    {
        return $appConfig !== null && self::fits($appConfig, $decision) ? $appConfig : null;
    }
}
