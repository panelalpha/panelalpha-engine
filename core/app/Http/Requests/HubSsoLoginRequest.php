<?php

namespace App\Http\Requests;

use App\Http\Controllers\HubSsoController;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** A login ticket posted from the Hub's page. A missing or odd ticket gets the same page as a refused one. */
class HubSsoLoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ticket' => ['required', 'string', 'max:4096']];
    }

    public function ticket(): string
    {
        return (string) $this->validated('ticket');
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(HubSsoController::refused());
    }
}
