<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{value: string, path?: string|null} validated($key = null, $default = null)
 */
class WpOptionSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The value is untrimmed and may be empty (see AppServiceProvider): '' clears an option. */
    public function rules(): array
    {
        return [
            'value' => 'present|string',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
