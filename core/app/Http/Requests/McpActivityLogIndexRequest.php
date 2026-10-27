<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The filters of GET /mcp-activity-logs; a filter sent empty keeps everything. */
class McpActivityLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token_ids' => 'nullable|array|max:1000',
            'token_ids.*' => 'integer|min:1',
            'token_name' => 'nullable|string|max:255',
            'project' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /** @return array<int> */
    public function tokenIds(): array
    {
        $ids = $this->validated('token_ids');

        return empty($ids) ? [] : array_map('intval', $ids);
    }

    public function tokenName(): string
    {
        return $this->filledText('token_name');
    }

    public function project(): string
    {
        return $this->filledText('project');
    }

    private function filledText(string $key): string
    {
        $value = $this->validated($key);

        return empty($value) ? '' : $value;
    }
}
