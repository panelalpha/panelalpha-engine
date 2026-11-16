<?php

namespace App\Rules;

use App\Auth\OperatorTokens;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** How long a new token lasts: a number and a unit, as `pae configure` asks for one (30m, 12h, 90d, 2w). */
class TokenLifetime implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $why = is_string($value) ? OperatorTokens::badLifetime($value) : 'Give a number and a unit, like 90d.';
        if ($why !== null) {
            $fail($why);
        }
    }
}
