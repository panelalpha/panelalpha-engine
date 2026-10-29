<?php

namespace App\Rules;

use App\Lib\Project\ApiAccess;
use Closure;

/**
 * The names the engine delivers itself. An account's `env_vars` may not carry
 * one: the value in the container is what reaches the engine's API, and the
 * account cannot know the token the engine holds.
 */
final class EnvVarKeysNotReserved implements ProblemRule
{
    use RaisesProblem;

    public const CODE = 'env_vars_reserved';

    public const EXPECTED = 'a name the engine does not set itself';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach (ApiAccess::ENV_KEYS as $reserved) {
            if (! array_key_exists($reserved, $value)) {
                continue;
            }

            $this->reject([
                'field' => $attribute,
                'code' => self::CODE,
                'message' => "{$reserved} is the engine's own variable, set for a project it allows "
                    ."to use its API. A project's env_vars may not carry it.",
                'expected' => self::EXPECTED,
            ], $fail);

            return;
        }
    }
}
