<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A credential written raw into Exim's `passwd.client`, one `*:user:pass` per
 * line: `&`, `<` or a quote must survive, while a line break or NUL would start
 * a second entry.
 */
final class EximSecret implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            $fail('The :attribute must not contain a line break or a null byte.');
        }
    }
}
