<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\DotnetRuntime;

/**
 * A .NET solution or project.
 *
 * A probe rather than a `detect` rule because the manifest grammar cannot
 * express it: .NET names its manifests after the project -- Jellyfin.sln,
 * Jellyfin.Server.csproj -- so there is no fixed basename to match on, and
 * `glob` matches a stem rather than a suffix. The projects also routinely sit
 * one level down under src/, with only a solution at the top or nothing at
 * all.
 */
final class DotnetProjectProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'dotnet-project';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        // A repository whose only programs are console executables is not
        // served by publishing one; leave it to the strategies after this.
        return DotnetRuntime::hasProject($context->projectDir)
            && !DotnetRuntime::onlyConsoleExecutables($context->projectDir);
    }
}
