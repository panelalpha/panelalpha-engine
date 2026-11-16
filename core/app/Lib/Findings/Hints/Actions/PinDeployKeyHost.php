<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** A git host's key pinned on the project's deploy key, so the clone can verify the server. */
final class PinDeployKeyHost extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::PROJECT_DEPLOY_KEY_HOST;
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/git/deploy-key';
    }

    public function params(): array
    {
        return ['host' => '{host}'];
    }

    public function cli(): string
    {
        return 'git:deploy-key {username} --host={host}';
    }
}
