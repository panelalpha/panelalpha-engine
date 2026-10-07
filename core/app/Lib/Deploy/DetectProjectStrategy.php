<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Detect\DetectionResult;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\PlatformSelector;
use App\Lib\Deploy\Platform\PlatformValues;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Strategies;

/**
 * Detect how to run a project, in precedence order: `resources/sources/
 * <host>/<owner>/<repo>/`, then the manifests under `resources/platforms/` and
 * `resources/apps/` by descending `priority`, then Railpack, then fallback.
 */
class DetectProjectStrategy
{
    public const COMPOSE_GENERATED_LABEL = 'static-bootstrap';

    /**
     * @return array{
     *   strategy: string,
     *   label: string,
     *   compose_path: ?string,
     *   dockerfile: ?string,
     *   port_hint: ?int,
     *   runtime: ?string,
     *   output_directory: ?string,
     *   package_manager: ?string,
     *   install_command: ?string,
     *   build_command: ?string,
     *   start_command: ?string,
     *   env: ?array<string, string>,
     *   static_index: ?string,
     * }
     *
     * @param ?string $sourceUrl the repository the checkout was cloned from,
     *        when there was one; an uploaded archive has none.
     * @param ?string $recipe the recipe the request named, which replaces the
     *        whole of the above: no rule is evaluated.
     * @throws \App\Lib\Deploy\Platform\ManifestException on an unknown `$recipe`
     */
    public static function detect(
        string $projectDir,
        ?string $sourceUrl = null,
        ?string $recipe = null
    ): array {
        $context = ProjectContext::at($projectDir, $sourceUrl);

        // Railpack has no manifest to pin to, so pinning it is answered here:
        // otherwise the only way to reach Railpack is to be unrecognisable.
        if ($recipe === Strategies::RAILPACK) {
            return DetectionResult::of(Strategies::RAILPACK, 'Railpack');
        }

        return self::fromPlatforms($context, $recipe)
            ?? self::fromRailpack($context)
            ?? self::unknown();
    }

    /**
     * The platforms and apps in `resources/platforms/` and `resources/apps/`,
     * by descending `priority`: the first whose `detect` block claims it.
     *
     * @return array<string, mixed>|null
     */
    private static function fromPlatforms(ProjectContext $context, ?string $recipe = null): ?array
    {
        $hit = PlatformSelector::forContext($context, null, $recipe);
        if ($hit === null) {
            return null;
        }

        // The project's app config speaks for the build stage too.
        $appConfig = AppConfigLocator::findCandidate(
            new LocalAppConfigSource(),
            $context->projectDir,
            $context->sourceUrl
        )['config'] ?? null;

        return DetectionResult::fromPlatform(
            PlatformValues::withAppConfigBuild($hit['manifest'], $appConfig, $context, $hit['decision'])
        );
    }

    /**
     * Railpack packs the long tail: anything no platform claims but some
     * runtime in RuntimeRegistry recognises.
     *
     * @return array<string, mixed>|null
     */
    private static function fromRailpack(ProjectContext $context): ?array
    {
        return RuntimeRegistry::anyRecognises($context)
            ? DetectionResult::of(Strategies::RAILPACK, 'Railpack')
            : null;
    }

    /** @return array<string, mixed> */
    private static function unknown(): array
    {
        return DetectionResult::of(Strategies::FALLBACK, 'Unknown');
    }
}
