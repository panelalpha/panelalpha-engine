<?php

namespace App\Http\Requests;

use App\Lib\HttpAcmeChallengeStore;
use Illuminate\Foundation\Http\FormRequest;

class HttpAcmeChallengeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'token' => 'string|required|regex:'.HttpAcmeChallengeStore::TOKEN_PATTERN.'|max:255',
            'content' => 'string|required|max:'.HttpAcmeChallengeStore::MAX_CONTENT_LENGTH,
        ];
    }
}
