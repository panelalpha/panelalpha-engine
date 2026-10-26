<?php

namespace App\Http\Requests\Wp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method array{file: string, tables?: list<string>, path?: string|null} validated($key = null, $default = null)
 */
class WpDatabaseExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|string',
            'tables' => 'sometimes|array',
            'tables.*' => ['string', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'path' => 'sometimes|nullable|string',
            'service' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'skip_plugins' => 'sometimes|boolean',
        ];
    }
}
