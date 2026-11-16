<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** The repository connected to the project once its deploy key can read it. */
final class ConnectRepository extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::PROJECT_DEPLOY_KEY;
    }

    public function rest(): string
    {
        return 'POST /projects/{username}/git/connect';
    }

    public function params(): array
    {
        return ['repo_url' => null];
    }

    public function cli(): string
    {
        return 'git:connect {username} --repo-url={repo_url}';
    }
}
