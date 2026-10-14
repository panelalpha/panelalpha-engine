<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsFtpQuota;
use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Rules\DomainName;
use Illuminate\Foundation\Http\FormRequest;

class FtpAccountStoreRequest extends FormRequest
{
    use ReadsFtpQuota;
    use ResolvesVaultFields;

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['password']);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user' => 'string|required|alpha_num:ascii|max:32',
            'domain' => 'string|required|regex:' . DomainName::REGEX,
            'password' => 'required|string|min:8|max:255',
            'directory' => 'string|nullable|max:4096',
            'unlimited_quota' => 'boolean',
            'quota' => 'int|nullable|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'user.alpha_num' => 'Username can only contain letters and numbers.',
            'user.max' => 'Username cannot be longer than 32 characters.',
            'domain.regex' => 'Invalid domain format.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.max' => 'Password cannot be longer than 255 characters.',
            'directory.max' => 'Directory path is too long (maximum 4096 characters).',
            'quota.min' => 'Quota must be a positive number.',
        ];
    }
}
