<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Rules\SshPublicKey;
use Illuminate\Foundation\Http\FormRequest;

class SftpAccountUpdateRequest extends FormRequest
{
    use ResolvesVaultFields;

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['password']);
    }

    public function rules(): array
    {
        return [
            'auth_method' => 'string|required',
            'password' => 'string|nullable|min:8|max:255',
            'public_key' => ['string', 'nullable', 'max:4096', new SshPublicKey],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters long.',
            'password.max' => 'Password cannot be longer than 255 characters.',
            'public_key.max' => 'Public key is too large (maximum 4096 characters).',
        ];
    }
}
