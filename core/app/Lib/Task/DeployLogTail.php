<?php

namespace App\Lib\Task;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use App\Models\TaskLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the end of a failed deploy's log on its task, because the rollback
 * deletes the account and its deploy log with it.
 */
final class DeployLogTail
{
    public const MAX_LINES = 150;

    /** Marks a copied line so TaskPruner keeps it even when it is `dim`. */
    public const FLAG = 'deploy_log_tail';

    public const WHERE = "The last lines of the deploy log are in this task's log.";

    /**
     * Best-effort: returns false instead of throwing, so it can never mask
     * the deploy's own error or block the rollback.
     */
    public static function preserve(?Task $task, ?DeployLogger $logger, int $limit = self::MAX_LINES): bool
    {
        if ($task === null || $logger === null) {
            return false;
        }

        try {
            $lines = $logger->tail(max(1, min($limit, self::MAX_LINES)));
            if ($lines === []) {
                return false;
            }

            $now = now();
            $rows = [self::row($task, [
                'ts' => time(),
                'stage' => null,
                'level' => DeployLogger::LEVEL_INFO,
                'msg' => 'Last '.count($lines).' lines of the deploy log (the rollback deletes it):',
            ], $now)];
            foreach ($lines as $line) {
                $rows[] = self::row($task, $line, $now);
            }
            foreach (array_chunk($rows, TaskLogSink::BATCH_SIZE) as $chunk) {
                TaskLog::insert($chunk);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning("Could not copy the deploy log tail to task {$task->id}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function isKept(array $decoded): bool
    {
        return ($decoded[self::FLAG] ?? false) === true;
    }

    /** Point a failure message at this task's log instead of the deleted deploy log. */
    public static function retarget(string $message): string
    {
        $pattern = '/The full (?:\w+ )?output is in the deploy log\./';
        if (preg_match($pattern, $message) === 1) {
            return preg_replace($pattern, self::WHERE, $message) ?? $message;
        }

        return rtrim($message).' '.self::WHERE;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{task_id: int, log: string, created_at: Carbon}
     */
    private static function row(Task $task, array $line, Carbon $now): array
    {
        return [
            'task_id' => $task->id,
            'log' => (string) json_encode([
                'ts' => (int) ($line['ts'] ?? time()),
                'stage' => $line['stage'] ?? null,
                'level' => (string) ($line['level'] ?? DeployLogger::LEVEL_DIM),
                'msg' => (string) ($line['msg'] ?? ''),
                self::FLAG => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at' => $now,
        ];
    }
}
