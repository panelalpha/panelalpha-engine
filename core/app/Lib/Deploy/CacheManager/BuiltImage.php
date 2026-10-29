<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;

/**
 * Reading one of our own image tags back.
 *
 *     panelalpha/php:8.3-apache-bookworm-paf53449c0-xf4f426f8
 *     └ repository ┘ └ upstream tag ───┘ └ recipe ┘ └ variant┘
 *
 * Both fingerprints are one-way, which is the fact this class exists to be
 * honest about. Nothing publishes `panelalpha/*`, so a tag the engine fails to
 * recognise as its own goes to the pull path, 404s, and falls through to a
 * pull inside the account's nested NAT — 25 minutes and a dead deploy, per
 * {@see \App\\System\\Project\Dind\Inner\ImageSeeding::ensure()}.
 *
 * Everything here is read from `config/core/images.yaml` and nowhere
 * else. A runtime the catalogue does not describe is a runtime the engine
 * builds no image for, so a tag under its name is not one of ours -- there is
 * no compiled-in list to disagree with the file, and renaming a repository
 * there is the whole of renaming it.
 */
final class BuiltImage
{
    /** Null when we did not build this tag. */
    public static function runtimeFor(string $tag): ?string
    {
        $tag = trim($tag);
        $repository = strpos($tag, ':') === false ? '' : explode(':', $tag, 2)[0];
        if ($repository === '') {
            return null;
        }

        return RuntimeImageCatalog::builtRepositories()[$repository] ?? null;
    }

    public static function isOurs(string $tag): bool
    {
        return self::runtimeFor($tag) !== null && self::parts($tag) !== null;
    }

    /**
     * The official image this was built from, so a host that pruned it can
     * rebuild knowing only the tag.
     */
    public static function sourceImage(string $tag): ?string
    {
        $parts = self::parts($tag);
        if ($parts === null) {
            return null;
        }

        $upstream = RuntimeImageCatalog::upstreamRepository($parts['runtime']);

        return $upstream === null ? null : $upstream.':'.$parts['upstreamTag'];
    }

    public static function fingerprintOf(string $tag): ?string
    {
        return self::parts($tag)['fingerprint'] ?? null;
    }

    /**
     * Does this tag promise something baked in on top of the standard recipe?
     *
     * The variant suffix hashes an extension set, and a hash does not go
     * backwards: rebuilt from the tag alone, `-xf4f426f8` yields the plain
     * base wearing a name that promises imagick — an image that installs
     * cleanly and 500s on the first request that needs it. Only the caller
     * that still holds the set rebuilds a variant — see
     * SharedBaseImages::ensurePhp().
     */
    public static function isVariant(string $tag): bool
    {
        return (self::parts($tag)['variant'] ?? null) !== null;
    }

    /** True for a plain base: the recipe is in the code, the upstream in the tag. */
    public static function isRebuildableFromTag(string $tag): bool
    {
        $parts = self::parts($tag);

        return $parts !== null && $parts['variant'] === null;
    }

    /**
     * The Dockerfile that produces this tag, or null when nothing here can.
     *
     * A variant tag hashes an extension set and a hash does not go backwards,
     * so only a caller that still holds the set can rebuild one; $extensions
     * is that set. Dispatched on the catalogue's repository table rather than
     * assumed to be PHP, so what the engine builds is what the config says.
     *
     * @param  list<string>  $extensions
     * @param  list<string>  $packages
     */
    public static function dockerfile(string $tag, array $extensions = [], array $packages = []): ?string
    {
        $source = self::sourceImage($tag);
        if ($source === null || (! self::isRebuildableFromTag($tag) && $extensions === [] && $packages === [])) {
            return null;
        }

        return match (self::runtimeFor($tag)) {
            'php' => PhpBaseImage::dockerfile($source, $extensions, $packages),
            default => null,
        };
    }

    /**
     * @return array{runtime: string, repository: string, upstreamTag: string, fingerprint: string, variant: ?string}|null
     */
    public static function parts(string $tag): ?array
    {
        $tag = trim($tag);
        $runtime = self::runtimeFor($tag);
        if ($runtime === null) {
            return null;
        }
        [$repository, $rest] = explode(':', $tag, 2);
        if ($rest === '') {
            return null;
        }

        // Anchored on the *last* `-pa`: nothing stops an upstream tag carrying
        // one of its own, as `php:8.3-pale-moon` would.
        $variant = null;
        $body = $rest;
        if (preg_match('/^(.*)-x([0-9a-f]+)$/i', $body, $m) === 1) {
            $body = $m[1];
            $variant = $m[2];
        }
        $cut = strrpos($body, '-pa');
        if ($cut === false || $cut === 0) {
            return null;
        }
        $fingerprint = substr($body, $cut + 3);
        if ($fingerprint === '' || preg_match('/^[0-9a-f]+$/i', $fingerprint) !== 1) {
            return null;
        }

        return [
            'runtime' => $runtime,
            'repository' => $repository,
            'upstreamTag' => substr($body, 0, $cut),
            'fingerprint' => $fingerprint,
            'variant' => $variant,
        ];
    }

    /**
     * The recipe fingerprint a fresh build would carry, so a caller can tell a
     * current image from a superseded one. Null means "cannot judge", not "no
     * images": a caller deciding what to delete must read it as keep.
     */
    public static function currentFingerprint(string $runtime): ?string
    {
        return match ($runtime) {
            'php' => PhpBaseImage::fingerprint(),
            // Ruby and Python fingerprint an apt package set, which is
            // per-image rather than per-recipe: no single current value.
            default => null,
        };
    }
}
