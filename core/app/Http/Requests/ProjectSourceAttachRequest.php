<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Project\NewProjectRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Giving a project that already exists a git source: the source fields
 * POST /projects accepts, with the repository required rather than optional.
 */
class ProjectSourceAttachRequest extends FormRequest
{
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $gitRepo = $this->input('git_repo');
        if (is_string($gitRepo) && trim($gitRepo) !== '') {
            $this->merge(['git_repo' => GitRepoInput::normalise($gitRepo)]);
        }
    }

    public function rules(): array
    {
        return [
            'git_repo' => ['required', ...NewProjectRules::gitRepositoryRules()],
            'git_branch' => NewProjectRules::gitBranchRules(),
            'git_token' => NewProjectRules::gitTokenRules(),
            ...NewProjectRules::envVarRules(),
            'stages' => 'array|nullable',
            'recipe' => 'string|nullable|max:64',
        ];
    }

    protected function expectations(): array
    {
        return [
            'git_repo' => ['expected' => 'an HTTPS clone URL', 'examples' => ['https://github.com/owner/repo.git']],
            'git_branch' => ['expected' => 'the name of a branch or tag in the repository', 'examples' => ['main', 'v1.2.0']],
            'env_vars' => ['expected' => 'an object of KEY: "value" strings, at most 200', 'examples' => ['{"APP_ENV": "production"}']],
            'recipe' => ['expected' => 'a recipe name, at most 64 characters'],
        ];
    }
}
