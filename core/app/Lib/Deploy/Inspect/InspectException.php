<?php

namespace App\Lib\Deploy\Inspect;

/**
 * A source that could not be opened (an unreachable repository, not a directory, no files yet), kept apart from
 * \InvalidArgumentException so it is answered 422, not 500. It may carry a full problem, so a suggestion survives.
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
            'code' => $field.'_'.$code,
            'message' => $this->getMessage(),
        ];
    }
}
