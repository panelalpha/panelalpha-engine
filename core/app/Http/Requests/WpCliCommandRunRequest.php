<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{
 *   args: list<string>,
 *   path?: string|null,
 *   service?: string|null
 * } validated($key = null, $default = null)
 */
class WpCliCommandRunRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'args' => 'required|array',
            'args.*' => 'string',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
        ];
    }
}
