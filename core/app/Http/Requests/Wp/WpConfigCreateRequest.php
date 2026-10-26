<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{dbname: string, dbuser: string, dbpass?: string, dbhost?: string, dbprefix?: string, dbcharset?: string, dbcollate?: string, locale?: string, force?: bool, skip_check?: bool, path?: string|null, service?: string|null, skip_plugins?: bool} validated($key = null, $default = null)
 */
class WpConfigCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dbname' => 'required|string',
            'dbuser' => 'required|string',
            'dbpass' => 'sometimes|string',
            'dbhost' => 'sometimes|string',
            'dbprefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_]*$/'],
            'dbcharset' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_]*$/'],
            'dbcollate' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_]*$/'],
            'locale' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_]*$/'],
            'force' => 'sometimes|boolean',
            'skip_check' => 'sometimes|boolean',
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
