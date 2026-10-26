<?php

namespace App\Http\Requests\Git;

use App\Http\Requests\Concerns\ChecksGitRefName;
use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Lib\Deploy\Source\GitRepoInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GitConnectRequest extends FormRequest
{
    use ChecksGitRefName;
    use ResolvesVaultFields;

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['token']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            'repo_url' => self::repoUrlRules(),
            'branch' => 'required_unless:repair,true|string',
            'token' => 'nullable|string',
            'auth_type' => 'nullable|in:pat',
            'repair' => 'nullable|boolean',
        ];
    }

    /**
     * An SSH remote is not a URL to Laravel; GitActions decides whether this project can use one.
     *
     * @return list<mixed>
     */
    private static function repoUrlRules(): array
    {
        return [
            'required_unless:repair,true',
            Rule::when(
                fn (Fluent $input): bool => ! GitRepoInput::isSsh((string) $input->get('repo_url', '')),
                ['url'],
                ['string', 'max:'.GitRepoInput::MAX_LENGTH],
            ),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkGitRefName($validator, 'branch');
    }
}
