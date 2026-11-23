<?php

namespace App\Lib\Deploy\Compose;

use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * A compose file kept in a subdirectory (`docker/compose.yml`), run from the project root, with its
 * relative paths rewritten to mean the same from there (`context: ..` becomes `.`).
 */
final class NestedCompose
{
    /**
     * Directories a repository keeps its deployment compose in; closed, as an open walk finds test stacks.
     *
     * @var list<string>
     */
    public const DIRECTORIES = ['docker', '.docker', 'deploy', 'deployment', 'docker-compose', 'compose'];

    /** $composePath's directory relative to $projectDir (`docker`), or null at the root or outside it. */
    public static function relativeDir(string $composePath, string $projectDir): ?string
    {
        $root = rtrim($projectDir, '/').'/';
        $dir = dirname($composePath);
        if (! str_starts_with($dir.'/', $root) || $dir.'/' === $root) {
            return null;
        }

        return substr($dir, strlen($root));
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    public static function rebase(array $compose, string $relativeDir): array
    {
        $relativeDir = trim($relativeDir, '/');
        if ($relativeDir === '') {
            return $compose;
        }

        if (is_array($compose['services'] ?? null)) {
            foreach ($compose['services'] as $name => $service) {
                if (is_array($service)) {
                    $compose['services'][$name] = self::rebaseService($service, $relativeDir);
                }
            }
        }
        $compose = self::withFilesRebased($compose, $relativeDir);

        return self::withIncludesRebased($compose, $relativeDir);
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    private static function withFilesRebased(array $compose, string $relativeDir): array
    {
        foreach (['configs', 'secrets'] as $section) {
            if (! is_array($compose[$section] ?? null)) {
                continue;
            }
            foreach ($compose[$section] as $name => $entry) {
                if (is_array($entry) && is_string($entry['file'] ?? null)) {
                    $compose[$section][$name]['file'] = self::path($entry['file'], $relativeDir);
                }
            }
        }

        return $compose;
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    private static function withIncludesRebased(array $compose, string $relativeDir): array
    {
        if (is_array($compose['include'] ?? null)) {
            foreach ($compose['include'] as $i => $include) {
                if (is_string($include)) {
                    $compose['include'][$i] = self::path($include, $relativeDir);
                } elseif (is_array($include) && is_string($include['path'] ?? null)) {
                    $compose['include'][$i]['path'] = self::path($include['path'], $relativeDir);
                }
            }
        }

        return $compose;
    }

    /**
     * Each relative path of the service rewritten, under a merge tag (`volumes: !override`) too.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function rebaseService(array $service, string $relativeDir): array
    {
        $rebasers = [
            'build' => self::rebasedBuild(...),
            'volumes' => self::rebasedVolumes(...),
            'env_file' => self::rebasedEnvFile(...),
            'extends' => self::rebasedExtends(...),
        ];
        foreach ($rebasers as $key => $rebase) {
            if (array_key_exists($key, $service)) {
                $service[$key] = self::underTags($service[$key], static fn (mixed $value): mixed => $rebase($value, $relativeDir));
            }
        }

        return $service;
    }

    /** $rebase applied to the value a merge tag holds, the tag kept for the `extends:` merge. */
    private static function underTags(mixed $value, \Closure $rebase): mixed
    {
        if ($value instanceof TaggedValue) {
            return new TaggedValue($value->getTag(), self::underTags($value->getValue(), $rebase));
        }

        return $rebase($value);
    }

    /** `dockerfile` is relative to the context, so it moves with it. */
    private static function rebasedBuild(mixed $build, string $relativeDir): mixed
    {
        if (is_string($build)) {
            return self::path($build, $relativeDir);
        }
        if (! is_array($build)) {
            return $build;
        }
        $build['context'] = self::path(is_string($build['context'] ?? null) ? $build['context'] : '.', $relativeDir);
        if (is_array($build['additional_contexts'] ?? null)) {
            foreach ($build['additional_contexts'] as $key => $context) {
                if (is_string($context)) {
                    $build['additional_contexts'][$key] = self::path($context, $relativeDir);
                }
            }
        }

        return $build;
    }

    private static function rebasedVolumes(mixed $volumes, string $relativeDir): mixed
    {
        if (! is_array($volumes)) {
            return $volumes;
        }
        foreach ($volumes as $i => $volume) {
            $volumes[$i] = self::rebaseVolume($volume, $relativeDir);
        }

        return $volumes;
    }

    private static function rebasedEnvFile(mixed $envFile, string $relativeDir): mixed
    {
        if (is_string($envFile)) {
            return self::path($envFile, $relativeDir);
        }
        if (! is_array($envFile)) {
            return $envFile;
        }
        foreach ($envFile as $i => $entry) {
            if (is_string($entry)) {
                $envFile[$i] = self::path($entry, $relativeDir);
            } elseif (is_array($entry) && is_string($entry['path'] ?? null)) {
                $envFile[$i]['path'] = self::path($entry['path'], $relativeDir);
            }
        }

        return $envFile;
    }

    private static function rebasedExtends(mixed $extends, string $relativeDir): mixed
    {
        if (is_array($extends) && is_string($extends['file'] ?? null)) {
            $extends['file'] = self::path($extends['file'], $relativeDir);
        }

        return $extends;
    }

    /** Only a bind whose source starts with `.` is relative; anything else means the same from anywhere. */
    private static function rebaseVolume(mixed $volume, string $relativeDir): mixed
    {
        if (is_string($volume)) {
            $parts = explode(':', $volume, 2);
            if (count($parts) === 2 && str_starts_with($parts[0], '.')) {
                return self::path($parts[0], $relativeDir).':'.$parts[1];
            }

            return $volume;
        }
        if (is_array($volume) && is_string($volume['source'] ?? null) && str_starts_with($volume['source'], '.')
            && strtolower((string) ($volume['type'] ?? 'bind')) === 'bind'
        ) {
            $volume['source'] = self::path($volume['source'], $relativeDir);
        }

        return $volume;
    }

    /** $path as written in the nested file, `./`-relative from the root; absolute paths, URLs and interpolations stay. */
    public static function path(string $path, string $relativeDir): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || str_starts_with($trimmed, '/') || str_starts_with($trimmed, '~')
            || str_starts_with($trimmed, '$') || preg_match('#^[a-z][a-z0-9+.-]*://|^git@#i', $trimmed) === 1
        ) {
            return $path;
        }

        $segments = [];
        foreach (explode('/', trim($relativeDir, '/').'/'.$trimmed) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            return '.';
        }

        return ($segments[0] === '..' ? '' : './').implode('/', $segments);
    }
}
