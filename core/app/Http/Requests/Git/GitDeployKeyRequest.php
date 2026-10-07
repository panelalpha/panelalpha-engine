<?php

namespace App\Http\Requests\Git;

use Illuminate\Foundation\Http\FormRequest;

class GitDeployKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'host' => 'nullable|string|max:260',
        ];
    }
}
