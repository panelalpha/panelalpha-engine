<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;

/**
 * Reads one of our own image tags back, e.g. `panelalpha/php:8.3-apache-bookworm-paf53449c0-xf4f426f8`,
 * from `config/core/images.yaml` only; both fingerprints in it are one-way.
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
     * Does this tag promise something baked in on top of the standard recipe? Its hash does not go
     * backwards, so only a caller still holding the set rebuilds it (SharedBaseImages::ensurePhp()).
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
     * The Dockerfile that produces this tag, or null when nothing here can; a variant needs the
     * $extensions set its hash came from.
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
            'python' => $packages === [] ? null : PythonBaseImage::dockerfile($source, $packages),
            'ruby' => $packages === [] ? null : RubyBaseImage::dockerfile($source, $packages),
            default => null,
        };
    }

    /**
     * Anchored on the last `-pa`: an upstream tag may carry one of its own, as `php:8.3-pale-moon` would.
     *
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

        [$body, $variant] = self::withoutVariant($rest);
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
     * @return array{0: string, 1: ?string} the tag without its `-x…` suffix, and the variant hash
     */
    private static function withoutVariant(string $rest): array
    {
        $variant = null;
        $body = $rest;
        if (preg_match('/^(.*)-x([0-9a-f]+)$/i', $body, $m) === 1) {
            $body = $m[1];
            $variant = $m[2];
        }

        return [$body, $variant];
    }

    /**
     * The recipe fingerprint a fresh build would carry; null means "cannot judge", read as keep.
     * Ruby and Python fingerprint a per-image apt package set, so they have no single current value.
     */
    public static function currentFingerprint(string $runtime): ?string
    {
        return match ($runtime) {
            'php' => PhpBaseImage::fingerprint(),
            default => null,
        };
    }
}
