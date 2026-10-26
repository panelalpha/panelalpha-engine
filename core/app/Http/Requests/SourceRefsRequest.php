<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Rules\GitAccessToken;
use App\Rules\GitRepositoryUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The remote whose branches and tags are listed. Repository and token run the
 * same rules the create and inspect endpoints do, pointed at this endpoint's
 * field names.
 */
class SourceRefsRequest extends FormRequest
{
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $repository = $this->input('repository');
        if (is_string($repository)) {
            $this->merge(['repository' => GitRepoInput::normalise(trim($repository))]);
        }
    }

    public function rules(): array
    {
        return [
            'repository' => ['required', 'string', 'max:'.GitRepoInput::MAX_LENGTH, new GitRepositoryUrl],
            'git_token' => [
                'nullable',
                'string',
                'max:'.GitTokenInput::MAX_LENGTH,
                new GitAccessToken('repository'),
            ],
            'limit' => 'nullable|integer|min:1|max:1000',
        ];
    }
}
