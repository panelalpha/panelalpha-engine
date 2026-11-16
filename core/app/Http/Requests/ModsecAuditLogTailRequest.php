<?php

namespace App\Http\Requests;

use App\Lib\Modsec\AuditLogFiles;
use Illuminate\Foundation\Http\FormRequest;

class ModsecAuditLogTailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.AuditLogFiles::MAX_TAIL_ENTRIES],
        ];
    }

    /** The most entries asked for, or null for the whole 50 KB window. */
    public function limit(): ?int
    {
        return $this->filled('limit') ? $this->integer('limit') : null;
    }
}
