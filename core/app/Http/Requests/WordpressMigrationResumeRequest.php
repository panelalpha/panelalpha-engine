<?php

namespace App\Http\Requests;

use App\Models\WordpressMigration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resume body: the source's secret again, required once
 * {@see WordpressMigration::expireIdleSecrets} has cleared it.
 */
class WordpressMigrationResumeRequest extends FormRequest
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
        $missing = $this->migration()?->missingSourceSecret();

        return [
            'password' => [Rule::requiredIf($missing === 'password'), 'nullable', 'string'],
            'source_token' => [Rule::requiredIf($missing === 'source_token'), 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $cleared = 'was cleared after '.WordpressMigration::SECRETS_KEPT_DAYS.' days without activity';

        return [
            'password.required' => "The wp-admin password {$cleared}: send it again as password.",
            'source_token.required' => "The plugin token {$cleared}: send it again as source_token.",
        ];
    }

    /** The secret the body gives for the migration's method, if any. */
    public function sourceSecret(): ?string
    {
        $field = $this->migration()?->usesManualPlugin() ? 'source_token' : 'password';
        $secret = $this->validated($field);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function migration(): ?WordpressMigration
    {
        return WordpressMigration::query()->find((int) $this->route('id'));
    }
}
