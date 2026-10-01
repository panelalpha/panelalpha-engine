<?php

namespace App\Lib\Deploy\Compose;

/**
 * A compose file's `include:`s merged into it, so the services Compose will
 * run are the ones the hardening, the port scan and the URL environment read.
 * Left as an `include:`, they started exactly as the repository wrote them.
 *
 * Each included file's relative paths are rewritten to mean the same from the
 * project root ({@see NestedCompose::rebase()}); the including file's own
 * entries win a name clash.
 */
final class ComposeInclude
{
    private const MAX_DEPTH = 3;

    /** Top-level sections an included file contributes, merged by name. */
    private const SECTIONS = ['services', 'volumes', 'networks', 'configs', 'secrets'];

    /**
     * @param array<string, mixed> $compose paths already relative to the project root
     * @param callable(string): ?string $read project-relative path => contents, null when unreadable
     * @return array{compose: array<string, mixed>, sources: list<string>} the merged file, and the raw included files
     */
    public static function flatten(array $compose, callable $read, int $depth = 0): array
    {
        $include = $compose['include'] ?? null;
        unset($compose['include']);
        if ($include === null) {
            return ['compose' => $compose, 'sources' => []];
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException('The compose file nests include: deeper than ' . self::MAX_DEPTH . ' levels.');
        }

        $merged = [];
        $sources = [];
        foreach (is_array($include) ? $include : [$include] as $entry) {
            $paths = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            $base = is_array($entry) && is_string($entry['project_directory'] ?? null) ? $entry['project_directory'] : null;
            foreach ((array) $paths as $path) {
                if (!is_string($path) || trim($path) === '') {
                    continue;
                }
                $relative = self::fromRoot($path);
                $raw = str_starts_with($relative, '/') || str_starts_with($relative, '..') ? null : $read($relative);
                $parsed = $raw === null ? null : ComposeYaml::parse($raw);
                if (!is_array($parsed)) {
                    throw new \InvalidArgumentException("The compose file includes {$path}, which could not be read.");
                }
                $dir = dirname($relative) === '.' ? '' : dirname($relative);
                // Relative paths in it resolve against its own directory, or
                // against project_directory when the include names one.
                $parsed = NestedCompose::rebase($parsed, $base === null ? $dir : self::fromRoot($base));
                $inner = self::flatten($parsed, $read, $depth + 1);
                $sources = array_merge($sources, [$raw], $inner['sources']);
                foreach (self::SECTIONS as $section) {
                    if (is_array($inner['compose'][$section] ?? null)) {
                        $merged[$section] = array_merge($merged[$section] ?? [], $inner['compose'][$section]);
                    }
                }
            }
        }

        foreach (self::SECTIONS as $section) {
            if (isset($merged[$section])) {
                $own = is_array($compose[$section] ?? null) ? $compose[$section] : [];
                $compose[$section] = array_merge($merged[$section], $own);
            }
        }

        return ['compose' => $compose, 'sources' => $sources];
    }

    /** `./docker/x.yml` -> `docker/x.yml`; `..`-leading when it leaves the project. */
    private static function fromRoot(string $path): string
    {
        $normalised = NestedCompose::path(trim($path), '');

        return $normalised === '.' ? '' : (string) preg_replace('#^\./#', '', $normalised);
    }
}
