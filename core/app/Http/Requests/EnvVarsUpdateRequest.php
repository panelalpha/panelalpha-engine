<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Rules\EnvVarKeys;
use Illuminate\Foundation\Http\FormRequest;

class EnvVarsUpdateRequest extends FormRequest
{
    use ReportsProblems;
    use ResolvesVaultFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['env_vars']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'env_vars' => ['present', 'array', 'max:200', new EnvVarKeys()],
            'env_vars.*' => ['string', 'nullable', 'max:8192'],
            'replace' => ['sometimes', 'boolean'],
            'apply' => ['sometimes', 'in:none,recreate'],
        ];
    }

    /**
     * @return array<string, array{expected: string, examples?: list<string>}>
     */
    protected function expectations(): array
    {
        return [
            'env_vars' => [
                'expected' => 'an object of KEY: "value" strings, at most 200; an empty object clears every key with replace',
                'examples' => ['{"APP_ENV": "production"}'],
            ],
            'env_vars.*' => ['expected' => 'a string of at most 8192 characters, or null to remove the key'],
            'replace' => ['expected' => 'a boolean: true, false, 1 or 0', 'examples' => ['true', 'false']],
            'apply' => ['expected' => 'one of: none, recreate', 'examples' => ['recreate', 'none']],
        ];
    }
}
