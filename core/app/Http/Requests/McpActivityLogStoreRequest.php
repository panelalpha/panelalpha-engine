<?php

namespace App\Http\Requests;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Http\FormRequest;

class McpActivityLogStoreRequest extends FormRequest
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
            'token_name' => 'required|string|max:255',
            'project' => 'nullable|string|max:255',
            'tool_name' => 'required|string|max:255',
            'input' => 'nullable|array',
            'status' => 'required|in:success,error',
            'error_message' => 'nullable|string',
        ];
    }

    /**
     * The row to record. `token_name` names a panel-side token the engine
     * cannot check, so the row also records which engine token wrote it.
     *
     * @return array<string, mixed>
     */
    public function entry(): array
    {
        return [...$this->validated(), 'token_id' => $this->writerTokenId()];
    }

    private function writerTokenId(): ?int
    {
        $user = $this->user();
        $token = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? $token->id : null;
    }
}
