<?php

namespace App\Http\Requests\Git;

use App\Lib\DeployHook\TlsInstructions;
use Illuminate\Validation\Rule;

class DeployHookCreateRequest extends GitPathRequest
{
    /**
     * `provider` narrows the TLS instructions the response carries and names
     * the provider to register a webhook at when the repository URL does not;
     * `register` asks the engine to make that webhook itself.
     */
    public function rules(): array
    {
        return parent::rules() + [
            'provider' => ['nullable', 'string', Rule::in(TlsInstructions::providers())],
            'register' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The validated input with `register` as a real boolean: the rule lets
     * `1` and `"1"` through, and those ask for a registration as `true` does.
     *
     * @return array<string, mixed>
     */
    public function hookParams(): array
    {
        return ['register' => $this->boolean('register')] + $this->validated();
    }
}
