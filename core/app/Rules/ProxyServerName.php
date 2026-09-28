<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One nginx `server_name` for a proxy rule: `_`, a hostname, or a hostname
 * with a leading `*.` or `.`. Written into the shared proxy config verbatim,
 * so a space, semicolon or newline would be a directive of its own.
 */
final class ProxyServerName implements ValidationRule
{
    private const NAME = '/\A(?:\*\.|\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !self::isValid($value)) {
            $fail('The :attribute must be "_" or a hostname.');
        }
    }

    public static function isValid(string $name): bool
    {
        return $name === '_' || (strlen($name) <= 253 && preg_match(self::NAME, $name) === 1);
    }
}
