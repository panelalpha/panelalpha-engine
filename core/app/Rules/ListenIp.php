<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The address side of an nginx `listen` directive: `*` for every address,
 * or one IP. Written into the config verbatim, so nothing else may pass.
 */
final class ListenIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !self::isValid($value)) {
            $fail('The :attribute must be "*" or an IP address.');
        }
    }

    public static function isValid(string $ip): bool
    {
        return $ip === '*' || filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
