<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A hostname or IP address that is about to be written into an nginx
 * `upstream { server ...; }` block verbatim. Nothing but hostname characters
 * may pass: a space, a semicolon or a newline there is a new directive in
 * the shared reverse proxy.
 */
final class UpstreamHost implements ValidationRule
{
    // Underscores allowed: compose service names (`my_app`) are upstreams too.
    private const HOSTNAME = '/\A[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*\z/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !self::isValid($value)) {
            $fail('The :attribute must be a hostname or an IP address.');
        }
    }

    public static function isValid(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }

        return preg_match(self::HOSTNAME, $host) === 1
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
