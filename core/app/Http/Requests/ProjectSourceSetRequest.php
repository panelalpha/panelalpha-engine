<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BuildsNewProjectInput;
use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Project\Create\CreateBody;
use App\Lib\Project\Create\CreateFields;
use App\Lib\Project\Create\CreateShape;
use App\Lib\Project\Create\SourceSetInput;
use App\Lib\Project\NewProjectRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /projects/{username}/source: a git or archive source for a project that
 * exists, in the shape POST /projects takes it, with `build` beside it.
 */
class ProjectSourceSetRequest extends FormRequest
{
    use BuildsNewProjectInput;
    use ReportsProblems {
        failedValidation as reportProblems;
    }

    private CreateBody $body;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->body = CreateBody::forAttach($this->all());
        $this->body->assertWellFormed();

        $flat = $this->body->flat();
        if (is_string($flat['git_repo'] ?? null)) {
            $flat['git_repo'] = GitRepoInput::normalise($flat['git_repo']);
        }
        $this->replace($flat);
    }

    public function rules(): array
    {
        return $this->body->sourceType() === CreateShape::SOURCE_GIT
            ? NewProjectRules::attachRules($this->body->source()->deployKey() !== null)
            : NewProjectRules::buildRules();
    }

    public function attributes(): array
    {
        return CreateShape::LEGACY_FIELDS;
    }

    protected function failedValidation(Validator $validator): void
    {
        CreateFields::answering(fn () => $this->reportProblems($validator));
    }

    protected function expectations(): array
    {
        return NewProjectRules::expectations();
    }

    public function sourceInput(): SourceSetInput
    {
        return new SourceSetInput(
            $this->body->source(),
            $this->newProjectInput('username', $this->body->source()->deployKey()),
            $this->exists('env_vars'),
        );
    }
}
