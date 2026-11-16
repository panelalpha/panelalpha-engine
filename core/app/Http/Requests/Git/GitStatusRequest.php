<?php

namespace App\Http\Requests\Git;

use Illuminate\Foundation\Http\FormRequest;

class GitStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            'fetch' => 'nullable|boolean',
        ];
    }
}
