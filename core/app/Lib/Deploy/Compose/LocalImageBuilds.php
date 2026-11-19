<?php

namespace App\Lib\Deploy\Compose;

/**
 * A service that runs a tag only the repository builds (`image: go-admin:latest` and no `build:`, the Makefile running
 * `docker build -t go-admin:latest .`) gets that build, so Compose builds the tag instead of asking Docker Hub for it.
 * Only for a tag that names no registry, that nothing in the file builds and that no registry publishes, and only
 * when the repository points at one build for it.
 */
final class LocalImageBuilds
{
    /** Past this many unbuilt tags, the sole Dockerfile is not matched to any of them. */
    private const MAX_SOLE_CLAIMANTS = 8;

    /**
     * @param  array<string, mixed>  $compose  rebased to the project root
     * @param  array<string, list<?string>|string>  $env
     * @param  callable(string): ?bool  $published  whether a registry has the image, null when that cannot be told
     * @return array{compose: array<string, mixed>, built: list<array{service: string, image: string, evidence: string}>}
     */
    public static function apply(array $compose, LocalImageEvidence $evidence, array $env, callable $published): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $unbuilt = self::unbuiltLocalImages($services, $env);
        if ($unbuilt === []) {
            return ['compose' => $compose, 'built' => []];
        }
        $isMissing = self::missingCheck($published);
        $assigned = self::explicitBuilds($unbuilt, $evidence, $isMissing);
        $assigned += self::soleDockerfileBuild(array_diff_key($unbuilt, $assigned), $assigned, $services, $evidence, $isMissing);

        $built = [];
        foreach ($assigned as $image => $candidate) {
            $service = $unbuilt[$image][0];
            $compose['services'][$service]['build'] = $candidate['build'];
            $built[] = ['service' => $service, 'image' => $image, 'evidence' => $candidate['evidence']];
        }

        return ['compose' => $compose, 'built' => $built];
    }

    /**
     * The log line for one service given a build.
     *
     * @param  array{service: string, image: string, evidence: string}  $built
     */
    public static function line(array $built): string
    {
        return "Service {$built['service']} runs {$built['image']}, which no registry publishes and nothing in the compose file builds; "
            ."building it from the repository, as {$built['evidence']}";
    }

    /**
     * @param  array<string, list<string>>  $unbuilt
     * @param  callable(string): bool  $isMissing
     * @return array<string, array{build: array<string, mixed>, dockerfile: string, evidence: string}>
     */
    private static function explicitBuilds(array $unbuilt, LocalImageEvidence $evidence, callable $isMissing): array
    {
        $assigned = [];
        foreach ($unbuilt as $image => $services) {
            $candidate = $evidence->explicitBuild($image, $services);
            if ($candidate !== null && $isMissing($image)) {
                $assigned[$image] = $candidate;
            }
        }

        return $assigned;
    }

    /**
     * The one Dockerfile beside the compose file, for the one unbuilt tag no registry has: two such tags could each
     * be its image, so neither gets it. Only a bare name is claimed: a Docker Hub `owner/name` the hub lacks was
     * published by someone and withdrawn (MinIO's `minio/minio`), not the repository's own build.
     *
     * @param  array<string, list<string>>  $unbuilt
     * @param  array<string, array{build: array<string, mixed>, dockerfile: string, evidence: string}>  $assigned
     * @param  array<mixed>  $services
     * @param  callable(string): bool  $isMissing
     * @return array<string, array{build: array<string, mixed>, dockerfile: string, evidence: string}>
     */
    private static function soleDockerfileBuild(array $unbuilt, array $assigned, array $services, LocalImageEvidence $evidence, callable $isMissing): array
    {
        if ($unbuilt === [] || count($unbuilt) > self::MAX_SOLE_CLAIMANTS) {
            return [];
        }
        $built = [...$evidence->dockerfilesBuiltBy($services), ...array_column($assigned, 'dockerfile')];
        $sole = $evidence->soleDockerfile($built);
        if ($sole === null) {
            return [];
        }
        $claimants = array_values(array_filter(array_keys($unbuilt), $isMissing));

        return count($claimants) === 1 && self::isBareName($claimants[0]) ? [$claimants[0] => $sole] : [];
    }

    /** Whether $image names no owner, as `go-admin:latest` does and `minio/minio:latest` does not. */
    private static function isBareName(string $image): bool
    {
        return ! str_contains($image, '/');
    }

    /**
     * Each tag a service runs without building, that names no registry and no other service builds, with the
     * services running it in file order.
     *
     * @param  array<mixed>  $services
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, list<string>>
     */
    private static function unbuiltLocalImages(array $services, array $env): array
    {
        $builtHere = DeployCompose::imagesBuiltHere($services, $env);
        $unbuilt = [];
        foreach ($services as $name => $service) {
            if (! is_array($service) || isset($service['build'])) {
                continue;
            }
            $image = DeployCompose::resolvedImageRef($service['image'] ?? null, $env);
            if ($image === null || str_contains($image, '@') || self::namesRegistry($image)
                || in_array(strtolower($image), $builtHere, true)
            ) {
                continue;
            }
            $unbuilt[$image][] = (string) $name;
        }

        return $unbuilt;
    }

    /** Whether $image names a registry host, as `ghcr.io/x/y` or `localhost:5000/y` do. */
    private static function namesRegistry(string $image): bool
    {
        $parts = explode('/', $image);
        $first = $parts[0];

        return count($parts) > 1 && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost');
    }

    /**
     * @param  callable(string): ?bool  $published
     * @return callable(string): bool true only when a registry answered that it has no such image
     */
    private static function missingCheck(callable $published): callable
    {
        $answers = [];

        return static function (string $image) use ($published, &$answers): bool {
            if (! array_key_exists($image, $answers)) {
                $answers[$image] = $published($image) === false;
            }

            return $answers[$image];
        };
    }
}
