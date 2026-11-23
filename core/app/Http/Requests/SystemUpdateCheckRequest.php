<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemUpdateCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['refresh' => ['sometimes', 'boolean']];
    }

    public function refresh(): bool
    {
        return $this->boolean('refresh');
    }
}
