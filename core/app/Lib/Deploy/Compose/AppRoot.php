<?php

namespace App\Lib\Deploy\Compose;

/**
 * The manifest's `app_root`: the subtree that becomes /app.
 *
 * This lives in one place because two callers have to agree about it and the
 * cost of them disagreeing is silent. {@see FrameworkService} mounts the
 * subtree at /app, and {@see \App\\System\\Project\Dind\Strategy\EntrypointWriter}
 * writes the generated stage script that the base image looks for at
 * /app/panelalpha-entrypoint.sh. While the writer used the checkout root and
 * the mount used the subtree, the script was written somewhere the container
 * could not see: the shim fell through to `serving directly`, and every
 * install, upgrade and start command the manifest declared was dropped
 * without a word in the deploy log. WordPress answered 500 because its
 * wp-config step never ran; phpBB lost the writable-dirs step the same way.
 *
 * Validated rather than trusted: `_schema.json` already rejects an absolute
 * path, but this value ends up as a bind-mount source and as a path written
 * to, so anything that could climb out of the checkout is refused here too.
 * A value that does not survive validation falls back to the checkout root,
 * which is the same answer both callers gave before this existed.
 */
final class AppRoot
{
    /**
     * The subtree relative to the checkout root: `src`, or '' for the root
     * itself. No leading or trailing slash, so a caller can join it.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function relative(array $decision): string
    {
        $declared = trim((string) ($decision['app_root'] ?? ''));
        // An absolute path is refused rather than reinterpreted. Trimming the
        // leading slash off `/etc` would turn a value the schema rejects into
        // a relative path that mounts something -- quietly, and not what the
        // manifest asked for either way.
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
     * The same answer as a bind-mount source, relative to the compose file:
     * `./src`, or `./` for the whole checkout.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function mount(array $decision): string
    {
        $root = self::relative($decision);

        return $root === '' ? './' : './'.$root;
    }

    /**
     * The absolute directory that becomes /app, for a caller that has to put
     * a file where the container will find it.
     *
     * @param  array<string, mixed>  $decision
     */
    public static function path(string $projectDir, array $decision): string
    {
        $root = self::relative($decision);

        return $root === '' ? $projectDir : rtrim($projectDir, '/').'/'.$root;
    }
}
