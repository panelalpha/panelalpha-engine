<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{path?: string|null, service?: string|null, skip_plugins?: bool, role?: string, fields?: string} validated($key = null, $default = null)
 */
class WpUserListRequest extends FormRequest
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
            'role' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
            'fields' => ['sometimes', 'string', 'regex:/^[A-Za-z_]+(,[A-Za-z_]+)*$/'],
        ];
    }
}
