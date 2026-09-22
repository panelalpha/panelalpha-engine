<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;

/**
 * Which manifest claims this project: the source recipe or app config written
 * for this repository if there is one, otherwise the first manifest whose
 * `detect` block matches, in descending `priority` order.
 */
final class PlatformSelector
{
    /**
     * The platform for this project, or null when no manifest claims it —
     * the signal for the caller to fall through to Railpack.
     *
     * @param array<string, true> $files lowercase basename => true
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     */
    public static function detect(
        string $projectDir,
        array $files,
        ?string $directory = null,
        ?string $sourceUrl = null
    ): ?array {
        return self::forContext(ProjectContext::make($projectDir, $files, $sourceUrl), $directory);
    }

    /**
     * A `$recipe` id pins the answer: the named manifest describes the
     * project whether or not its `detect` block would have claimed it, and an
     * id this engine does not ship is an error, not a fall-through.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     * @throws ManifestException when `$recipe` names no recipe this engine has
     */
    public static function forContext(
        ProjectContext $context,
        ?string $directory = null,
        ?string $recipe = null
    ): ?array {
        if (is_string($recipe) && trim($recipe) !== '') {
            return self::pinned($context, $directory, trim($recipe));
        }

        return self::fromSource($context) ?? self::fromWalk($context, $directory);
    }

    /**
     * The recipe a request named, described for this project. Detection is
     * skipped — only the existence of the recipe is still checked.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}
     * @throws ManifestException
     */
    private static function pinned(ProjectContext $context, ?string $directory, string $id): array
    {
        // The source recipe first: a source recipe usually shares a shipped
        // manifest's id, and pinning must reach the one inspection reported.
        $hit = self::fromSource($context);
        if ($hit !== null && $hit['manifest']->id === $id) {
            return $hit;
        }

        foreach (PlatformRegistry::all($directory) as $manifest) {
            if ($manifest->id === $id) {
                $matcher = new PlatformMatcher(ProbeRegistry::all());
                // Run detect anyway, discarding the verdict: the probes supply
                // fields a pinned deploy needs too.
                $matcher->matches($manifest->detect, $context);

                return [
                    'manifest' => $manifest,
                    'decision' => $manifest->describe($context, $matcher->lastProbeData()),
                ];
            }
        }

        throw new ManifestException(
            "No recipe called '{$id}'. Inspect the source to see which recipes can deploy it."
        );
    }

    /**
     * The platform this project's own app config names — its `.panelalpha/`,
     * or the engine's directory for the repository it was cloned from.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     */
    private static function fromSource(ProjectContext $context): ?array
    {
        $hit = AppConfigLocator::findCandidate(
            new LocalAppConfigSource(),
            $context->projectDir,
            $context->sourceUrl
        );
        $manifest = SourceRecipes::fromAppConfig(
            $hit['config'] ?? null,
            $hit === null ? '' : AppConfigLocator::describe($hit),
            // The recipe's own checks, so a `check:` naming one resolves at
            // selection time the same way it does through SourceRecipes::at().
            $hit === null ? null : SourceRecipes::checksDirectory($hit['path'])
        );
        if ($manifest === null || $hit === null) {
            return null;
        }

        $decision = $manifest->describe($context);
        // So a deploy log and an inspection say why this recipe was chosen.
        $decision['source_recipe'] = AppConfigLocator::describe($hit);

        return ['manifest' => $manifest, 'decision' => $decision];
    }

    /**
     * The manifests in `resources/platforms/` and `resources/apps/`, in
     * descending priority: the first whose `detect` block claims the project.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     */
    private static function fromWalk(ProjectContext $context, ?string $directory): ?array
    {
        $matcher = new PlatformMatcher(ProbeRegistry::all());

        foreach (PlatformRegistry::all($directory) as $manifest) {
            if (!$matcher->matches($manifest->detect, $context)) {
                continue;
            }

            return [
                'manifest' => $manifest,
                'decision' => $manifest->describe($context, $matcher->lastProbeData()),
            ];
        }

        return null;
    }
}
