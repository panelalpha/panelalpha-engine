<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Http\Requests\Concerns\ResolvesVaultFields;
use App\Models\SftpAccount;
use App\Models\User;
use App\Rules\SshPublicKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class SftpAccountStoreRequest extends FormRequest
{
    use ReportsProblems;
    use ResolvesVaultFields;

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
        if (! $this->has('username') && $this->has('sftp_username')) {
            $this->merge(['username' => $this->input('sftp_username')]);
        }
        $this->resolveVaultFields(['password']);
    }

    public function rules(): array
    {
        return [
            'username' => 'string|required|max:'.SftpAccount::MAX_USERNAME_LENGTH.'|regex:/^[a-zA-Z0-9_]+$/D',
            'auth_method' => 'string|required',
            'password' => 'string|nullable|min:8|max:255',
            'public_key' => ['string', 'nullable', 'max:4096', new SshPublicKey],
        ];
    }

    /** The name once the project's prefix is on it; an unknown project is left to the controller's 404. */
    public function after(): array
    {
        return [$this->checkQualifiedName(...)];
    }

    private function checkQualifiedName(Validator $validator): void
    {
        $project = User::findByUsername((string) $this->route('username'));
        if ($validator->errors()->isNotEmpty() || $project === null) {
            return;
        }

        $prefix = $project->username.'_';
        $name = $project->qualifySftpAccount((string) $this->input('username'));
        if (! Str::after($name, $prefix)) {
            $validator->errors()->add('username', 'Username needs a name after '.$prefix);
        } elseif (strlen($name) > SftpAccount::MAX_USERNAME_LENGTH) {
            $validator->errors()->add(
                'username',
                'Username cannot be longer than '.SftpAccount::MAX_USERNAME_LENGTH.' characters with its '.$prefix.' prefix.',
            );
        }
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Username can only contain letters, numbers, and underscores.',
            'username.max' => 'Username cannot be longer than '.SftpAccount::MAX_USERNAME_LENGTH.' characters.',
            'password.min' => 'Password must be at least 8 characters long.',
            'password.max' => 'Password cannot be longer than 255 characters.',
            'public_key.max' => 'Public key is too large (maximum 4096 characters).',
        ];
    }
}
