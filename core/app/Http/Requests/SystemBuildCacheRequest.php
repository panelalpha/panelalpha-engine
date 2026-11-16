<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A repository and, optionally, the branch of its build caches; from the body or the query. */
class SystemBuildCacheRequest extends FormRequest
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
            'repository' => ['required', 'string', 'max:2048'],
            'branch' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function repository(): string
    {
        return trim((string) $this->input('repository'));
    }

    public function branch(): ?string
    {
        $branch = trim((string) $this->input('branch'));

        return $branch === '' ? null : $branch;
    }
}
