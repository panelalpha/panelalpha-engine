<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** The host's own Docker Hub login, which lifts the anonymous pull limit; an operator's. */
final class LogInToDockerHub extends Hint
{
    public function resolves(): array
    {
        return ['registry-rate-limited'];
    }

    public function params(): array
    {
        return ['hub_user' => null];
    }

    public function cli(): string
    {
        return 'docker-hub:login --username={hub_user} --token-stdin';
    }
}
