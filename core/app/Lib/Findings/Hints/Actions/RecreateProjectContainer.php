<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** A new container for a project sysbox-fs fails to serve, then a deploy; an operator's, since it runs on the host. */
final class RecreateProjectContainer extends Hint
{
    public function resolves(): array
    {
        return ['sysbox-fs-connection-lost'];
    }

    public function cli(): string
    {
        return 'project:dind:recover --project={username} --recreate';
    }
}
