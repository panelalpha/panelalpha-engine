<?php

namespace App\Http\Requests\Git;

use App\Http\Requests\Concerns\ChecksGitRefName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GitRevertRequest extends FormRequest
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
            'ref' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkGitRefName($validator, 'ref');
    }
}
