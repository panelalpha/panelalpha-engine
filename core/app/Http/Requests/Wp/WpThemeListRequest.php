<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{path?: string|null, service?: string|null, skip_plugins?: bool, fields?: string, status?: string} validated($key = null, $default = null)
 */
class WpThemeListRequest extends FormRequest
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
            'fields' => ['sometimes', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'status' => ['sometimes', 'string', 'regex:/^[a-z-]+(,[a-z-]+)*$/'],
        ];
    }
}
