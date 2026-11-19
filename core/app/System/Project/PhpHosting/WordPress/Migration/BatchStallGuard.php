<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * Counts process-batch answers in a row that report the same phase, sent chunks and total chunks while
 * chunks are left to send; BATCHES of them mean the source has stalled.
 */
final class BatchStallGuard
{
    public const BATCHES = 3;

    /** @var array{?string, int, int}|null */
    private ?array $lastChunks = null;

    private int $repeats = 0;

    /**
     * @param  array{current_phase: ?string, sent_chunks: int, total_chunks: int}  $result
     */
    public function stalled(array $result, bool $completed): bool
    {
        $chunks = $completed || $result['total_chunks'] <= 0 ? null : self::chunks($result);

        $this->repeats = match (true) {
            $chunks === null => 0,
            $chunks === $this->lastChunks => $this->repeats + 1,
            default => 1,
        };
        $this->lastChunks = $chunks;

        return $this->repeats >= self::BATCHES;
    }

    /**
     * @param  array{current_phase: ?string, sent_chunks: int, total_chunks: int}  $result
     * @return array{?string, int, int}
     */
    private static function chunks(array $result): array
    {
        return [$result['current_phase'], $result['sent_chunks'], $result['total_chunks']];
    }
}
