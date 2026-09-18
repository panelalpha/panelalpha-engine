<?php

namespace App\Rules;

use Closure;

/** Hold the problem, and fail with its message so `errors` is unchanged. */
trait RaisesProblem
{
    /** @var ?array<string, mixed> */
    private ?array $problem = null;

    /** @return ?array<string, mixed> */
    public function problem(): ?array
    {
        return $this->problem;
    }

    /** @param array<string, mixed> $problem */
    protected function reject(array $problem, Closure $fail): void
    {
        $this->problem = $problem;
        $fail((string) $problem['message']);
    }
}
