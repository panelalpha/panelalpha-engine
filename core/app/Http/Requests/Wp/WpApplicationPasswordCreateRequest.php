<?php

namespace App\Http\Requests\Wp;

use App\Rules\WpCliArgument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{name: string, app_id?: string|null, path?: string|null} validated($key = null, $default = null)
 */
class WpApplicationPasswordCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', new WpCliArgument],
            'app_id' => 'sometimes|nullable|string',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
