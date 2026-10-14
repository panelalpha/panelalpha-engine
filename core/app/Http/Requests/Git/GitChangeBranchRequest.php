<?php

namespace App\Http\Requests\Git;

use App\Http\Requests\Concerns\ChecksGitRefName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GitChangeBranchRequest extends FormRequest
{
    use ChecksGitRefName;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            'branch' => 'required|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkGitRefName($validator, 'branch');
    }
}
