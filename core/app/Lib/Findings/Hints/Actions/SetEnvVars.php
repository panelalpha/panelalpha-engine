<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;
use App\System\Project\Deployment\DeployMechanics;

/** Environment variables set or changed, for the next deploy. */
final class SetEnvVars extends Hint
{
    public function resolves(): array
    {
        return [DeployMechanics::CUSTOM_ENV_SUSPECTED, 'env-validation-failed', 'build-arg-unset'];
    }

    public function rest(): string
    {
        return 'PUT /projects/{username}/env-vars';
    }

    public function params(): array
    {
        return ['env_vars' => null];
    }
}
