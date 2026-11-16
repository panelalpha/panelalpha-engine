<?php

namespace App\Http\Requests;

use App\Auth\ApiSurface;
use App\Rules\TokenLifetime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_in' => ['sometimes', 'nullable', 'string', 'max:16', new TokenLifetime],
            'routes' => ['sometimes', 'nullable', 'array', 'max:2000'],
            'routes.*' => ['string', 'distinct', Rule::in(ApiSurface::all())],
            'projects' => ['sometimes', 'nullable', 'array', 'max:500'],
            'projects.*' => ['string', 'exists:users,username'],
        ];
    }

    public function messages(): array
    {
        return ['routes.*.in' => ':input is not an operation of this API; write one as its method, a space and its path, as the OpenAPI document lists it.'];
    }
}
