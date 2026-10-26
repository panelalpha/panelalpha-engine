<?php

namespace App\Rules;

use App\Lib\Domains\MainDomainRename;
use App\Models\User;
use Closure;

/**
 * The name a project's main domain is renamed to: free on this engine, as a
 * new project's domain has to be, and one the rename can serve. The rename rewrites
 * vhosts and domain rows before the unique `users.domain` is saved, so it is refused before it starts.
 */
final class FreeMainDomain implements ProblemRule
{
    use RaisesProblem;

    public function __construct(private readonly string $username) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not a string: the `string` rule says so. No project: the controller answers 404.
        $project = is_string($value) && $this->username !== '' ? User::findByUsername($this->username) : null;
        if ($project === null) {
            return;
        }

        $refusal = MainDomainRename::refusal($project, $value);
        if ($refusal !== null) {
            $this->reject(['field' => $attribute, ...$refusal], $fail);
        }
    }
}
