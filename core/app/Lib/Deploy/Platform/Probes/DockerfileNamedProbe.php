<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A root `<name>.dockerfile` ({@see DockerfileFinder::findNamed()}) for a repository nothing else
 * deploys: every language recipe ranks above it, so `php.dockerfile` beside a PHP app is left alone.
 */
final class DockerfileNamedProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'dockerfile-named';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
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
