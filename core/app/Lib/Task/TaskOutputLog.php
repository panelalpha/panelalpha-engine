<?php

namespace App\Lib\Task;

use Closure;

/**
 * A command's raw output into a task's log as it arrives: written once 4 KB
 * have gathered or two seconds have passed, rather than a row per chunk.
 */
final class TaskOutputLog
{
    private const FLUSH_BYTES = 4096;

    private const FLUSH_SECONDS = 2;

    private string $pending = '';

    private float $flushedAt = 0.0;

    /**
     * @param  Closure(string): void  $write  one task log entry
     */
    public function __construct(private readonly Closure $write) {}

    public function __invoke(string $chunk): void
    {
        $this->pending .= $chunk;
        if (strlen($this->pending) >= self::FLUSH_BYTES || microtime(true) - $this->flushedAt >= self::FLUSH_SECONDS) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        ($this->write)(rtrim($this->pending));
        $this->pending = '';
        $this->flushedAt = microtime(true);
    }
}
