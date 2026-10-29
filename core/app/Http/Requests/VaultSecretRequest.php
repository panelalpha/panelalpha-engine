<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The vault paste form's one field. Handler::$dontFlash keeps it out of the session. */
class VaultSecretRequest extends FormRequest
{
    public const MAX_LENGTH = 8192;

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
            'secret' => ['required', 'string', 'max:'.self::MAX_LENGTH, 'not_in:'],
        ];
    }
}
