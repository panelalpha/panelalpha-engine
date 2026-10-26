<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

class WpConfigPathRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
            'type' => 'sometimes|string|in:constant,variable,all',
            'format' => 'sometimes|string|in:json',
        ];
    }
}
