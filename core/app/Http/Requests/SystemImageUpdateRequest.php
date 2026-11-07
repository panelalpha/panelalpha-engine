<?php

namespace App\Http\Requests;

use App\Lib\Deploy\CacheManager\OperatorImages;
use Illuminate\Foundation\Http\FormRequest;

/** The fields an operator image change may carry; {@see OperatorImages} checks them, as it does for pae. */
class SystemImageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dockerfile' => ['nullable'],
            'build_args' => ['nullable'],
            'why' => ['nullable'],
        ];
    }
}
