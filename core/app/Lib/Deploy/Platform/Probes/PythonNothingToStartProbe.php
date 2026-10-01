<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;

/**
 * Python files with nothing to start, beside another stack's manifest.
 *
 * Jellysweep is a Go app whose requirements.txt installs only its docs and
 * lint tooling; python outranks go, found no entry point, and served the
 * placeholder. Here python steps aside so the other stack is tried.
 */
final class PythonNothingToStartProbe implements PlatformProbe
{
    /** Root manifests of the stacks ranked below python. */
    private const OTHER_STACKS = ['go.mod', 'cargo.toml', 'package.json', 'pom.xml', 'build.gradle', 'build.gradle.kts'];

    public function id(): string
    {
        return 'python-nothing-to-start';
    }

    public function evaluate(ProjectContext $context): bool
    {
        if (!$this->hasOtherStack($context)) {
            return false;
        }
        $manifests = [];
        foreach (['requirements.txt', 'pyproject.toml', 'Pipfile'] as $name) {
            $body = $context->contents($name);
            if (is_string($body) && $body !== '') {
                $manifests[] = $body;
            }
        }

        return str_contains(PythonRuntime::startCommand($context->projectDir, $manifests), PythonRuntime::PLACEHOLDER_DIR);
    }

    private function hasOtherStack(ProjectContext $context): bool
    {
        foreach (self::OTHER_STACKS as $file) {
            if ($context->hasFile($file)) {
                return true;
            }
        }

        return DotnetRuntime::hasProject($context->projectDir);
    }
}
