<?php

namespace App\Http\Requests\Git;

use App\System\Project\Git\Ref as GitRef;
use App\Lib\Deploy\Source\GitRepoInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GitConnectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            // An SSH remote is not a URL to Laravel; GitActions decides whether this project can use one.
            'repo_url' => [
                'required_unless:repair,true',
                Rule::when(
                    fn (Fluent $input): bool => !GitRepoInput::isSsh((string) $input->get('repo_url', '')),
                    ['url'],
                    ['string', 'max:' . GitRepoInput::MAX_LENGTH],
                ),
            ],
            'branch' => 'required_unless:repair,true|string',
            'token' => 'nullable|string',
            'auth_type' => 'nullable|in:pat',
            'repair' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $branch = $validator->getData()['branch'] ?? null;
            if (is_string($branch) && $branch !== '' && !GitRef::isValidName($branch)) {
                $validator->errors()->add('branch', 'Invalid git ref name.');
            }
        });
    }
}
