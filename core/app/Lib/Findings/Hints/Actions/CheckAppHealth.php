<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Findings\Hints\Hint;
use App\System\Project\Dind\AppHealth;

/** The application probed as a deploy probes it. */
final class CheckAppHealth extends Hint
{
    public function resolves(): array
    {
        return [
            AppHealth::CODE_NOT_ANSWERING,
            AppHealth::CODE_SITE_GATEWAY_ERROR,
            AppHealth::CODE_SITE_UNREACHABLE,
            AppHealth::CODE_CHECK_FAILED,
            DeployFailureExplainer::APP_DID_NOT_START,
        ];
    }

    public function rest(): string
    {
        return 'GET /projects/{username}/app/health';
    }

    public function cli(): string
    {
        return 'project:deploy:check {username}';
    }
}
