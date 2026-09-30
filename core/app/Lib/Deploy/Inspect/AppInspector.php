<?php

namespace App\Lib\Deploy\Inspect;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Inspect\Report\EnvironmentReport;
use App\Lib\Deploy\Inspect\Report\MarkerFiles;
use App\Lib\Deploy\Inspect\Report\AppConfigOrigin;
use App\Lib\Deploy\Inspect\Report\PortsReport;
use App\Lib\Deploy\Inspect\Report\ServicesReport;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\Metadata\MetadataRegistry;
use App\Lib\Deploy\Platform\ProjectContext;
use InvalidArgumentException;

/**
 * What an application is: stack, ports, backing services, the variables it expects, and
 * what the engine would run — DetectProjectStrategy's own code plus the toolchain versions
 * and datastores it consumes. It only reads, returning .env keys but never values.
 */
final class AppInspector
{
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
        $context = ProjectContext::at($projectDir, $repoUrl);
        [$appConfig, $origin] = AppConfigOrigin::find($projectDir, $repoUrl);
        [$decision, $issue] = self::decide($projectDir, $repoUrl, $recipe);
        // Applied here as well as in the deploy, or the preview would show build commands
        // the deploy is not going to run.
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
            // Separate from `application`: that is what the engine will do with this
            // directory, this is which application it holds, and the two are independent.
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
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private static function decide(
        string $projectDir,
        ?string $repoUrl = null,
        ?string $recipe = null
    ): array {
        try {
            return [DetectProjectStrategy::detect($projectDir, $repoUrl, $recipe), null];
        } catch (InvalidArgumentException | ManifestException $e) {
            return [ApplicationReport::undeployable(), $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $decision
     */
    private static function composePathOf(array $decision): ?string
    {
        $path = $decision['compose_path'] ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private static function runtimeOf(array $decision): ?string
    {
        $runtime = $decision['runtime'] ?? null;

        return is_string($runtime) ? $runtime : null;
    }
}
