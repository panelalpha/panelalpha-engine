<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;

/**
 * Python declared by a `pyproject.toml` `[project]` or `[tool.poetry]` table; the filename alone
 * is not evidence, since any tool borrows it.
 */
final class PyprojectProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'pyproject';
    }

    public function evaluate(ProjectContext $context): bool
    {
        return PythonRuntime::declaresProject($context->contents('pyproject.toml'));
    }
}
