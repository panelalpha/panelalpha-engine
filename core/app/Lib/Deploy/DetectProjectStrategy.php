<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Detect\DetectionResult;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\AppConfigStack;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformSelector;
use App\Lib\Deploy\Platform\PlatformValues;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Strategies;

/**
 * Detect how to run a project: `resources/sources/<host>/<owner>/<repo>/`, then the platform and app manifests by
 * descending `priority`, then fallback. Railpack builds only a project whose request names it.
 */
class DetectProjectStrategy
{
    public const COMPOSE_GENERATED_LABEL = 'static-bootstrap';

    /**
     * A pinned Railpack is answered here, since it has no manifest to pin to.
     *
     * @param  ?string  $sourceUrl  the repository the checkout was cloned from,
     *                              when there was one; an uploaded archive has none.
     * @param  ?string  $recipe  the recipe the request named, which replaces the
     *                           whole of the above: no rule is evaluated.
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
     * @throws ManifestException on an unknown `$recipe`
     */
    public static function detect(
        string $projectDir,
        ?string $sourceUrl = null,
        ?string $recipe = null
    ): array {
        $context = ProjectContext::at($projectDir, $sourceUrl);

        if ($recipe === Strategies::RAILPACK) {
            return DetectionResult::of(Strategies::RAILPACK, 'Railpack');
        }

        return self::fromPlatforms($context, $recipe) ?? self::unknown();
    }

    /**
     * The first platform or app manifest, by descending `priority`, whose `detect` block claims the project; its app
     * config speaks for the build stage too when written for that stack.
     *
     * @return array<string, mixed>|null
     */
    private static function fromPlatforms(ProjectContext $context, ?string $recipe = null): ?array
    {
        $hit = PlatformSelector::forContext($context, null, $recipe);
        if ($hit === null) {
            return null;
        }

        $appConfig = AppConfigStack::for($hit['decision'], AppConfigLocator::findCandidate(
            new LocalAppConfigSource,
            $context->projectDir,
            $context->sourceUrl
        )['config'] ?? null);

        return DetectionResult::fromPlatform(
            PlatformValues::withAppConfigBuild($hit['manifest'], $appConfig, $context, $hit['decision'])
        );
    }

    /** @return array<string, mixed> */
    private static function unknown(): array
    {
        return DetectionResult::of(Strategies::FALLBACK, 'Unknown');
    }
}
