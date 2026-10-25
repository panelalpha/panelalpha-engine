<?php

namespace App\Http\Requests;

use App\Lib\Backup\ArchivePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackupSettingsUpdateRequest extends FormRequest
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
            'nice' => [
                'sometimes',
                'nullable',
                'integer',
                'between:'.ArchivePriority::NICE_MIN.','.ArchivePriority::NICE_MAX,
            ],
            'ionice_class' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::in(ArchivePriority::IONICE_CLASSES),
            ],
        ];
    }

    /**
     * The whole setting after the change: a field the request leaves out keeps
     * the value it has, and an explicit null switches that half off.
     *
     * @return array{nice: ?int, ionice_class: ?int}
     */
    public function merged(ArchivePriority $current): array
    {
        $values = $current->toArray();

        foreach (array_keys($values) as $field) {
            if ($this->has($field)) {
                $value = $this->validated($field);
                $values[$field] = $value === null ? null : (int) $value;
            }
        }

        return $values;
    }
}
