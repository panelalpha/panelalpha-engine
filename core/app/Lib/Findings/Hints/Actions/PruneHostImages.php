<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;
use App\System\Project\Dind\HostDiskLowException;

/** Host disk freed of the base images no project has used lately; an operator's, since the disk is the host's. */
final class PruneHostImages extends Hint
{
    public function resolves(): array
    {
        return ['disk-limit-reached', 'disk-full', HostDiskLowException::CODE];
    }

    public function cli(): string
    {
        return 'system:image:prune';
    }
}
