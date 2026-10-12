<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BackupStoreRequest extends FormRequest
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
            'files' => ['sometimes', 'boolean'],
            'databases' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
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

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
