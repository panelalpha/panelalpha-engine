<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BackupScheduleUpdateRequest extends FormRequest
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
            'container' => ['required', 'string'],
            'cron' => ['required', 'string', 'max:255'],
            'keep_last' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'files' => ['sometimes', 'boolean'],
            'databases' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function cron(): string
    {
        return (string) $this->validated('cron');
    }

    public function keepLast(): int
    {
        $value = $this->validated('keep_last');

        return is_numeric($value) ? (int) $value : 7;
    }

    /**
     * @return array{files: bool, databases: bool}
     */
    public function contents(): array
    {
        return [
            'files' => $this->boolean('files', true),
            'databases' => $this->boolean('databases', true),
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled', true);
    }
}
