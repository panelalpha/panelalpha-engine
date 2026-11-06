<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** More disk, for a deploy stopped at the project's limit. */
final class RaiseDiskLimit extends Hint
{
    public function resolves(): array
    {
        return ['disk-limit-reached'];
    }

    public function rest(): string
    {
        return 'PUT /projects/{username}';
    }

    public function params(): array
    {
        return ['disk_space_limit' => null];
    }

    public function cli(): string
    {
        return 'project:limit:set --project={username} --disk-space-limit={disk_space_limit}';
    }
}
