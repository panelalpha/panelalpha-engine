<?php

namespace App\Http\Requests\Csf;

use Illuminate\Foundation\Http\FormRequest;

class AddRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'protocol' => 'nullable|string|in:tcp,udp',
            'direction' => 'nullable|string|in:in,out',
            'port_prefix' => 'nullable|string|in:s=,d=',
            // One csf.allow/csf.deny line is built from these; a newline in
            // any of them would be a second, unvalidated rule.
            'port' => ['nullable', 'string', 'regex:/\A[0-9]+(?:[_:][0-9]+)?(?:,[0-9]+(?:[_:][0-9]+)?)*\z/'],
            'target_prefix' => 'nullable|string|in:s=,d=,u=',
            // An IP, a CIDR, a hostname, or a uid for `u=`.
            'target' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9.:_-]+(?:\/[0-9]{1,3})?\z/'],
            'comment' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
        ];
    }
}
