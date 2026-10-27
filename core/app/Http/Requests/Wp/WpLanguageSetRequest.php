<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{language: string, path?: string|null} validated($key = null, $default = null)
 */
class WpLanguageSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'language' => ['required', 'string', 'regex:'.WpCoreDownloadRequest::LOCALE_PATTERN],
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
