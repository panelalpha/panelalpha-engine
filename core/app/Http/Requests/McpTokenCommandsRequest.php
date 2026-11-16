<?php

namespace App\Http\Requests;

use App\Mcp\McpScopeSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class McpTokenCommandsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'commands' => ['present', 'nullable', 'array', 'min:1'],
            'commands.*' => ['string', 'distinct', Rule::in(McpScopeSettings::commandNames())],
        ];
    }

    public function messages(): array
    {
        return [
            'commands.*.in' => 'There is no MCP command called :input on this engine.',
            'commands.min' => 'A token limited to no command is not a limit the engine can store; revoke the token to stop it.',
        ];
    }

    /** @return list<string>|null the commands the token may call, null for every one the engine offers */
    public function commands(): ?array
    {
        $commands = $this->validated('commands');

        return $commands === null ? null : array_values($commands);
    }
}
