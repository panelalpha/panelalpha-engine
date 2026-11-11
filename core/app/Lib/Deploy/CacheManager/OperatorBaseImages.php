<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Detect\DockerfileFinder;

/**
 * The operator images a compose file's builds start `FROM`, fetched from the cache registry before the build
 * because BuildKit finds a base in the account's daemon without asking a public registry.
 */
final class OperatorBaseImages
{
    /**
     * @param  list<string>  $operatorRefs
     * @param  callable(string): ?string  $read  an absolute path to its contents, null when unreadable
     * @param  list<string>|null  $activeProfiles
     * @return list<string> the operator refs to fetch, as the operator named them
     */
    public static function neededBy(
        string $composePath,
        string $composeYaml,
        array $operatorRefs,
        callable $read,
        ?array $activeProfiles = null
    ): array {
        if ($operatorRefs === []) {
            return [];
        }
        $byName = [];
        foreach ($operatorRefs as $ref) {
            $byName[OperatorImageRef::normalize($ref)] = $ref;
        }

        $needed = [];
        foreach (DeployCompose::buildSpecs($composeYaml, $activeProfiles) as $build) {
            foreach (self::fromRefs(self::dockerfileOf($composePath, $build, $read)) as $from) {
                $ref = $byName[OperatorImageRef::normalize($from)] ?? null;
                if ($ref !== null) {
                    $needed[$ref] = true;
                }
            }
        }

        return array_keys($needed);
    }

    /**
     * Every base a Dockerfile names literally: not an earlier stage, not
     * `scratch`, and not a name built from an ARG.
     *
     * @return list<string>
     */
    public static function fromRefs(string $dockerfile): array
    {
        $stages = [];
        $refs = [];
        foreach (preg_split('/\R/', DockerfileFinder::withoutHeredocBodies($dockerfile)) ?: [] as $line) {
            if (preg_match(DockerfileFinder::FROM_LINE_PATTERN, $line, $m) !== 1) {
                continue;
            }
            $from = $m[1];
            if (! isset($stages[strtolower($from)]) && strtolower($from) !== 'scratch' && ! str_contains($from, '$')) {
                $refs[$from] = true;
            }
            if (isset($m[2])) {
                $stages[strtolower($m[2])] = true;
            }
        }

        return array_keys($refs);
    }

    /**
     * @param  array<string, mixed>|string  $build
     * @param  callable(string): ?string  $read
     */
    private static function dockerfileOf(string $composePath, array|string $build, callable $read): string
    {
        if (is_array($build) && is_string($build['dockerfile_inline'] ?? null)) {
            return $build['dockerfile_inline'];
        }
        $projectDir = rtrim(dirname($composePath), '/');
        $path = ComposeFileInspector::composeBuildDockerfileAbsolute($projectDir, $build);
        if ($path === null || ! str_starts_with($path, $projectDir.'/')) {
            return '';
        }

        return $read($path) ?? '';
    }
}
