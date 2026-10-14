<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesVaultFields;
use Illuminate\Foundation\Http\FormRequest;

class ProjectPasswordSetRequest extends FormRequest
{
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
            'password' => 'required|string|min:1|max:255',
        ];
    }
}
