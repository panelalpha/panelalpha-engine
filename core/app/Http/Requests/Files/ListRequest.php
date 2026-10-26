<?php

namespace App\Http\Requests\Files;

use App\System\Project\FileManager;
use Illuminate\Foundation\Http\FormRequest;

class ListRequest extends FormRequest
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
            'path' => 'string|required',
            'show_hidden' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:'.FileManager::LIST_MAX_LIMIT,
        ];
    }
}
