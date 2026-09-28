<?php

namespace App\Rules;

use App\Lib\Host\HostMemory;
use App\Lib\Host\ProjectMemory;
use Closure;

/**
 * A project's `memory_limit` against {@see ProjectMemory}: never above the
 * maximum, and on creation only when the host has it free right now.
 */
final class AccountMemoryLimit implements ProblemRule
{
    use RaisesProblem;

    public function __construct(
        private readonly bool $creating,
        private readonly ?HostMemory $memory = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_numeric($value)) {
            return;
        }

        $problem = $this->creating
            ? ProjectMemory::creationProblem((int) $value, $this->memory)
            : ProjectMemory::changeProblem((int) $value, $this->memory);
        if ($problem !== null) {
            $this->reject(['field' => $attribute] + $problem, $fail);
        }
    }
}
