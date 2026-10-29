<?php

namespace App\Http\Requests\Wp;

use App\Rules\WpCliArgument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{login: string, email: string, password: string, role?: string|null, display_name?: string|null, first_name?: string|null, last_name?: string|null, path?: string|null} validated($key = null, $default = null)
 */
class WpUserCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', new WpCliArgument(WpCliArgument::LOGIN)],
            'email' => ['required', 'email', new WpCliArgument],
            'password' => 'required|string|min:8',
            'role' => 'sometimes|nullable|string',
            'display_name' => 'sometimes|nullable|string',
            'first_name' => 'sometimes|nullable|string',
            'last_name' => 'sometimes|nullable|string',
            'send_email' => 'sometimes|boolean',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
