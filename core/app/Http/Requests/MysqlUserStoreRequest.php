<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Http\Requests\Concerns\ValidatesMysqlName;
use Illuminate\Foundation\Http\FormRequest;

class MysqlUserStoreRequest extends FormRequest
{
    use ResolvesVaultFields;
    use ValidatesMysqlName;

    protected function prepareForValidation(): void
    {
        $this->resolveVaultFields(['password']);
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name' => $this->mysqlNameRule(32),
            'password' => [
                'string',
                'required',
                'min:8',
                'max:255',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            ...$this->mysqlNameMessages('MySQL user name', 32),
            'password.min' => 'Password must be at least 8 characters long.',
        ];
    }
}
