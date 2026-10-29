<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{locale?: string|null, version?: string|null, path?: string|null} validated($key = null, $default = null)
 */
class WpCoreDownloadRequest extends FormRequest
{
    public const LOCALE_PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/D';

    public const VERSION_PATTERN = '/^(latest|\d+\.\d+(\.\d+)?)$/D';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'nullable', 'string', 'regex:'.self::LOCALE_PATTERN],
            'version' => ['sometimes', 'nullable', 'string', 'regex:'.self::VERSION_PATTERN],
            'path' => 'sometimes|nullable|string',
        ];
    }
}
