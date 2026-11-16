<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Inspect\Report\PortsReport;
use App\Lib\Findings\Hints\Hint;
use App\System\Project\Dind\AppHealth;

/** What the application printed; `app` is the service of every compose file the engine writes. */
final class ReadAppLogs extends Hint
{
    public function resolves(): array
    {
        return [
            AppHealth::CODE_NOT_ANSWERING,
            AppHealth::CODE_SITE_GATEWAY_ERROR,
            AppHealth::CODE_SITE_UNREACHABLE,
            AppHealth::CODE_CHECK_FAILED,
            PortsReport::PORT_NOT_DETECTED,
            DeployFailureExplainer::APP_DID_NOT_START,
            'container-start-failed',
            'container-entrypoint-missing',
            'container-entrypoint-not-executable',
            'new-version-empty-page',
            'missing-package-at-runtime',
        ];
    }

    public function rest(): string
    {
        return 'GET /projects/{username}/containers/{service}/logs';
    }

    public function params(): array
    {
        return ['service' => 'app'];
    }
}
