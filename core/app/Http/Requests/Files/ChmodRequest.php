<?php

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;

class ChmodRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'path' => 'string|required',
            'mode' => ['required', 'string', 'regex:/\A[0-7]{3,4}\z/'],
        ];
    }
}
