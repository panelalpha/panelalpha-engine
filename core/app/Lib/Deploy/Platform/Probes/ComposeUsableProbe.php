<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\System\Project\Dind\Strategy\AppConfigBootstrap;

/**
 * A compose file worth running as-is, the app config's `replace`-mode one ({@see AppConfigBootstrap}) read first;
 * a missing Dockerfile reference is accepted when the repo has a root Dockerfile. Yields `compose_path` or false.
 */
final class ComposeUsableProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'compose-usable';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $candidates = [EngineArtifacts::APP_CONFIG_COMPOSE, ...ComposeFileInspector::COMPOSE_FILE_CANDIDATES];
        foreach ($candidates as $candidate) {
            if (! $context->hasFile(strtolower($candidate)) || ! $context->isFile($candidate)) {
                continue;
            }
            $path = $context->path($candidate);
            if ($this->rejects($candidate, $path, $context)) {
                continue;
            }

            $missing = ComposeFileInspector::missingComposeDockerfileRefs($path, $context->projectDir);
            if ($missing === [] || $context->isFile('Dockerfile')) {
                return ['compose_path' => $path];
            }
        }

        return false;
    }

    /**
     * The generated bootstrap and v1 files always; the workstation, sidecars-only and starts-nothing guesses only for a
     * repository's own file, as an app config's was written for the engine on purpose.
     */
    private function rejects(string $candidate, string $path, ProjectContext $context): bool
    {
        $guess = $candidate !== EngineArtifacts::APP_CONFIG_COMPOSE;

        return ComposeFileInspector::isGeneratedBootstrapCompose($path)
            || ($guess && ComposeFileInspector::isLocalDevCompose($path))
            || ($guess && ComposeFileInspector::isSidecarsOnlyCompose($path))
            || ComposeFileInspector::isLegacyV1Compose($path)
            || ($guess && $this->startsNothing($candidate, $context));
    }

    private function startsNothing(string $candidate, ProjectContext $context): bool
    {
        $raw = $context->contents($candidate);
        $profiles = ComposeFileInspector::profilesFromEnvFile($context->contents('.env'));

        return is_string($raw) && ComposeFileInspector::startsNothingReasonYaml($raw, $profiles) !== null;
    }
}
