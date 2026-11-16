<?php

namespace App\Http\Requests;

use App\Mcp\McpScopeSettings;
use App\Rules\TokenLifetime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class McpTokenStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_in' => ['sometimes', 'nullable', 'string', 'max:16', new TokenLifetime],
            'projects' => ['sometimes', 'array', 'max:500'],
            'projects.*' => ['string', 'exists:users,username'],
            'commands' => ['sometimes', 'nullable', 'array', 'min:1'],
            'commands.*' => ['string', 'distinct', Rule::in(McpScopeSettings::commandNames())],
        ];
    }

    public function messages(): array
    {
        return [
            'commands.*.in' => 'There is no MCP command called :input on this engine.',
            'commands.min' => 'A token limited to no command could call nothing; leave `commands` out for every command.',
        ];
    }

    /** @return list<string>|null the commands the token may call, null for every one the engine offers */
    public function commands(): ?array
    {
        $commands = $this->validated('commands');

        return is_array($commands) ? array_values($commands) : null;
    }
}
