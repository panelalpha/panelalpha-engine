<?php

namespace App\Http\Requests\Wp;

use App\Rules\WpCliArgument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{slug: string, force?: bool, path?: string|null} validated($key = null, $default = null)
 */
class WpPluginInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', new WpCliArgument(WpCliArgument::SLUG)],
            'force' => 'sometimes|boolean',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
