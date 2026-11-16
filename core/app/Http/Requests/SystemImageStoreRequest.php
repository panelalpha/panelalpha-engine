<?php

namespace App\Http\Requests;

use App\Lib\Deploy\CacheManager\OperatorImages;
use Illuminate\Foundation\Http\FormRequest;

/** The fields a new operator image is made from; {@see OperatorImages} checks them, as it does for pae. */
class SystemImageStoreRequest extends FormRequest
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
            'ref' => ['nullable'],
            'source' => ['nullable'],
            'dockerfile' => ['nullable'],
            'build_args' => ['nullable'],
            'why' => ['nullable'],
        ];
    }
}
