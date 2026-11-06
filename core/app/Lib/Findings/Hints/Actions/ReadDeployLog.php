<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Exceptions\DeployBusyException;
use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;
use App\System\Project\Dind\AppHealth;

/** The deploy's own log, every line of it. */
final class ReadDeployLog extends Hint
{
    public function resolves(): array
    {
        return [AppHealth::CODE_NOT_ANSWERING, DeployBusyException::CODE, ...FindingCodes::failures()];
    }

    public function rest(): string
    {
        return 'GET /projects/{username}/deploy-log';
    }

    public function cli(): string
    {
        return 'project:deploy:log {username}';
    }
}
