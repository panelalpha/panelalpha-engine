<?php

namespace App\Lib\Deploy\Inspect;

/**
 * A source that could not be opened: an unreachable repository, a path that is
 * not a directory, a project with no files yet.
 *
 * Separate from \InvalidArgumentException on purpose — the controller answers
 * this with 422 (the caller asked for something that cannot be read) rather
 * than with a 500 (the engine broke).
 *
 * It may carry a full problem, so a suggestion survives being thrown.
 */
class InspectException extends \RuntimeException
{
    /** @var ?array<string, mixed> */
    public ?array $problem = null;

    /** @param array<string, mixed> $problem needs at least field, code and message */
    public static function ofProblem(array $problem): self
    {
        $e = new self((string) ($problem['message'] ?? 'The source could not be read.'));
        $e->problem = $problem;

        return $e;
    }

    /** @return array<string, mixed> */
    public function toProblem(string $field = 'source', string $code = 'unreadable'): array
    {
        return $this->problem ?? [
            'field' => $field,
            'code' => $field . '_' . $code,
            'message' => $this->getMessage(),
        ];
    }
}
