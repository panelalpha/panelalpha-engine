<?php

namespace App\Rules;

use Closure;

/**
 * A project (account) name, reported as one problem that states the format.
 *
 * It used to be five Laravel rules, so a bad name came back as up to three
 * sentences like "The username format is invalid." with no word on what the
 * format is (#83).
 */
final class ProjectName implements ProblemRule
{
    use RaisesProblem;

    public const PATTERN = '^[a-z][a-z0-9]{2,14}$';

    public const EXPECTED = '3-15 characters: lowercase letters a-z and digits, starting with a letter';

    public const EXAMPLES = ['shop', 'blog2', 'myapp01'];

    /** @param string $field the name the caller sent it under: `name` or `username` */
    public function __construct(private readonly string $field = 'username')
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not a string: the `string` rule beside this one says so.
        if (!is_string($value) || preg_match('/' . self::PATTERN . '/', $value) === 1) {
            return;
        }

        $suggestion = self::suggest($value);
        $shown = mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value;

        $this->reject(array_filter([
            'field' => $this->field,
            'code' => $this->field . '_invalid',
            'message' => "'{$shown}' is not a valid project name. A project name is " . self::EXPECTED . '.'
                . ($suggestion !== null ? " For example: {$suggestion}." : ''),
            'expected' => self::EXPECTED,
            'pattern' => self::PATTERN,
            'suggestion' => $suggestion,
            'examples' => self::EXAMPLES,
        ], static fn (mixed $v): bool => $v !== null), $fail);
    }

    /** The nearest valid name, or null when too little of the value survives. */
    public static function suggest(string $value): ?string
    {
        $name = (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
        $name = substr(ltrim($name, '0123456789'), 0, 15);

        return strlen($name) >= 3 ? $name : null;
    }
}
