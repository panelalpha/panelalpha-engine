<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppUserDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reassign' => ['nullable', 'string', 'max:64'],
        ];
    }
}
