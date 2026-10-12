<?php

namespace App\Http\Requests\Git;

use App\Http\Requests\Concerns\ChecksGitRefName;
use App\Lib\Git\GitActions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GitCommitsRequest extends FormRequest
{
    use ChecksGitRefName;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `limit` is bounded: core runs with memory_limit -1, so a huge `git log -n`
     * holds a PHP worker while it reads the whole history.
     */
    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            'branch' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:' . GitActions::MAX_COMMITS,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkGitRefName($validator, 'branch');
    }
}
