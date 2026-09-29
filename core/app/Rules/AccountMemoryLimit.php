<?php

namespace App\Rules;

use App\Lib\Host\HostMemory;
use App\Lib\Host\ProjectMemory;
use Closure;

/** A project's `memory_limit` against {@see ProjectMemory}: never above the maximum. */
final class AccountMemoryLimit implements ProblemRule
{
    use RaisesProblem;

    public function __construct(
        private readonly ?HostMemory $memory = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value)) {
            return;
        }

        $problem = ProjectMemory::problem((int) $value, $this->memory);
        if ($problem !== null) {
            $this->reject(['field' => $attribute] + $problem, $fail);
        }
    }
}
