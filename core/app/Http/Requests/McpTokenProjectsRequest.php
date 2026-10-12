<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class McpTokenProjectsRequest extends FormRequest
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
            'projects' => ['required', 'array', 'max:500'],
            'projects.*' => ['string', 'exists:users,username'],
        ];
    }
}
