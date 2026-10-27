<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\User;

/**
 * Provisions + deploys in-process through ProjectCreator, the same path
 * POST /users and `project:create` take. The queue worker's request and
 * authenticated user are left as they are.
 */
final class ControllerProjectDeployer implements ProjectDeployer
{
    public function deploy(NewProjectInput $input): User
    {
        return app(ProjectCreator::class)->create($input);
    }
}
