<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which directory of a project to inspect.
 *
 * The parameter is `subdirectory`, the name POST /source/inspect already used
 * for the same thing. `path` was this endpoint's original spelling and is
 * still accepted: the two named one concept in two words, which is exactly
 * the kind of difference a caller discovers by getting a 200 with the wrong
 * directory in it.
 */
class ProjectSourceInspectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fold the deprecated spelling onto the canonical one before validation,
     * so everything downstream of here knows only `subdirectory`.
     */
    protected function prepareForValidation(): void
    {
        $subdirectory = $this->input('subdirectory');
        $legacy = $this->input('path');

        if (! is_string($subdirectory) && is_string($legacy)) {
            $this->merge(['subdirectory' => $legacy]);
        }
    }

    public function rules(): array
    {
        return [
            'subdirectory' => 'nullable|string|max:512',
            // Validated as well as merged: a caller sending only `path` must
            // still be told when it is the wrong shape rather than having it
            // silently dropped.
            'path' => 'nullable|string|max:512',
        ];
    }
}
