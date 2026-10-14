<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMysqlName;
use Illuminate\Foundation\Http\FormRequest;

class MysqlDatabaseRenameRequest extends FormRequest
{
    use ValidatesMysqlName;

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
            'name' => $this->mysqlNameRule(64),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages()
    {
        return $this->mysqlNameMessages('Database name', 64);
    }
}
