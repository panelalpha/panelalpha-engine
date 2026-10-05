<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;

/**
 * A root `<name>.dockerfile` ({@see DockerfileFinder::findNamed()}), for a
 * repository nothing else deploys. Laravel tutorials keep `php.dockerfile`
 * beside the app, so its manifest sits below every other one and a runtime
 * Railpack recognises vetoes it, as for a nested compose file.
 */
final class DockerfileNamedProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'dockerfile-named';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        if (RuntimeRegistry::anyRecognises($context)) {
            return false;
        }
        $dockerfile = DockerfileFinder::findNamed($context->projectDir);
        if ($dockerfile === null) {
            return false;
        }

        $port = DockerfileFinder::exposedPort($context->path($dockerfile));

        return array_filter(
            ['dockerfile' => $dockerfile, 'port_hint' => $port],
            static fn ($value): bool => $value !== null
        );
    }
}
