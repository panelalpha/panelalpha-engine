<?php

namespace App\Http\Requests;

use App\Rules\SshPublicKey;
use Illuminate\Foundation\Http\FormRequest;

class SftpAccountStoreRequest extends FormRequest
{
    /**
     * Accept `sftp_username` as an alias for `username`.
     *
     * The account name has always been `username` in the body, but this route's
     * path parameter is also `{username}` (the project). Anything that flattens
     * path and body into one parameter list -- the generated MCP tool, most
     * notably -- cannot carry both, and the body copy is the one that gets
     * dropped. `sftp_username` gives that caller a name of its own; `username`
     * keeps working unchanged for every existing client.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('username') && $this->has('sftp_username')) {
            $this->merge(['username' => $this->input('sftp_username')]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => 'string|required|max:32|regex:/^[a-zA-Z0-9_]+$/',
            'auth_method' => 'string|required',
            'password' => 'string|nullable|min:8|max:255',
            'public_key' => ['string', 'nullable', 'max:4096', new SshPublicKey()],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Username can only contain letters, numbers, and underscores.',
            'username.max' => 'Username cannot be longer than 32 characters.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.max' => 'Password cannot be longer than 255 characters.',
            'public_key.max' => 'Public key is too large (maximum 4096 characters).',
        ];
    }
}
