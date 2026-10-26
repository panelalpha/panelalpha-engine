<?php

namespace App\Rules;

use Closure;

/**
 * The names an env_vars map may carry: the grammar every `.env` reader,
 * shell and compose file accepts, so a name cannot spell a second line.
 */
final class EnvVarKeys implements ProblemRule
{
    use RaisesProblem;

    /** `\A`/`\z`: `$` also matches before a final newline, which a name may not hide behind. */
    public const PATTERN = '^[A-Za-z_][A-Za-z0-9_]*$';

    public const EXPECTED = 'a name of letters, digits and underscores, starting with a letter or an underscore';

    public const EXAMPLES = ['APP_ENV', 'DATABASE_URL', 'MY_FLAG'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $key) {
            if (is_string($key) && preg_match('/\A'.self::PATTERN.'\z/', $key) === 1) {
                continue;
            }
            $shown = is_scalar($key) ? (string) $key : gettype($key);

            $this->reject([
                'field' => $attribute,
                'code' => $attribute.'_invalid_key',
                'message' => "'{$shown}' is not a valid environment variable name. A name is ".self::EXPECTED.'.',
                'expected' => self::EXPECTED,
                'pattern' => self::PATTERN,
                'examples' => self::EXAMPLES,
            ], $fail);

            return;
        }
    }
}
