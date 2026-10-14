<?php

namespace App\Http\Requests\Concerns;

trait LowercasesInput
{
    /**
     * Lowercase each named field, a name or a list of names. Anything that is
     * not a string is left for the rules to refuse: strtolower() on it is a TypeError.
     */
    protected function lowercaseInput(string ...$fields): void
    {
        $lower = static fn (mixed $name): mixed => is_string($name) ? strtolower($name) : $name;

        foreach ($fields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $this->merge([$field => is_array($value) ? array_map($lower, $value) : $lower($value)]);
            }
        }
    }
}
