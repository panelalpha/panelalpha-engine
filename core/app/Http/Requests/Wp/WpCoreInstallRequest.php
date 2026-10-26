<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{url: string, title: string, admin_user: string, admin_email: string, admin_password: string, path?: string|null} validated($key = null, $default = null)
 */
class WpCoreInstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => 'required|string',
            'title' => 'required|string',
            'admin_user' => 'required|string',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:8',
            'skip_email' => 'sometimes|boolean',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
