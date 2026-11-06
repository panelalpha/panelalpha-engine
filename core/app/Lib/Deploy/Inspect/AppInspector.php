<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Detect\DeployRefusal;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Inspect\Report\AppConfigOrigin;
use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Inspect\Report\EnvironmentReport;
use App\Lib\Deploy\Inspect\Report\MarkerFiles;
use App\Lib\Deploy\Inspect\Report\PortsReport;
use App\Lib\Deploy\Inspect\Report\ServicesReport;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\Metadata\MetadataRegistry;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Findings\Finding;
use InvalidArgumentException;

/**
 * What an application is and what the engine would run with it, by DetectProjectStrategy's own code.
 * It only reads, returning .env keys but never values.
 */
final class AppInspector
{
    public const DETECTION_FAILED = 'detection_failed';

    public const MANIFEST_INVALID = 'manifest_invalid';

    /** Keys reported from .env files. A generated file can hold thousands. */
    public const MAX_ENV_KEYS = EnvironmentReport::MAX_KEYS;

    /**
     * A DeployPlan or a `$recipe` previews that deploy rather than the default one.
     *
     * @return array{
     *   application: array<string, mixed>,
     *   metadata: array<string, mixed>,
     *   ports: array<string, mixed>,
     *   services: list<array<string, mixed>>,
     *   environment: array<string, mixed>,
     *   files: list<string>,
     * }
     */
    public static function inspect(
        string $projectDir,
        ?string $repoUrl = null,
        ?DeployPlan $plan = null,
        ?string $recipe = null
    ): array {
        $projectDir = rtrim($projectDir, '/');

        return AccountHomeRead::confinedTo($projectDir, static fn (): array => self::report($projectDir, $repoUrl, $plan, $recipe));
    }

    /**
     * The plan is applied as in the deploy, or the preview would show build commands the deploy will not run.
     * `metadata` is which application the directory holds, independent of what the engine does with it.
     *
     * @return array{
     *   application: array<string, mixed>,
     *   metadata: array<string, mixed>,
     *   ports: array<string, mixed>,
     *   services: list<array<string, mixed>>,
     *   environment: array<string, mixed>,
     *   files: list<string>,
     * }
     */
    private static function report(string $projectDir, ?string $repoUrl, ?DeployPlan $plan, ?string $recipe): array
    {
        $context = ProjectContext::at($projectDir, $repoUrl);
        [$appConfig, $origin] = AppConfigOrigin::find($projectDir, $repoUrl);
        [$decision, $issue] = self::decide($projectDir, $repoUrl, $recipe);
        $decision = $plan?->applyToDecision($decision) ?? $decision;

        return [
            'application' => (new ApplicationReport(
                $projectDir,
                $context,
                $decision,
                $origin,
                $appConfig,
                $issue,
                $plan,
                $recipe
            ))->build(),
            'metadata' => MetadataRegistry::describe($context, self::runtimeOf($decision)),
            'ports' => PortsReport::of($projectDir, $decision),
            'services' => ServicesReport::of($projectDir, self::composePathOf($decision)),
            'environment' => EnvironmentReport::of($projectDir, $decision),
            'files' => MarkerFiles::presentIn($projectDir),
        ];
    }

    /**
     * A project the engine cannot resolve is a finding, not a server error.
     *
     * @return array{0: array<string, mixed>, 1: ?Finding}
     */
    private static function decide(
        string $projectDir,
        ?string $repoUrl = null,
        ?string $recipe = null
    ): array {
        try {
            return [DetectProjectStrategy::detect($projectDir, $repoUrl, $recipe), null];
        } catch (ManifestException $e) {
            return [ApplicationReport::undeployable(), DeployRefusal::findingOf($e, self::MANIFEST_INVALID)];
        } catch (InvalidArgumentException $e) {
            return [ApplicationReport::undeployable(), DeployRefusal::findingOf($e, self::DETECTION_FAILED)];
        }
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private static function composePathOf(array $decision): ?string
    {
        $path = $decision['compose_path'] ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private static function runtimeOf(array $decision): ?string
    {
        $runtime = $decision['runtime'] ?? null;

        return is_string($runtime) ? $runtime : null;
    }
}
