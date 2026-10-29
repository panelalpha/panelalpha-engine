<?php

namespace App\Rules;

use App\System\Project\PhpHosting\WordPress\SearchReplace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** One extra WP-CLI argument of a search-replace: a table name or a flag {@see SearchReplace} allows. */
final class SearchReplaceArgument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! SearchReplace::accepts($value)) {
            $fail('The :attribute must be a table name or one of: '.SearchReplace::flags().'.');
        }
    }
}
