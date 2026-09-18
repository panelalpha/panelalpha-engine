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
                    : self::problem($field, $failed[$i] ?? 'invalid', $message);
            }
        }

        throw ProblemException::of($problems);
    }

    /** @return array<string, mixed> */
    private static function problem(string $field, string $rule, string $message): array
    {
        return [
            'field' => $field,
            'code' => Str::snake($field) . '_' . Str::snake($rule),
            'message' => $message,
        ];
    }
}
