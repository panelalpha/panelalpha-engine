<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\NestedCompose;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * A usable compose file in one of {@see NestedCompose::DIRECTORIES}, for a
 * repository that has nothing else to deploy: zoraxy and WikiDocs keep theirs
 * under docker/ and used to land on the placeholder page (engine#91).
 *
 * Only a project no platform and no Railpack runtime claims: its manifest sits
 * below every other one, and a runtime Railpack recognises vetoes it. So it
 * cannot take a repository that deploys some other way today.
 *
 * The same filters as the root probe, applied to the file as it reads from the
 * root. Yields `compose_path` or false.
 */
final class ComposeUsableNestedProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'compose-usable-nested';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        if (RuntimeRegistry::anyRecognises($context)) {
            return false;
        }

        foreach (NestedCompose::DIRECTORIES as $directory) {
            if (!$context->hasFile($directory) || !is_dir($context->path($directory))) {
                continue;
            }
            foreach (ComposeFileInspector::COMPOSE_FILE_CANDIDATES as $candidate) {
                $relative = $directory . '/' . $candidate;
                if (!$context->isFile($relative)) {
                    continue;
                }
                if ($this->usable($relative, $context)) {
                    return ['compose_path' => $context->path($relative)];
                }
            }
        }

        return false;
    }

    private function usable(string $relative, ProjectContext $context): bool
    {
        $path = $context->path($relative);
        $raw = $context->contents($relative);
        $parsed = is_string($raw) ? ComposeYaml::parse($raw) : null;
        if (!is_array($parsed) || !is_array($parsed['services'] ?? null)) {
            return false;
        }
        $fromRoot = Yaml::dump(NestedCompose::rebase($parsed, dirname($relative)), 6, 2);

        if (ComposeFileInspector::isGeneratedBootstrapCompose($path)
            || ComposeFileInspector::isLocalDevComposeYaml($fromRoot)
            || ComposeFileInspector::isSidecarsOnlyComposeYaml($fromRoot)
        ) {
            return false;
        }

        return ComposeFileInspector::missingComposeDockerfileRefs($path, $context->projectDir) === [];
    }
}
