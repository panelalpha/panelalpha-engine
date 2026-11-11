<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReportsProblems;
use App\Models\SecretVaultEntry;
use Illuminate\Foundation\Http\FormRequest;

/** POST /vault/deploy-keys: who may use the key, and the git server whose host keys to pin. */
class VaultDeployKeyStoreRequest extends FormRequest
{
    use ReportsProblems;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', 'in:'.implode(',', SecretVaultEntry::SCOPES)],
            'purpose' => ['nullable', 'string', 'max:255'],
            'project' => ['nullable', 'string'],
            'host' => ['nullable', 'string', 'max:260'],
            'expires_in' => ['nullable', 'integer'],
        ];
    }

    public function scope(): string
    {
        return (string) ($this->validated('scope') ?? SecretVaultEntry::SCOPE_PROJECT);
    }

    public function purpose(): ?string
    {
        return $this->validated('purpose');
    }

    public function project(): ?string
    {
        return $this->validated('project');
    }

    public function host(): ?string
    {
        return $this->validated('host');
    }

    public function expiresIn(): ?int
    {
        $expiresIn = $this->validated('expires_in');

        return $expiresIn === null ? null : (int) $expiresIn;
    }
}
