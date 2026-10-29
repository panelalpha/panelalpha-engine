<?php

namespace App\Lib\Deploy\Platform\Runtime\Php;

/**
 * The asset publishes a project registers in its own composer scripts.
 *
 * Composer runs `post-update-cmd` and `post-create-project-cmd` on neither
 * `install` nor with `--no-scripts`, so assets a package ships under vendor/
 * (Cachet's Vite build, Filament's CSS) never reach public/. Only artisan
 * asset publishes are taken, and only with allow-listed arguments.
 *
 * No Laravel dependencies — unit-testable.
 */
final class AssetPublish
{
    private const SCRIPTS = ['post-update-cmd', 'post-create-project-cmd'];

    private const COMMANDS = ['vendor:publish', 'filament:assets'];

    private const SAFE_ARGUMENT = '#^[A-Za-z0-9_=:./\\\\-]+$#';

    /**
     * Artisan command lines, in script order and deduplicated, e.g.
     * `vendor:publish --tag=laravel-assets --ansi --force`.
     *
     * @param  array<string, mixed>|null  $composer  parsed root composer.json
     * @return list<string>
     */
    public static function commands(?array $composer): array
    {
        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

        $found = [];
        foreach (self::SCRIPTS as $name) {
            $entries = $scripts[$name] ?? [];
            foreach (is_array($entries) ? $entries : [$entries] as $entry) {
                $command = is_string($entry) ? self::parse($entry) : null;
                if ($command !== null && ! in_array($command, $found, true)) {
                    $found[] = $command;
                }
            }
        }

        return $found;
    }

    /**
     * The build step: each publish logged and allowed to fail on its own.
     * '' when the project registers none, which the build then skips.
     *
     * @param  array<string, mixed>|null  $composer
     */
    public static function buildCommand(?array $composer): string
    {
        $lines = [];
        foreach (self::commands($composer) as $command) {
            $label = "'[panelalpha] build: php artisan {$command}";
            $lines[] = "echo {$label}' >&2";
            $lines[] = 'php artisan '.self::shellArguments($command)
                ." || echo {$label} failed (optional, continuing)' >&2";
        }

        return implode('; ', $lines);
    }

    private static function parse(string $entry): ?string
    {
        $words = preg_split('/\s+/', trim($entry)) ?: [];
        if (
            count($words) < 3
            || ! in_array($words[0], ['@php', 'php'], true)
            || $words[1] !== 'artisan'
            || ! in_array($words[2], self::COMMANDS, true)
        ) {
            return null;
        }

        // One bad argument drops the whole entry: publishing without the tag
        // it named would publish something the project never asked for.
        $arguments = array_slice($words, 2);
        foreach ($arguments as $argument) {
            if (preg_match(self::SAFE_ARGUMENT, $argument) !== 1) {
                return null;
            }
        }

        return implode(' ', $arguments);
    }

    /** Backslashes (`--provider=Vendor\Package\Provider`) survive only quoted. */
    private static function shellArguments(string $command): string
    {
        $words = array_map(
            static fn (string $word): string => str_contains($word, '\\') ? "'{$word}'" : $word,
            explode(' ', $command)
        );
        if (! in_array('--no-interaction', $words, true) && ! in_array('-n', $words, true)) {
            $words[] = '--no-interaction';
        }

        return implode(' ', $words);
    }
}
