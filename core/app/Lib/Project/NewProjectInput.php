<?php

namespace App\Lib\Project;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A project create as {@see ProjectCreator} takes it: the validated fields,
 * plus the raw values the create reads itself -- vault references are resolved
 * only once the name is known, and `stages`/`recipe` have a grammar of their own.
 */
final class NewProjectInput
{
    /**
     * @param array<string, mixed> $params validated against {@see NewProjectRules}
     * @param string $nameField what the caller called the project name
     */
    public function __construct(
        public readonly array $params,
        public readonly string $nameField = 'username',
        public readonly mixed $gitToken = null,
        public readonly mixed $envVars = null,
        public readonly mixed $stages = null,
        public readonly mixed $recipe = null,
    ) {
    }

    /**
     * Validate a create given as plain fields, the way the API's FormRequest does.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function fromArray(array $input): self
    {
        [$changes, $nameField] = NewProjectRules::normalised($input);
        $input = array_replace($input, $changes);

        $params = Validator::make(
            $input,
            NewProjectRules::rules($nameField, $input['domain'] ?? null),
            [],
            ['username' => $nameField],
        )->validate();

        return new self(
            $params,
            $nameField,
            $input['git_token'] ?? null,
            $input['env_vars'] ?? null,
            $input['stages'] ?? null,
            $input['recipe'] ?? null,
        );
    }
}
