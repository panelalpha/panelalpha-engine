<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** A deploy key for the project, whose public half the repository is given. */
final class CreateDeployKey extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::PROJECT_DEPLOY_KEY;
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/git/deploy-key';
    }

    public function cli(): string
    {
        return 'git:deploy-key {username}';
    }
}
