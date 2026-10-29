<?php

namespace App\Http\Requests;

use App\Models\Tunnel;
use Illuminate\Foundation\Http\FormRequest;

class TunnelStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['nullable', 'string', 'max:253', 'required_if:provider,cloudflare'],
            'provider' => ['sometimes', 'string', 'in:'.implode(',', Tunnel::PROVIDERS)],
            'purpose' => ['sometimes', 'string', 'in:'.implode(',', Tunnel::PURPOSES)],
            'ttl_minutes' => ['sometimes', 'integer', 'min:5', 'max:43200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hostname.required_if' => 'A hostname is required for the cloudflare provider.',
            'purpose.in' => 'purpose must be one of: '.implode(', ', Tunnel::PURPOSES).'.',
            'ttl_minutes.min' => 'ttl_minutes must be at least 5 minutes.',
            'ttl_minutes.max' => 'ttl_minutes must be at most 43200 minutes (30 days).',
        ];
    }
}
