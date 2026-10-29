<?php

namespace App\Http\Requests;

use App\Rules\ListenIp;
use App\Rules\ProxyServerName;
use App\Rules\UpstreamHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProxyRuleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The address and name fields are written into the shared nginx-proxy config verbatim.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'owner_scope' => ['nullable', Rule::in(['system', 'user'])],
            'username' => ['nullable', 'string', 'exists:users,username'],
            'transport' => ['required', Rule::in(['http', 'tcp', 'udp'])],
            'listen_ip' => ['nullable', 'string', new ListenIp],
            'listen_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'server_name' => ['nullable', 'string', new ProxyServerName],
            'upstream_host' => ['required', 'string', new UpstreamHost],
            'upstream_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'upstream_protocol' => ['nullable', Rule::in(['http', 'https'])],
            'enabled' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
