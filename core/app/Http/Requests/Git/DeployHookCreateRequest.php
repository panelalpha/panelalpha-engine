<?php

namespace App\Http\Requests\Git;

use App\Lib\DeployHook\TlsInstructions;
use Illuminate\Validation\Rule;

class DeployHookCreateRequest extends GitPathRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            // The git host the client is about to register the hook with.
            // Narrows the TLS instructions the response carries when the
            // engine's certificate is self-signed; a value this class has no
            // instructions for is a client mistake worth a 422 rather than a
            // response that silently ignores it.
            'provider' => ['nullable', 'string', Rule::in(TlsInstructions::providers())],
        ];
    }
}
