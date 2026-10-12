<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BackupRestoreRequest extends FormRequest
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
            'confirm' => ['required', 'boolean', 'accepted'],
            'delete_existing_files' => ['sometimes', 'boolean'],
            'only' => ['sometimes', 'array'],
            'only.files' => ['sometimes', 'boolean'],
            'only.volumes' => ['sometimes', 'array'],
            'only.volumes.*' => ['string'],
            'only.databases' => ['sometimes', 'array'],
            'only.databases.*' => ['string'],
            'exclude' => ['sometimes', 'array'],
            'exclude.files' => ['sometimes', 'boolean'],
            'exclude.volumes' => ['sometimes', 'array'],
            'exclude.volumes.*' => ['string'],
            'exclude.databases' => ['sometimes', 'array'],
            'exclude.databases.*' => ['string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $only = is_array($data['only'] ?? null) ? $data['only'] : [];
            $exclude = is_array($data['exclude'] ?? null) ? $data['exclude'] : [];

            if (
                $this->restoreFilterNonEmpty($this->normalizeRestoreFilter($only))
                && $this->restoreFilterNonEmpty($this->normalizeRestoreFilter($exclude))
            ) {
                $validator->errors()->add('only', 'Cannot combine include and exclude');
            }
        });
    }

    public function deleteExistingFiles(): bool
    {
        return $this->boolean('delete_existing_files', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function onlyFilter(): array
    {
        /** @var array<string, mixed> $only */
        $only = $this->validated('only') ?? [];

        return $this->normalizeRestoreFilter(is_array($only) ? $only : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function excludeFilter(): array
    {
        /** @var array<string, mixed> $exclude */
        $exclude = $this->validated('exclude') ?? [];

        return $this->normalizeRestoreFilter(is_array($exclude) ? $exclude : []);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function normalizeRestoreFilter(array $filter): array
    {
        return [
            'files' => filter_var($filter['files'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'volumes' => array_values(array_filter(
                is_array($filter['volumes'] ?? null) ? $filter['volumes'] : [],
                static fn ($value): bool => is_string($value) && $value !== '',
            )),
            'databases' => array_values(array_filter(
                is_array($filter['databases'] ?? null) ? $filter['databases'] : [],
                static fn ($value): bool => is_string($value) && $value !== '',
            )),
        ];
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function restoreFilterNonEmpty(array $filter): bool
    {
        if (($filter['files'] ?? false) === true) {
            return true;
        }

        if (($filter['volumes'] ?? []) !== []) {
            return true;
        }

        if (($filter['databases'] ?? []) !== []) {
            return true;
        }

        return false;
    }
}
