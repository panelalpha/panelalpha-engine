<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A redirect target written verbatim into nginx's `return 301 "<url>"` in the
 * shared proxy vhost, which the `url` rule alone does not make safe: `$` is a
 * variable there (an unknown one fails the reload for every site), a quote,
 * backslash or backtick changes the string, and whitespace, `;` or a brace
 * ends the directive.
 */
final class RedirectUrl implements ValidationRule
{
    private const FORBIDDEN = '/[\s"\'\\\\$;{}`]/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a URL.');

            return;
        }

        if (preg_match(self::FORBIDDEN, $value) === 1) {
            $fail('The :attribute must not contain whitespace, a quote, a backslash, `$`, `;`, `{`, `}` or a backtick.');
        }
    }
}
