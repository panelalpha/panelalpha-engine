<?php

namespace App\Http\Requests;

use App\Auth\ApiSurface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiTokenRoutesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'routes' => ['present', 'nullable', 'array', 'max:2000'],
            'routes.*' => ['string', 'distinct', Rule::in(ApiSurface::all())],
        ];
    }

    public function messages(): array
    {
        return ['routes.*.in' => ':input is not an operation of this API; write one as its method, a space and its path, as the OpenAPI document lists it.'];
    }

    /** @return list<string>|null the operations the token may call, null for every one */
    public function routes(): ?array
    {
        $routes = $this->validated('routes');

        return $routes === null ? null : array_values($routes);
    }
}
