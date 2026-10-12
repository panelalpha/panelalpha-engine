<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PhpErrorLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => 'sometimes|integer|min:1|max:5000',
            'since' => 'sometimes|date',
            'domain' => 'sometimes|string',
        ];
    }
}
