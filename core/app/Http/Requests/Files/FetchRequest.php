<?php

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;

class FetchRequest extends FormRequest
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
            'url' => ['required', 'string', 'regex:/^https?:/i'],
            'path' => 'string|required',
            'filename' => 'nullable|string',
        ];
    }
}
