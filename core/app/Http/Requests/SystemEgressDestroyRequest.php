<?php

namespace App\Http\Requests;

use App\Rules\EgressOwner;
use Illuminate\Foundation\Http\FormRequest;

class SystemEgressDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['owner' => $this->route('owner')]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['owner' => ['required', 'string', new EgressOwner]];
    }

    public function owner(): string
    {
        return (string) $this->route('owner');
    }
}
