<?php

namespace App\Lib\Task;

use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Collection;

/**
 * One page of a task's log rows after an id cursor, and where the next starts.
 *
 * The default keeps a page of typical deploy output near 100 KB, well under the
 * 256 KB an MCP tool returns; the maximum is the page the API always gave before.
 */
final class TaskLogPage
{
    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 2000;

    /**
     * @param  Collection<int, TaskLog>  $rows
     */
    private function __construct(
        public readonly Collection $rows,
        public readonly int $nextAfterId,
        public readonly bool $more,
    ) {}

    /** One row past the page is read, so the page knows whether more follow. */
    public static function read(Task $task, int $afterId, int|string|null $since = null, int $limit = self::DEFAULT_LIMIT): self
    {
        $rows = $task->logsPage($afterId, $since, $limit + 1);
        $page = $rows->take($limit);

        return new self(
            $page,
            $page->isEmpty() ? $afterId : (int) $page->last()->id,
            $rows->count() > $limit,
        );
    }
}
