<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A rule with more to say than a sentence.
 *
 * validate() is handed $fail(string) and nothing else, so a rule that worked
 * out the value the caller meant has nowhere to put it. It keeps it here, and
 * {@see \App\Http\Requests\Concerns\ReportsProblems} reads it back.
 *
 * Instances are stateful: construct one per field.
 */
interface ProblemRule extends ValidationRule
{
    /** @return ?array<string, mixed> field, code and message, plus anything else */
    public function problem(): ?array;
}
