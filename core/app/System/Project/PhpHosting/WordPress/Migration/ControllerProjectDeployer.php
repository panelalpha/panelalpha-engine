<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Http\Requests\UserStoreRequest;
use App\Lib\Project\NewProjectInput;
use App\Lib\Project\ProjectCreator;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticates as root and provisions + deploys in-process through
 * ProjectCreator, the same path POST /users takes.
 */
final class ControllerProjectDeployer implements ProjectDeployer
{
    public function deploy(UserStoreRequest $request): User
    {
        Auth::setUser(Admin::rootAccount());

        return app(ProjectCreator::class)->create(new NewProjectInput(
            $request->validated(),
            $request->nameField(),
            $request->input('git_token'),
            $request->input('env_vars'),
            $request->input(DeployPlanInput::FIELD),
            $request->input(RecipeChoiceInput::FIELD),
        ));
    }
}
