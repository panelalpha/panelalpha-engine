<?php

namespace App\Rules;

use Closure;

/**
 * A value the engine passes to WP-CLI as a positional argument.
 *
 * WP-CLI reads an argument starting with '-' as an option wherever it stands,
 * and the bundled 2.8.1 ignores '--', so no such value may start with '-'.
 * The grammars are unanchored so routes can constrain their parameters too.
 */
final class WpCliArgument implements ProblemRule
{
    use RaisesProblem;

    public const SLUG = '[A-Za-z0-9_][A-Za-z0-9_.-]*';

    public const LOGIN = '[A-Za-z0-9_.@][A-Za-z0-9 _.@-]*';

    public const CONSTANT = '[A-Za-z_][A-Za-z0-9_]*';

    public const OPTION_NAME = '[^/-][^/]*';

    public const UUID = '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}';

    public function __construct(private readonly ?string $grammar = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (str_starts_with($value, '-')) {
            $this->reject([
                'field' => $attribute,
                'code' => $attribute.'_option_like',
                'message' => "The {$attribute} must not start with '-': WP-CLI would read it as an option.",
            ], $fail);
        } elseif ($this->grammar !== null && preg_match('~^'.$this->grammar.'$~D', $value) !== 1) {
            $fail('The :attribute format is invalid.');
        }
    }
}
