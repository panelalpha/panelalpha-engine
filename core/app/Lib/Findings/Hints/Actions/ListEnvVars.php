<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;
use App\System\Project\Deployment\DeployMechanics;

/** The environment variables the project deploys with. */
final class ListEnvVars extends Hint
{
    public function resolves(): array
    {
        return [DeployMechanics::CUSTOM_ENV_SUSPECTED, 'env-validation-failed', 'build-arg-unset'];
    }

    public function rest(): string
    {
        return 'GET /projects/{username}/env-vars';
    }
}
