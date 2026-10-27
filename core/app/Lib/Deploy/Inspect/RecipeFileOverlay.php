<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Inspect\Report\AppConfigOrigin;

/**
 * Put an app config's `files/` and its replace-mode compose file into an
 * inspect clone, as the deploy's AppConfigBootstrap does.
 *
 * The deploy copies them into ~/project before detection runs, so a recipe can
 * supply what the repository lacks -- ESMira's root package.json shim is what
 * lets its runtime resolve at all. Inspect read the bare clone instead, and
 * reported a deployable app as undeployable. The compose file
 * is what detection reads ahead of the repository's own: without it inspect
 * called github.com/WordPress/WordPress `php` while the deploy ran compose.
 *
 * Only for a throwaway clone: a project's own directory already has the files,
 * and inspecting it must not write to it.
 *
 * Files land only in real folders of the clone. The repository may commit a
 * link anywhere on a file's path, even a chain of links that each stay inside
 * on paper, and following one would write wherever the engine can. A link on
 * the way, or at the file itself, stops the inspection with the reason.
 */
final class RecipeFileOverlay
{
    /**
     * @return list<string> the paths written, relative to $dir
     *
     * @throws InspectException when a file's path goes through a link
     */
    public static function apply(string $dir, ?string $repoUrl): array
    {
        [$appConfig] = AccountHomeRead::confinedTo($dir, static fn (): array => AppConfigOrigin::find($dir, $repoUrl));
        if ($appConfig === null) {
            return [];
        }

        $root = realpath($dir);
        if ($root === false) {
            return [];
        }

        $writes = $appConfig->files();
        $compose = $appConfig->replacingCompose();
        if ($compose !== null) {
            $writes[] = ['path' => EngineArtifacts::APP_CONFIG_COMPOSE, 'contents' => $compose];
        }

        $written = [];
        foreach ($writes as $snippet) {
            $relative = ltrim((string) $snippet['path'], '/');
            if (self::write($root, $relative, (string) $snippet['contents'])) {
                $written[] = $relative;
            }
        }

        return $written;
    }

    /** @throws InspectException */
    private static function write(string $root, string $relative, string $contents): bool
    {
        $parts = self::parts($relative);
        if ($parts === []) {
            return false;
        }

        $name = array_pop($parts);
        $directory = self::directory($root, $parts, $relative);
        if ($directory === null) {
            return false;
        }

        $target = $directory.'/'.$name;
        if (is_link($target)) {
            throw self::throughLink($relative, $relative);
        }

        return ! is_dir($target) && self::replace($target, $contents);
    }

    /**
     * The path's segments, or none for a path that is empty or climbs out.
     *
     * @return list<string>
     */
    private static function parts(string $relative): array
    {
        $parts = array_values(array_filter(
            explode('/', $relative),
            static fn (string $part): bool => $part !== '' && $part !== '.'
        ));

        return in_array('..', $parts, true) ? [] : $parts;
    }

    /**
     * The real folder $parts names under $root, made where missing, or null
     * where a file is in the way.
     *
     * @param  list<string>  $parts
     *
     * @throws InspectException when one of them is a link
     */
    private static function directory(string $root, array $parts, string $relative): ?string
    {
        clearstatcache(true);
        $directory = $root;
        foreach ($parts as $index => $part) {
            $directory .= '/'.$part;
            if (is_link($directory)) {
                throw self::throughLink($relative, implode('/', array_slice($parts, 0, $index + 1)));
            }
            if (! is_dir($directory) && ! @mkdir($directory, 0755)) {
                return null;
            }
        }

        if (realpath($directory) !== $directory) {
            throw self::throughLink($relative, implode('/', $parts));
        }

        return $directory;
    }

    /** Written beside $target and renamed over it, so whatever is at $target is never opened. */
    private static function replace(string $target, string $contents): bool
    {
        $temporary = dirname($target).'/.panelalpha-overlay-'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            return false;
        }

        $complete = fwrite($handle, $contents) === strlen($contents);
        fclose($handle);
        if ($complete && @rename($temporary, $target)) {
            return true;
        }
        @unlink($temporary);

        return false;
    }

    private static function throughLink(string $relative, string $link): InspectException
    {
        return new InspectException(
            "The app config's file {$relative} cannot be written: {$link} is a symbolic link in the repository, "
                .'and the engine does not write through links. Commit a real folder or file there instead.'
        );
    }
}
