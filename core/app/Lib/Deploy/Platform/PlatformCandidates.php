<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
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

    /** The strategies of a repository that says how it runs: its checkout is an application, never a site of files. */
    private const BUILDS_ITSELF = [Strategies::DOCKERFILE, Strategies::COMPOSE];

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
     * The recipes a failed start falls back through, in the order a deploy tries them: the detected ones, by priority,
     * without the placeholder, which runs nothing, Railpack, or a static site below a Dockerfile or Compose file.
     * None when a source recipe names the platform, or when an app config chose what runs without naming one
     * ({@see choosesWhatRuns()}).
     *
     * @return list<string>
     *
     * @throws ManifestException
     */
    public static function fallbackOrder(ProjectContext $context, ?string $directory = null): array
    {
        $hit = self::appConfig($context);
        if ($hit !== null && self::choosesWhatRuns($hit)) {
            return [];
        }
        $ids = [];
        $buildsItself = false;
        foreach (self::forContext($context, $directory) as $candidate) {
            if ($candidate['via'] === self::VIA_RECIPE) {
                return [];
            }
            if ($candidate['via'] !== self::VIA_DETECTED || $candidate['strategy'] === Strategies::FALLBACK) {
                continue;
            }
            if (! $buildsItself || $candidate['strategy'] !== Strategies::STATIC) {
                $ids[] = $candidate['id'];
            }
            $buildsItself = $buildsItself || in_array($candidate['strategy'], self::BUILDS_ITSELF, true);
        }

        return $ids;
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
        $hit = self::appConfig($context);
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
     * The project's own app config, else the engine's recipe for the repository it was cloned from.
     *
     * @return array{config: AppConfig, path: string, kind: string, origin: string}|null
     *
     * @throws ManifestException
     */
    private static function appConfig(ProjectContext $context): ?array
    {
        return AppConfigLocator::findCandidate(new LocalAppConfigSource, $context->projectDir, $context->sourceUrl);
    }

    /**
     * The engine's recipe, or a repository config with a compose file or credentials, which another recipe would drop.
     *
     * @param  array{config: AppConfig, path: string, kind: string, origin: string}  $hit
     */
    private static function choosesWhatRuns(array $hit): bool
    {
        return $hit['origin'] === AppConfigLocator::ORIGIN_ENGINE
            || $hit['config']->compose() !== null
            || $hit['config']->credentials() !== null;
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
