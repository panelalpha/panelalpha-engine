<?php

namespace App\Rules;

use App\Lib\Limits\ResourceLimit;
use Closure;

/**
 * A resource limit: exactly UNLIMITED (-1), or 0 or more. `min:-1` alone also
 * took every fraction between them, such as a cpu_limit of -0.5.
 */
final class UnlimitedOrAtLeastZero implements ProblemRule
{
    use RaisesProblem;

    public const EXPECTED = '-1 for unlimited, or 0 or more';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || self::isAccepted((float) $value)) {
            return;
        }

        $this->reject([
            'field' => $attribute,
            'code' => $attribute.'_min',
            'message' => 'The '.str_replace('_', ' ', $attribute).' must be '.self::EXPECTED.'.',
            'expected' => self::EXPECTED,
            'examples' => [(string) ResourceLimit::UNLIMITED, '0'],
        ], $fail);
    }

    private static function isAccepted(float $value): bool
    {
        return $value >= 0 || $value === (float) ResourceLimit::UNLIMITED;
    }
}
