<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\ProblemException;
use App\Rules\ProblemRule;
use App\Rules\RuleExpectation;
use Illuminate\Contracts\Validation\Validator;

/**
 * Report a FormRequest's failures as {@see ProblemException}, so a rule
 * failure carries a field and a code like the ones a controller raises.
 */
trait ReportsProblems
{
    /** @var array<string, list<ProblemRule>> */
    private array $problemRules = [];

    /**
     * Harvested here because the validator wraps rule objects in
     * InvokableValidationRule, and the wrapper is all getRules() returns.
     *
     * @return array<string, mixed>
     */
    protected function validationRules(): array
    {
        $rules = parent::validationRules();

        foreach ($rules as $field => $set) {
            foreach (is_array($set) ? $set : [$set] as $rule) {
                if ($rule instanceof ProblemRule) {
                    $this->problemRules[$field][] = $rule;
                }
            }
        }

        return $rules;
    }

    protected function failedValidation(Validator $validator): void
    {
        $generic = RuleExpectation::problems(
            $validator,
            fn (string $field): ?array => $this->expectations()[$field] ?? null,
            fn (string $field): string => $this->reportedField($field),
        );
        $problems = [];
        $i = 0;

        foreach ($validator->errors()->messages() as $field => $messages) {
            // One per message, not per field: ProblemException rebuilds
            // `errors` from what it is given.
            foreach ($messages as $message) {
                $problems[] = $this->richProblem($field, $message) ?? $generic[$i];
                $i++;
            }
        }

        throw ProblemException::of($problems);
    }

    /**
     * The rule that failed with this message, so its field and code go with it;
     * a field may carry more than one, and the others report nothing.
     *
     * @return ?array<string, mixed>
     */
    private function richProblem(string $field, string $message): ?array
    {
        foreach ($this->problemRules[$field] ?? [] as $rule) {
            $problem = $rule->problem();
            if (($problem['message'] ?? null) === $message) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * What a field holds, said in the problem so a caller can fix the value
     * without reading the docs: `expected`, and `examples` where useful.
     *
     * @return array<string, array{expected: string, examples?: list<string>}>
     */
    protected function expectations(): array
    {
        return [];
    }

    /** The field as the caller spelled it, where the request renamed it. */
    protected function reportedField(string $field): string
    {
        return $field;
    }
}
