<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{email?: string|null, role?: string|null, display_name?: string|null, first_name?: string|null, last_name?: string|null, path?: string|null} validated($key = null, $default = null)
 */
class WpUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'sometimes|nullable|email',
            'role' => 'sometimes|nullable|string',
            'display_name' => 'sometimes|nullable|string',
            'first_name' => 'sometimes|nullable|string',
            'last_name' => 'sometimes|nullable|string',
            'password' => 'sometimes|nullable|string|min:8',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
