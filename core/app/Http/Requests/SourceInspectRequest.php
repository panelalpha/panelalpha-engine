<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Rules\GitAccessToken;
use App\Rules\InspectableSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inspect is asked the same question about a repository that create is, so it
 * runs the same rules, pointed at this endpoint's field names.
 */
class SourceInspectRequest extends FormRequest
{
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $source = $this->input('source');
        if (!is_string($source)) {
            return;
        }

        $source = trim($source);
        $this->merge(['source' => $this->isGit($source) ? GitRepoInput::normalise($source) : $source]);
    }

    /**
     * A declared `type` outranks classification: `example.com/not/a/repo`
     * reads as a schemeless git URL, and a caller who said `path` has already
     * said it is not one.
     */
    private function isGit(string $source): bool
    {
        $declared = InspectableSource::declaredType($this->input('type'));

        return $declared === null
            ? SourceResolver::classify($source) === SourceResolver::TYPE_GIT
            : $declared === SourceResolver::TYPE_GIT;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:' . GitRepoInput::MAX_LENGTH, new InspectableSource()],
            'type' => ['nullable', 'string', Rule::in(SourceResolver::TYPES)],
            'branch' => 'nullable|string|max:255',
            'subdirectory' => 'nullable|string|max:512',
            // Shape only; the grammar is checked by DeployPlanInput, which is
            // the same check the deploy endpoints run on the same field.
            'stages' => 'nullable|array',
            'recipe' => 'nullable|string|max:64',
            'git_token' => [
                'nullable',
                'string',
                'max:' . GitTokenInput::MAX_LENGTH,
                new GitAccessToken('source'),
            ],
        ];
    }
}
