<?php

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;

class MoveContentsRequest extends FormRequest
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
            'source_path' => 'string|required',
            'dest_path' => 'string|required',
            'override' => 'boolean',
        ];
    }
}
