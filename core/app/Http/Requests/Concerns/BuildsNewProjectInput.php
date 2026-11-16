<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\DeployPlanInput;
use App\Http\Requests\RecipeChoiceInput;
use App\Lib\Project\NewProjectInput;

/**
 * A create or source attach as {@see NewProjectInput} takes it: the validated
 * fields, plus the raw ones the create reads itself.
 */
trait BuildsNewProjectInput
{
    /**
     * @param  string  $nameField  what the caller called the project name
     * @param  ?string  $deployKey  the `vault:` reference of the SSH key an SSH remote clones with
     */
    public function newProjectInput(string $nameField, ?string $deployKey = null): NewProjectInput
    {
        return new NewProjectInput(
            $this->validated(),
            $nameField,
            $this->input('git_token'),
            $this->input('env_vars'),
            $this->input(DeployPlanInput::FIELD),
            $this->input(RecipeChoiceInput::FIELD),
            $deployKey,
        );
    }
}
