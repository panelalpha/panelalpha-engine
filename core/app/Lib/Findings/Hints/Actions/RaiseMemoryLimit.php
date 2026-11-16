<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** More memory, for a build or an application that ran out of it. */
final class RaiseMemoryLimit extends Hint
{
    public function resolves(): array
    {
        return ['out-of-memory', 'java-heap-space', 'node-heap-space'];
    }

    public function rest(): string
    {
        return 'PUT /projects/{username}';
    }

    public function params(): array
    {
        return ['memory_limit' => null];
    }

    public function cli(): string
    {
        return 'project:limit:set --project={username} --memory-limit={memory_limit}';
    }
}
