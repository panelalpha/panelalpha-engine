<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Http\Requests\UserStoreRequest;
use App\Models\User;

/**
 * Runs provision + deploy for a migration create_project step in-process.
 * Production uses ControllerProjectDeployer; tests inject a stub.
 */
interface ProjectDeployer
{
    public function deploy(UserStoreRequest $request): User;
}
