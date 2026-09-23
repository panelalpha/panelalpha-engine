<?php

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;

class ZipRequest extends FormRequest
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
        return [
            'zip_path' => 'string|required',
            'path' => 'string|required',
            'compression_level' => 'nullable|integer|min:0|max:9',
            'from_date' => 'nullable|string',
            'ignore_empty' => 'boolean',
        ];
    }
}
