<?php

namespace App\Lib\Deploy\Compose;

/**
 * The manifest's `app_root`, the subtree that becomes /app, in one place so {@see FrameworkService} and the
 * stage-script writer agree. Validated, not trusted: anything that could leave the checkout falls back to the root.
 */
final class AppRoot
{
    /**
     * The subtree relative to the checkout root: `src`, or '' for the root, with no leading or trailing slash.
     * An absolute path is refused rather than reinterpreted: trimmed, `/etc` would mount something.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function relative(array $decision): string
    {
        $declared = trim((string) ($decision['app_root'] ?? ''));
        if ($declared === '' || str_starts_with($declared, '/')) {
            return '';
        }

        $root = rtrim($declared, '/');
        if (! preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $root)) {
            return '';
        }
        if (in_array('..', explode('/', $root), true)) {
            return '';
        }

        return $root;
    }

    /**
     * The same answer as a bind-mount source relative to the compose file: `./src`, or `./` for the checkout.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function mount(array $decision): string
    {
        $root = self::relative($decision);

        return $root === '' ? './' : './'.$root;
    }

    /**
     * The absolute directory that becomes /app, for a caller that puts a file where the container finds it.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function path(string $projectDir, array $decision): string
    {
        $root = self::relative($decision);

        return $root === '' ? $projectDir : rtrim($projectDir, '/').'/'.$root;
    }
}
