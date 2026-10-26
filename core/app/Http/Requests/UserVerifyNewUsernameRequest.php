<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserVerifyNewUsernameRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules()
    {
        /** @var string $rule */
        $rule = (new UserStoreRequest)->rules()['username'];

        return [
            'username' => $rule,
        ];
    }
}
