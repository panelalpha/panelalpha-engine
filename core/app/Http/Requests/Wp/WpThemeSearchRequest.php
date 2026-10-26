<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{term: string, path?: string|null, fields?: string, per_page?: int|string} validated($key = null, $default = null)
 */
class WpThemeSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'term' => 'required|string',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
            'fields' => ['sometimes', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
