<?php

namespace App\Http\Requests\Git;

use Illuminate\Foundation\Http\FormRequest;

class GitStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('fetch')) {
            return;
        }

        $fetch = $this->input('fetch');
        if (! is_string($fetch)) {
            return;
        }

        $normalized = filter_var($fetch, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized !== null) {
            $this->merge(['fetch' => $normalized]);
        }
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            'fetch' => 'nullable|boolean',
        ];
    }
}
