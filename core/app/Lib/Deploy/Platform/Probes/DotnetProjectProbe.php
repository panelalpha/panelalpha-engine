<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;

/**
 * A .NET solution or project, named after the project and often under src/, so no `detect` rule can match it.
 * One with nothing that serves HTTP is left to later strategies.
 */
final class DotnetProjectProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'dotnet-project';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        return DotnetRuntime::hasProject($context->projectDir)
            && ! DotnetRuntime::servesNothing($context->projectDir);
    }
}
