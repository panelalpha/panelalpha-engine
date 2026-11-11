<?php

namespace App\Rules;

use App\Lib\Ssl\Issuers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** The ACME directory the client talks to: an http(s) URL, one of the shorthands, or empty for the default. */
final class AcmeDirectoryUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '' || Issuers::isDirectoryShorthand((string) $value)) {
            return;
        }

        $scheme = is_string($value) ? parse_url($value, PHP_URL_SCHEME) : null;
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail('The :attribute must be an http or https URL, or one of: '.implode(', ', array_keys(Issuers::DIRECTORY_SHORTHANDS)).'.');
        }
    }
}
