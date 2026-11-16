<?php

namespace App\Rules;

use App\Lib\Deploy\Source\GitRepoInput;
use Closure;

/**
 * A repository URL this engine can clone.
 *
 * An adapter: {@see GitRepoInput} decides, because SourceResolver has to ask
 * the same question and is not an HTTP request.
 */
final class GitRepositoryUrl implements ProblemRule
{
    use RaisesProblem;

    /**
     * @param  bool  $forToken  a `git_token` came with it, so HTTP is out too
     * @param  bool  $sshWithKey  a deploy key came with it, so an SSH remote is accepted
     */
    public function __construct(private readonly bool $forToken = false, private readonly bool $sshWithKey = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $this->reject([
                'field' => $attribute,
                'code' => $attribute.'_malformed',
                'message' => 'A repository URL must be a string.',
            ], $fail);

            return;
        }

        $problem = GitRepoInput::problem($attribute, $value, $this->forToken, $this->sshWithKey);
        if ($problem !== null) {
            $this->reject($problem, $fail);
        }
    }
}
