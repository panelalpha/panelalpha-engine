<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\CacheManager\BuildCache;

/**
 * The compose services that build inside the checkout, with the context, Dockerfile, target and args keying their
 * {@see BuildCache}. A build the host cannot reproduce as the account would, such as one with secrets, gets none.
 */
final class ComposeBuilds
{
    private const UNREPRODUCIBLE = ['dockerfile_inline', 'secrets', 'ssh', 'additional_contexts'];

    /**
     * @param  array<string, mixed>  $compose  parsed, after the deploy's own rewrites
     * @return array<string, array{context: string, dockerfile: string, target: ?string, args: array<string, string>}>
     */
    public static function of(array $compose): array
    {
        $builds = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $name => $service) {
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            $parsed = is_string($name) ? self::parse($build) : null;
            if ($parsed !== null) {
                $builds[$name] = $parsed;
            }
        }

        return $builds;
    }

    /**
     * A build's name among one repository's caches: the Dockerfile alone at the root, matching a Dockerfile deploy,
     * else the context, the Dockerfile and the target stage.
     */
    public static function identity(string $context, string $dockerfile, ?string $target = null): string
    {
        return ($context === '' ? '' : $context.'//').$dockerfile.($target === null ? '' : '@'.$target);
    }

    /**
     * $build with $cacheFrom added to its own `cache_from`, in the mapping form.
     *
     * @param  list<string>  $cacheFrom
     * @return array<string, mixed>
     */
    public static function withCacheFrom(array|string $build, array $cacheFrom): array
    {
        $build = is_string($build) ? ['context' => $build] : $build;
        $own = ComposeValues::stringList($build['cache_from'] ?? null);
        $build['cache_from'] = array_values(array_unique([...$own, ...$cacheFrom]));

        return $build;
    }

    /**
     * @return array{context: string, dockerfile: string, target: ?string, args: array<string, string>}|null
     */
    private static function parse(mixed $build): ?array
    {
        if (is_string($build)) {
            $build = ['context' => $build];
        }
        if (! is_array($build)) {
            return null;
        }
        foreach (self::UNREPRODUCIBLE as $key) {
            if (isset($build[$key])) {
                return null;
            }
        }
        $context = self::context($build['context'] ?? '.');
        $dockerfile = $build['dockerfile'] ?? 'Dockerfile';
        $target = $build['target'] ?? null;
        $args = self::args($build['args'] ?? []);
        if ($context === null || ! is_string($dockerfile) || ! self::isInside($dockerfile)
            || ($target !== null && ! is_string($target)) || $args === null
        ) {
            return null;
        }

        return ['context' => $context, 'dockerfile' => $dockerfile, 'target' => $target, 'args' => $args];
    }

    /** `web` for `./web/`, '' for the checkout itself, null for anything outside it. */
    private static function context(mixed $context): ?string
    {
        if (! is_string($context) || str_contains($context, '://') || str_starts_with($context, 'git@')) {
            return null;
        }
        $context = trim((string) preg_replace('#^(\./)+#', '', trim($context)), '/');
        if ($context === '.') {
            $context = '';
        }

        return self::isInside($context === '' ? '.' : $context) ? $context : null;
    }

    private static function isInside(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '$')
            && ! in_array('..', explode('/', $path), true);
    }

    /**
     * @return array<string, string>|null null when one interpolates what only the account knows
     */
    private static function args(mixed $args): ?array
    {
        $map = [];
        foreach (is_array($args) ? $args : [] as $key => $value) {
            if (is_int($key) && is_string($value)) {
                [$key, $value] = str_contains($value, '=') ? explode('=', $value, 2) : [$value, null];
            }
            if (! is_string($key) || $key === '' || $value === null) {
                continue;
            }
            if (! is_scalar($value) || str_contains((string) $value, '$')) {
                return null;
            }
            $map[$key] = (string) $value;
        }

        return $map;
    }
}
