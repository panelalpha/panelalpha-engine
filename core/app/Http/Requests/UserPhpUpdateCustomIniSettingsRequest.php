<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserPhpUpdateCustomIniSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'php_version' => 'string|required',
            'settings' => 'array|present',
            'settings.*' => 'string',
        ];
    }
}
