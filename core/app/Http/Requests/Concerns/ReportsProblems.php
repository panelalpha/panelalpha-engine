<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\ProblemException;
use App\Rules\ProblemRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

/**
 * Report a FormRequest's failures as {@see ProblemException}, so a rule
 * failure carries a field and a code like the ones a controller raises.
 */
trait ReportsProblems
{
    /** @var array<string, ProblemRule> */
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
                    $this->problemRules[$field] = $rule;
                }
            }
        }

        return $rules;
    }

    protected function failedValidation(Validator $validator): void
    {
        $problems = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            $rich = ($this->problemRules[$field] ?? null)?->problem();
            $failed = array_keys($validator->failed()[$field] ?? []);

            // One per message, not per field: ProblemException rebuilds
            // `errors` from what it is given.
            foreach (array_values($messages) as $i => $message) {
                $problems[] = ($rich['message'] ?? null) === $message
                    ? $rich
                    : $this->problem($field, $failed[$i] ?? 'invalid', $message);
            }
        }

        throw ProblemException::of($problems);
    }

    /**
     * What a field holds, said in the problem so a caller can fix the value
     * without reading the docs (#83): `expected`, and `examples` where useful.
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

    /** @return array<string, mixed> */
    private function problem(string $field, string $rule, string $message): array
    {
        $reported = $this->reportedField($field);
        // A closure or rule object fails under its class name, which is no code.
        $rule = str_contains($rule, '\\') ? 'invalid' : $rule;

        return [
            'field' => $reported,
            'code' => Str::snake($reported) . '_' . Str::snake($rule),
            'message' => $message,
        ] + ($this->expectations()[$field] ?? []);
    }
}
