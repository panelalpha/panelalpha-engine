<?php

namespace App\Http\Requests;

use App\Rules\TokenLifetime;
use Illuminate\Foundation\Http\FormRequest;

class McpTokenStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'expires_in' => ['sometimes', 'nullable', 'string', 'max:16', new TokenLifetime],
            'projects' => ['sometimes', 'array', 'max:500'],
            'projects.*' => ['string', 'exists:users,username'],
        ];
    }
}
