<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * At least one of core, plugins and themes; the ones left out are not changed.
 *
 * @method array{core?: string, plugins?: string, themes?: string, path?: string|null} validated($key = null, $default = null)
 */
class WpAutoUpdateSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'core' => ['required_without_all:plugins,themes', 'string', Rule::in(['off', 'on', 'minor'])],
            'plugins' => ['required_without_all:core,themes', 'string', Rule::in(['off', 'on'])],
            'themes' => ['required_without_all:core,plugins', 'string', Rule::in(['off', 'on'])],
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
