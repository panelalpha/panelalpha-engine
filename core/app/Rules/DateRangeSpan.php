<?php

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a Y-m-d `start`/`end` pair more than ten years apart: AWStats walks
 * every month of the range per domain, so an unbounded span holds a PHP worker.
 * A missing, malformed or reversed pair is left to the other rules.
 */
final class DateRangeSpan implements DataAwareRule, ValidationRule
{
    private const MAX_YEARS = 10;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $endField) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /** A reversed pair never fails here: start + 10 years is after start. */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $end = $this->data[$this->endField] ?? null;
        if (! is_string($value) || ! is_string($end)) {
            return;
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $finish = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if ($start === false || $finish === false) {
            return;
        }

        if ($start->modify('+'.self::MAX_YEARS.' years') < $finish) {
            $fail("The :attribute and {$this->endField} cannot span more than ".self::MAX_YEARS.' years.');
        }
    }
}
