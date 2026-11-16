<?php

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ChangesSettings;
use App\Mcp\McpScopeSettings;
use App\Mcp\ToolPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class McpScopeUpdateRequest extends FormRequest
{
    use ChangesSettings;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permission_mode' => ['sometimes', 'nullable', 'string', Rule::in([ToolPolicy::MODE_READONLY, ToolPolicy::MODE_MODIFY, ToolPolicy::MODE_FULL])],
            'commands' => ['sometimes', 'nullable', 'array'],
            'commands.*' => ['string', 'distinct', Rule::in(McpScopeSettings::commandNames())],
        ];
    }

    public function messages(): array
    {
        return ['commands.*.in' => 'There is no MCP command called :input on this engine.'];
    }

    /** @return array{permission_mode?: ?string, commands?: ?list<string>} */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip($this->fields()));
    }

    protected function fields(): array
    {
        return ['permission_mode', 'commands'];
    }
}
