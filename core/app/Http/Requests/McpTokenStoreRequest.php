<?php

namespace App\Http\Requests;

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
            'projects' => ['sometimes', 'array', 'max:500'],
            'projects.*' => ['string', 'exists:users,username'],
        ];
    }
}
