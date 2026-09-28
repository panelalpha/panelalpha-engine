<?php

namespace App\Lib\Deploy\Compose;

/**
 * A compose file kept in a subdirectory (`docker/compose.yml`,
 * `deploy/docker-compose.yml`), run from the project root.
 *
 * Compose resolves a file's relative paths against its own directory, but the
 * engine runs every stack with `--project-directory ~/project` and writes the
 * run file there. So the nested file's paths are rewritten to mean the same
 * thing from the root: WikiDocs' `context: ..` becomes `.`, a `./data` bind
 * becomes `./docker/data`.
 *
 * No Laravel dependencies — unit-testable.
 */
final class NestedCompose
{
    /**
     * Directories a repository keeps its deployment compose in. A closed list:
     * an open walk finds test stacks (`ws-tests/`), dev containers and docs.
     *
     * @var list<string>
     */
    public const DIRECTORIES = ['docker', '.docker', 'deploy', 'deployment', 'docker-compose', 'compose'];

    /**
     * $composePath's directory relative to $projectDir (`docker`), or null
     * when the file sits at the root or outside the project.
     */
    public static function relativeDir(string $composePath, string $projectDir): ?string
    {
        $root = rtrim($projectDir, '/') . '/';
        $dir = dirname($composePath);
        if (!str_starts_with($dir . '/', $root) || $dir . '/' === $root) {
            return null;
        }

        return substr($dir, strlen($root));
    }

    /**
     * @param array<string, mixed> $compose
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
        foreach (['configs', 'secrets'] as $section) {
            if (!is_array($compose[$section] ?? null)) {
                continue;
            }
            foreach ($compose[$section] as $name => $entry) {
                if (is_array($entry) && is_string($entry['file'] ?? null)) {
                    $compose[$section][$name]['file'] = self::path($entry['file'], $relativeDir);
                }
            }
        }
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
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function rebaseService(array $service, string $relativeDir): array
    {
        $build = $service['build'] ?? null;
        if (is_string($build)) {
            $service['build'] = self::path($build, $relativeDir);
        } elseif (is_array($build)) {
            // `dockerfile` is relative to the context, so it moves with it.
            $service['build']['context'] = self::path(is_string($build['context'] ?? null) ? $build['context'] : '.', $relativeDir);
            if (is_array($build['additional_contexts'] ?? null)) {
                foreach ($build['additional_contexts'] as $key => $context) {
                    if (is_string($context)) {
                        $service['build']['additional_contexts'][$key] = self::path($context, $relativeDir);
                    }
                }
            }
        }

        if (is_array($service['volumes'] ?? null)) {
            foreach ($service['volumes'] as $i => $volume) {
                $service['volumes'][$i] = self::rebaseVolume($volume, $relativeDir);
            }
        }

        $envFile = $service['env_file'] ?? null;
        if (is_string($envFile)) {
            $service['env_file'] = self::path($envFile, $relativeDir);
        } elseif (is_array($envFile)) {
            foreach ($envFile as $i => $entry) {
                if (is_string($entry)) {
                    $service['env_file'][$i] = self::path($entry, $relativeDir);
                } elseif (is_array($entry) && is_string($entry['path'] ?? null)) {
                    $service['env_file'][$i]['path'] = self::path($entry['path'], $relativeDir);
                }
            }
        }

        if (is_array($service['extends'] ?? null) && is_string($service['extends']['file'] ?? null)) {
            $service['extends']['file'] = self::path($service['extends']['file'], $relativeDir);
        }

        return $service;
    }

    /**
     * Only a bind whose source starts with `.` is relative; anything else is a
     * named volume, an absolute path or `~`, and means the same from anywhere.
     */
    private static function rebaseVolume(mixed $volume, string $relativeDir): mixed
    {
        if (is_string($volume)) {
            $parts = explode(':', $volume, 2);
            if (count($parts) === 2 && str_starts_with($parts[0], '.')) {
                return self::path($parts[0], $relativeDir) . ':' . $parts[1];
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

    /**
     * $path as written in the nested file, as a `./`-relative path from the
     * root. Absolute paths, URLs and interpolations are left alone.
     */
    public static function path(string $path, string $relativeDir): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || str_starts_with($trimmed, '/') || str_starts_with($trimmed, '~')
            || str_starts_with($trimmed, '$') || preg_match('#^[a-z][a-z0-9+.-]*://|^git@#i', $trimmed) === 1
        ) {
            return $path;
        }

        $segments = [];
        foreach (explode('/', trim($relativeDir, '/') . '/' . $trimmed) as $segment) {
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

        return ($segments[0] === '..' ? '' : './') . implode('/', $segments);
    }
}
