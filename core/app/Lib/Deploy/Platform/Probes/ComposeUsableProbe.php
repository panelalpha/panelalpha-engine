<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A compose file worth running as-is: not the engine's generated bootstrap, not
 * a workstation file, not sidecars-only. A missing Dockerfile reference is
 * accepted when the repo has a root Dockerfile. Yields `compose_path` or false.
 *
 * An app config's `replace`-mode compose (ADR-0001: written under its own
 * reserved name, never the repository's) is read ahead of the repository's
 * own — it is what {@see \App\System\Project\Dind\Strategy\AppConfigBootstrap}
 * already decided the deploy should run.
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
            if (!$context->hasFile(strtolower($candidate)) || !$context->isFile($candidate)) {
                continue;
            }
            $path = $context->path($candidate);

            if (ComposeFileInspector::isGeneratedBootstrapCompose($path)
                || ComposeFileInspector::isLocalDevCompose($path)
                || ComposeFileInspector::isSidecarsOnlyCompose($path)
            ) {
                continue;
            }

            $missing = ComposeFileInspector::missingComposeDockerfileRefs($path, $context->projectDir);
            if ($missing === [] || $context->isFile('Dockerfile')) {
                return ['compose_path' => $path];
            }
        }

        return false;
    }
}
