<?php

namespace App\Http\Requests;

use App\Rules\UpstreamHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProxyRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'upstream_host' => ['nullable', 'string', new UpstreamHost],
            'upstream_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            'enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
