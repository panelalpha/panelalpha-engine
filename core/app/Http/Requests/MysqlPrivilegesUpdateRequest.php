<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class MysqlPrivilegesUpdateRequest extends FormRequest
{
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
        $availablePrivileges = [
            'ALL PRIVILEGES',
            'ALTER',
            'ALTER ROUTINE',
            'CREATE',
            'CREATE ROUTINE',
            'CREATE TEMPORARY TABLES',
            'CREATE VIEW',
            'DELETE',
            'DROP',
            'EVENT',
            'EXECUTE',
            'INDEX',
            'INSERT',
            'LOCK TABLES',
            'REFERENCES',
            'SELECT',
            'SHOW VIEW',
            'TRIGGER',
            'UPDATE',
        ];

        /** @var mixed */
        $privileges = $this->input('privileges');
        if (! is_string($privileges)) {
            throw ValidationException::withMessages([
                'privileges' => 'Invalid value.',
            ]);
        }
        foreach (explode(',', $privileges) as $priv) {
            if (! in_array($priv, $availablePrivileges)) {
                throw ValidationException::withMessages([
                    'privileges' => 'Invalid value.',
                ]);
            }
        }

        return [
            'privileges' => 'string|required',
        ];
    }
}
