<?php

namespace App\Rules;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Deploy\Source\GitUrl;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;

/**
 * A Git access token, checked only when one was sent.
 *
 * Two questions: is it shaped like a credential, and may it travel to the
 * repository it came with. The second reads a sibling field, which is why
 * this is data-aware rather than a plain rule.
 */
final class GitAccessToken implements DataAwareRule, ProblemRule
{
    use RaisesProblem;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param string $repoField the sibling holding the repository URL */
    public function __construct(private readonly string $repoField = 'git_repo')
    {
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            $this->reject([
                'field' => $attribute,
                'code' => $attribute . '_malformed',
                'message' => 'A Git token must be a string.',
            ], $fail);

            return;
        }

        $problem = GitTokenInput::problem($attribute, $value);
        if ($problem !== null) {
            $this->reject($problem, $fail);

            return;
        }

        // Second, so a pasted Authorization header is named as one rather
        // than blamed on the repository URL.
        if (!$this->repoCanCarryAToken()) {
            $this->reject([
                'field' => $attribute,
                'code' => $attribute . '_requires_https_repo',
                'message' => 'A Git token requires an HTTPS repository URL without embedded '
                    . 'credentials. It is injected at clone time, so it needs a remote that '
                    . 'can carry it safely.',
                'expected' => 'https://<host>/<owner>/<repo>[.git] in ' . $this->repoField,
            ], $fail);
        }
    }

    /**
     * Normalised first: create fills the scheme in during
     * prepareForValidation, inspect cannot -- its field also carries paths
     * and project names.
     */
    private function repoCanCarryAToken(): bool
    {
        $repo = $this->data[$this->repoField] ?? null;

        return is_string($repo) && GitUrl::isHttpsWithoutCredentials(GitRepoInput::normalise($repo));
    }
}
