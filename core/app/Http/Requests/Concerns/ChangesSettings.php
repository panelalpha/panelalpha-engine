<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * A PUT of engine settings: every field optional, at least one given, and only the given ones applied.
 */
trait ChangesSettings
{
    /** @return list<string> the fields the request may set */
    abstract protected function fields(): array;

    public function after(): array
    {
        return [$this->namesAField(...)];
    }

    /** @return array<string, mixed> the validated fields the request gave, by name */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip($this->fields()));
    }

    private function namesAField(Validator $validator): void
    {
        if (array_intersect($this->fields(), array_keys($this->all())) === []) {
            $validator->errors()->add($this->fields()[0], 'Give at least one of: '.implode(', ', $this->fields()).'.');
        }
    }
}
