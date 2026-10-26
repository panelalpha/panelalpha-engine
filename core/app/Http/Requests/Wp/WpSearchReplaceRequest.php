<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{search: string, replacement: string, args?: list<string>, path?: string|null} validated($key = null, $default = null)
 */
class WpSearchReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'required|string',
            // Untrimmed and may be empty (see AppServiceProvider): '' removes the search string.
            'replacement' => 'present|string',
            'args' => 'sometimes|array',
            'args.*' => 'string',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
