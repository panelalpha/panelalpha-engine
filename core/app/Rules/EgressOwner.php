<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A project or a build as the outbound limits name it, and as the root broker takes it. */
final class EgressOwner implements ValidationRule
{
    public const PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\z/';

    public const MESSAGE = 'Not a project or build name.';

    public static function accepts(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::accepts($value)) {
            $fail(self::MESSAGE);
        }
    }
}
