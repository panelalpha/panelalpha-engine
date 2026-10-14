<?php

namespace App\Http\Requests\Git;

use App\Http\Requests\Concerns\ResolvesVaultFields;
use Illuminate\Foundation\Http\FormRequest;

class GitUpdateCredentialsRequest extends FormRequest
{
    use ResolvesVaultFields;

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['token']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => 'nullable|string',
            'token' => 'nullable|string',
        ];
    }
}
