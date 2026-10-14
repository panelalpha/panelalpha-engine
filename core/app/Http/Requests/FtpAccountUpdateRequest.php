<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReadsFtpQuota;
use App\Http\Requests\Concerns\ResolvesVaultFields;
use Illuminate\Foundation\Http\FormRequest;

class FtpAccountUpdateRequest extends FormRequest
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

    /**
     * An omitted quota/unlimited_quota pair leaves the quota as it is, which
     * getQuota() alone cannot tell apart from "set it to 0".
     */
    public function quotaProvided(): bool
    {
        return $this->has('quota') || $this->has('unlimited_quota');
    }

    public function rules(): array
    {
        return [
            'password' => 'nullable|string|min:8|max:255',
            'unlimited_quota' => 'boolean',
            'quota' => 'int|nullable|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Password must be at least 8 characters long.',
            'password.max' => 'Password cannot be longer than 255 characters.',
            'quota.min' => 'Quota must be a positive number.',
        ];
    }
}
