<?php

namespace App\Rules;

use App\Lib\Host\HostMemory;
use App\Lib\Host\ProjectMemory;
use Closure;

/**
 * A project's `memory_limit` against {@see ProjectMemory}: never below the minimum for its kind
 * (a container project runs its own Docker daemon), never above the maximum.
 */
final class AccountMemoryLimit implements ProblemRule
{
    use RaisesProblem;

    public function __construct(
        private readonly ?HostMemory $memory = null,
        private readonly bool $container = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $problem = ProjectMemory::problem((int) $value, $this->memory, $this->container);
        if ($problem !== null) {
            $this->reject(['field' => $attribute] + $problem, $fail);
        }
    }
}
