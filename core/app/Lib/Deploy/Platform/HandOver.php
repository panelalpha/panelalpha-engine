<?php

namespace App\Lib\Deploy\Platform;

/**
 * A host-compiled recipe's `hand_over`: a file a build leaves when it made what another recipe serves, beside the output
 * directory, and that recipe. `next build` writes `.next/BUILD_ID` without `output: "export"` and `out/index.html` with it.
 */
final class HandOver
{
    private const KEYS = ['marker', 'recipe'];

    private const RUNTIMES = [PlatformManifest::RUNTIME_NGINX, PlatformManifest::RUNTIME_NODE];

    private function __construct(
        public readonly string $marker,
        public readonly string $recipe,
    ) {}

    /** @throws ManifestException */
    public static function read(ManifestReader $reader, string $runtime): ?self
    {
        if (! $reader->has('hand_over')) {
            return null;
        }
        $raw = $reader->raw('hand_over');
        if (! is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), self::KEYS) !== []) {
            throw $reader->fail('hand_over must be an object with marker and recipe');
        }
        if (! in_array($runtime, self::RUNTIMES, true)) {
            throw $reader->fail("hand_over is only supported for runtime 'nginx' or 'node'");
        }

        return new self(self::marker($reader, $raw['marker'] ?? null), self::recipe($reader, $raw['recipe'] ?? null));
    }

    /** The marker's path in the checkout, for a build whose output goes to $outputDirectory. */
    public function markerBeside(string $outputDirectory): string
    {
        $parent = dirname(trim($outputDirectory, '/'));

        return $parent === '.' ? $this->marker : $parent.'/'.$this->marker;
    }

    /** @throws ManifestException */
    private static function marker(ManifestReader $reader, mixed $marker): string
    {
        $segments = is_string($marker) ? explode('/', $marker) : [];
        if ($segments === [] || array_intersect($segments, ['', '.', '..']) !== []) {
            throw $reader->fail('hand_over.marker must be a relative file path');
        }

        return $marker;
    }

    /** @throws ManifestException */
    private static function recipe(ManifestReader $reader, mixed $recipe): string
    {
        if (! is_string($recipe) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $recipe) !== 1) {
            throw $reader->fail('hand_over.recipe must be a recipe id');
        }

        return $recipe;
    }
}
