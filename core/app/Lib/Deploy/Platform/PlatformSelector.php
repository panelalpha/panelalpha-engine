<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Probes\ComposeUsableProbe;
use App\Lib\Deploy\Platform\Probes\DockerfileProbe;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;

/**
 * Which manifest claims this project: the source recipe or app config written for this repository if there is one, otherwise
 * the first manifest whose `detect` block matches, in descending `priority` order.
 */
final class PlatformSelector
{
    /**
     * The platform for this project, or null when no manifest claims it —
     * the signal for the caller to fall through to the placeholder page.
     *
     * @param  array<string, true>  $files  lowercase basename => true
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
     * A `$recipe` id pins the answer: the named manifest describes the project whether or not its `detect` block would have
     * claimed it, and an id this engine does not ship is an error, not a fall-through.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     *
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
     * The recipe a request named, described for this project: only its existence is checked, and detect runs just for the probes'
     * fields. The source recipe comes first, as it usually shares a shipped manifest's id and pinning must reach the one inspected.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}
     *
     * @throws ManifestException
     */
    private static function pinned(ProjectContext $context, ?string $directory, string $id): array
    {
        $hit = self::fromSource($context);
        if ($hit !== null && $hit['manifest']->id === $id) {
            return $hit;
        }

        foreach (PlatformRegistry::all($directory) as $manifest) {
            if ($manifest->id === $id) {
                $matcher = new PlatformMatcher(ProbeRegistry::all());
                $matcher->matches($manifest->detect, $context);

                return [
                    'manifest' => $manifest,
                    'decision' => $manifest->describe($context, $matcher->lastProbeData()),
                ];
            }
        }

        throw new UnknownRecipeException($id);
    }

    /**
     * The platform this project's own app config names (its `.panelalpha/` or the engine's directory for its repository), with the
     * recipe's checks so a `check:` resolves as through SourceRecipes::at(); `source_recipe` tells logs and inspections why.
     *
     * @return array{manifest: PlatformManifest, decision: array<string, mixed>}|null
     */
    private static function fromSource(ProjectContext $context): ?array
    {
        $hit = AppConfigLocator::findCandidate(
            new LocalAppConfigSource,
            $context->projectDir,
            $context->sourceUrl
        );
        $manifest = SourceRecipes::fromAppConfig(
            $hit['config'] ?? null,
            $hit === null ? '' : AppConfigLocator::describe($hit),
            $hit === null ? null : SourceRecipes::checksDirectory($hit['path'])
        );
        if ($manifest === null || $hit === null) {
            return null;
        }

        $decision = $manifest->describe(
            $context,
            self::probeData($manifest, $context, $hit['config']->manifest() ?? [])
        );
        $decision['source_recipe'] = AppConfigLocator::describe($hit);
        $decision['source_recipe_origin'] = AppConfigLocator::origin($hit);

        return ['manifest' => $manifest, 'decision' => $decision];
    }

    /**
     * Where the compose file or Dockerfile is, for a recipe that runs one: found by its path, no detect filled it, and other
     * strategies' probes would change what recipes build. A recipe's own `port:` outranks EXPOSE; an inherited one does not.
     *
     * @param  array<string, mixed>  $declared  the keys the recipe itself states
     * @return array<string, mixed>
     */
    private static function probeData(PlatformManifest $manifest, ProjectContext $context, array $declared): array
    {
        $found = match ($manifest->strategy) {
            Strategies::COMPOSE => (new ComposeUsableProbe)->evaluate($context),
            Strategies::DOCKERFILE => (new DockerfileProbe)->evaluate($context),
            default => false,
        };
        if (! is_array($found)) {
            return [];
        }
        if (($declared['port'] ?? null) !== null) {
            unset($found['port_hint']);
        }

        return $found;
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
            if (! $matcher->matches($manifest->detect, $context)) {
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
