<?php

namespace App\Rules;

use App\Lib\Deploy\Inspect\SourceResolver;
use App\Lib\Deploy\Source\GitRepoInput;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;

/**
 * The `source` of POST /source/inspect: a repository, a path, or a project.
 *
 * Only the git case is decided here, by the same {@see GitRepoInput} that
 * `git_repo` goes through. A path or project is left to the resolver, which
 * is the only thing that can check them.
 */
final class InspectableSource implements DataAwareRule, ProblemRule
{
    use RaisesProblem;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param string $typeField the sibling that may name the kind outright */
    public function __construct(private readonly string $typeField = 'type')
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
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $type = self::declaredType($this->data[$this->typeField] ?? null)
            ?? SourceResolver::classify($value);

        if ($type === null) {
            $this->reject([
                'field' => $attribute,
                'code' => $attribute . '_unrecognised',
                'message' => 'Could not tell what this source is. Pass `' . $this->typeField
                    . '` as git, path or project.',
                'expected' => 'a repository URL, an absolute path, or a project name',
                'examples' => ['https://github.com/owner/repo.git', '/home/myapp/project', 'myapp'],
            ], $fail);

            return;
        }

        if ($type !== SourceResolver::TYPE_GIT) {
            return;
        }

        $problem = GitRepoInput::problem($attribute, $value);
        if ($problem !== null) {
            $this->reject($problem, $fail);
        }
    }

    /** The caller's own `type`, when it is one of the three. */
    public static function declaredType(mixed $declared): ?string
    {
        return is_string($declared) && in_array($declared, SourceResolver::TYPES, true)
            ? $declared
            : null;
    }
}
