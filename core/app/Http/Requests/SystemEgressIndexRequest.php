<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemEgressIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['days' => ['sometimes', 'integer', 'min:1', 'max:90']];
    }

    public function days(): ?int
    {
        return $this->has('days') ? (int) $this->input('days') : null;
    }
}
