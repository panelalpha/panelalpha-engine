<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The value arrives untrimmed and may be empty (see AppServiceProvider).
 *
 * @method array{value: string, raw?: bool, type?: string, path?: string|null} validated($key = null, $default = null)
 */
class WpConfigSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => 'present|string',
            'raw' => 'sometimes|boolean',
            'type' => 'sometimes|string|in:constant,variable,all',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
