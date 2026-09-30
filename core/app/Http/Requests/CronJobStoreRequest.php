<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesCronJobFields;
use Illuminate\Foundation\Http\FormRequest;

class CronJobStoreRequest extends FormRequest
{
    use ValidatesCronJobFields;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }
}
