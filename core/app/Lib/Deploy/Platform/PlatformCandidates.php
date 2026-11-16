<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;

/**
 * Every recipe that could deploy this project, best first: its source recipe, matching manifests by priority, then
 * Railpack if a toolchain recognises something that may serve; `via` says how each got here.
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
     *
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

        if (RuntimeRegistry::anyRecognises($context) && ! self::onlyDotnetServingNothing($context)) {
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
     * Railpack publishes .NET as the engine does, so a repository only .NET reads whose projects serve no HTTP (desktop
     * apps, libraries, tests) runs there no more than here.
     */
    public static function onlyDotnetServingNothing(ProjectContext $context): bool
    {
        return RuntimeRegistry::recognisedBy($context) === ['dotnet']
            && DotnetRuntime::hasProject($context->projectDir)
            && DotnetRuntime::servesNothing($context->projectDir)
            && ! self::anyProjectUnread($context);
    }

    /** An inspection writes a file it did not fetch empty, and that project may be the one that serves. */
    private static function anyProjectUnread(ProjectContext $context): bool
    {
        foreach (DotnetRuntime::findProjectFiles($context->projectDir) as $relative) {
            if (trim((string) $context->contents($relative)) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The recipe written for the repository this project was cloned from, or shipped in the checkout, with its
     * own checks as PlatformSelector::fromSource() passes them.
     *
     * @throws ManifestException
     */
    private static function sourceRecipe(ProjectContext $context): ?PlatformManifest
    {
        $hit = AppConfigLocator::findCandidate(
            new LocalAppConfigSource,
            $context->projectDir,
            $context->sourceUrl
        );
        if ($hit === null) {
            return null;
        }

        return SourceRecipes::fromAppConfig(
            $hit['config'],
            AppConfigLocator::describe($hit),
            SourceRecipes::checksDirectory($hit['path'])
        );
    }

    /**
     * The first candidate for an id wins: a source recipe extending a shipped manifest shares its id.
     *
     * @param  list<array{id: string, label: string, priority: int, strategy: string, via: string}>  $candidates
     */
    private static function add(array &$candidates, PlatformManifest $manifest, string $via): void
    {
        foreach ($candidates as $candidate) {
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
