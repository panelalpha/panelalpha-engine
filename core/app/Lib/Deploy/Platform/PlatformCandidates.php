<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;

/**
 * Every recipe that could deploy this project, best first: the source recipe
 * for this repository, then every manifest whose `detect` block matches in
 * descending `priority`, then Railpack if a toolchain recognises the project.
 * `fallback` is never a candidate.
 *
 * `via` says how a candidate got here.
 */
final class PlatformCandidates
{
    /** How a candidate got onto the list: named by the request, detected, or Railpack. */
    public const VIA_RECIPE = 'recipe';
    public const VIA_DETECTED = 'detected';
    public const VIA_FALLBACK = 'fallback';

    /** The last resort, which is code rather than a manifest. */
    public const RAILPACK = Strategies::RAILPACK;

    /**
     * @return list<array{id: string, label: string, priority: int, strategy: string, via: string}>
     * @throws ManifestException
     */
    public static function forContext(ProjectContext $context, ?string $directory = null): array
    {
        $candidates = [];

        $recipe = self::sourceRecipe($context);
        if ($recipe !== null) {
            self::add($candidates, $recipe, self::VIA_RECIPE);
        }

        $matcher = new PlatformMatcher(ProbeRegistry::all());
        foreach (PlatformRegistry::all($directory) as $manifest) {
            if ($matcher->matches($manifest->detect, $context)) {
                self::add($candidates, $manifest, self::VIA_DETECTED);
            }
        }

        if (RuntimeRegistry::anyRecognises($context)) {
            $candidates[] = [
                'id' => self::RAILPACK,
                'label' => 'Railpack',
                'priority' => 0,
                'strategy' => Strategies::RAILPACK,
                'via' => self::VIA_FALLBACK,
            ];
        }

        return $candidates;
    }

    /**
     * The recipe written for the repository this project was cloned from, or
     * shipped inside the checkout itself.
     *
     * @throws ManifestException
     */
    private static function sourceRecipe(ProjectContext $context): ?PlatformManifest
    {
        $hit = AppConfigLocator::findCandidate(
            new LocalAppConfigSource(),
            $context->projectDir,
            $context->sourceUrl
        );
        if ($hit === null) {
            return null;
        }

        // The recipe's own checks, as PlatformSelector::fromSource() passes them.
        return SourceRecipes::fromAppConfig(
            $hit['config'],
            AppConfigLocator::describe($hit),
            SourceRecipes::checksDirectory($hit['path'])
        );
    }

    /**
     * @param list<array{id: string, label: string, priority: int, strategy: string, via: string}> $candidates
     */
    private static function add(array &$candidates, PlatformManifest $manifest, string $via): void
    {
        foreach ($candidates as $candidate) {
            // A source recipe usually shares a shipped manifest's id, because
            // it extends it, and has already spoken for that id.
            if ($candidate['id'] === $manifest->id) {
                return;
            }
        }

        $candidates[] = [
            'id' => $manifest->id,
            'label' => $manifest->label,
            'priority' => $manifest->priority,
            'strategy' => $manifest->strategy,
            'via' => $via,
        ];
    }
}
