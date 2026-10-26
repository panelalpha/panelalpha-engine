<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * One process-batch report of the source plugin, with the table or file
 * count its /remote-sync/status gave when the step began.
 */
final class TransferProgress
{
    public function __construct(
        public readonly ?string $phase,
        public readonly float $percentage,
        public readonly ?string $item,
        public readonly int $sentChunks = 0,
        public readonly int $totalChunks = 0,
        public readonly ?int $itemCount = null,
    ) {}

    /**
     * @param  array{current_phase: ?string, current_phase_percent: float, current_item: ?string, sent_chunks?: int, total_chunks?: int}  $batch
     * @param  array<array-key, mixed>  $status
     */
    public static function fromBatch(array $batch, array $status): self
    {
        return new self(
            $batch['current_phase'],
            $batch['current_phase_percent'],
            $batch['current_item'],
            $batch['sent_chunks'] ?? 0,
            $batch['total_chunks'] ?? 0,
            self::itemCount($batch['current_phase'], $status),
        );
    }

    /** Chunks sent of all chunks when the plugin counts them, else its own percentage. */
    public function phasePercent(): float
    {
        $percent = $this->totalChunks > 0 ? 100 * $this->sentChunks / $this->totalChunks : $this->percentage;

        return round(max(0.0, min(100.0, $percent)), 1);
    }

    public function message(): string
    {
        $files = $this->phase === 'files';
        $chunks = $this->totalChunks > 0 ? sprintf(': %d/%d chunks', $this->sentChunks, $this->totalChunks) : '';
        $count = $this->itemCount === null ? '' : sprintf(' (%d %s)', $this->itemCount, $files ? 'files' : 'tables');

        return 'Copying the '.($files ? 'files' : 'database').$chunks.$count;
    }

    /**
     * @param  array<array-key, mixed>  $status
     */
    private static function itemCount(?string $phase, array $status): ?int
    {
        $count = $phase === 'files'
            ? ($status['files']['total_files'] ?? null)
            : ($status['database']['total_tables'] ?? null);

        return is_numeric($count) ? (int) $count : null;
    }
}
