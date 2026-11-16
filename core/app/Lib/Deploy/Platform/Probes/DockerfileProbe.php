<?php

namespace App\Lib\Deploy\Platform\Probes;

use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * A Dockerfile the repository intends to be built, with its path and EXPOSEd port; a probe since
 * the name varies, its casing must be kept, and a uid/gid-mapping one is a workstation file.
 */
final class DockerfileProbe implements PlatformProbe
{
    public function id(): string
    {
        return 'dockerfile';
    }

    public function evaluate(ProjectContext $context): bool|array
    {
        $dockerfile = DockerfileFinder::find($context->projectDir, $context->files);
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
