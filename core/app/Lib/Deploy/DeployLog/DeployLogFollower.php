<?php

namespace App\Lib\Deploy\DeployLog;

use App\Models\Task;

/**
 * A deploy log printed from its file while another process writes it, by polling: `project:deploy:log --follow` ends it by
 * the log's own status, and the CLI archive deploy by the task a queue worker runs it in.
 */
final class DeployLogFollower
{
    private const POLL_MICROSECONDS = 500_000;

    /** @var \Closure(array{ts?: int, stage?: ?string, level?: string, msg?: string}, int): void */
    private \Closure $write;

    /** @var \Closure(): void */
    private \Closure $sleep;

    /**
     * @param  \Closure(array{ts?: int, stage?: ?string, level?: string, msg?: string}, int): void  $write  one line, and when the deploy started
     * @param  ?\Closure(): void  $sleep  the wait between two reads
     */
    public function __construct(\Closure $write, ?\Closure $sleep = null)
    {
        $this->write = $write;
        $this->sleep = $sleep ?? static function (): void {
            usleep(self::POLL_MICROSECONDS);
        };
    }

    /**
     * The last $tail lines, so a deploy already halfway through does not start mid-sentence, then each new one until $settled
     * answers non-null. $settled is asked before each read, so a deploy that ends between the two is still drained.
     *
     * @template T
     *
     * @param  callable(): (T|null)  $settled
     * @param  ?int  $timeout  seconds to follow for; null follows until $settled answers
     * @return T|null null when the timeout passed first
     */
    public function follow(DeployLogger $logger, int $tail, callable $settled, ?int $timeout = null): mixed
    {
        $deadline = $timeout === null ? null : time() + $timeout;
        [$offset, $startedAt] = $this->printBacklog($logger, $tail);

        while (true) {
            $verdict = $settled();
            $offset = $this->printFrom($logger, $offset, $startedAt);
            if ($verdict !== null) {
                return $verdict;
            }
            if ($deadline !== null && time() >= $deadline) {
                return null;
            }
            ($this->sleep)();
        }
    }

    /**
     * The deploy a queued task runs, from its first line, until the task ends or $timeout passes; nothing for a task that
     * ends before a worker opens its log. The task names its deploy once the worker has opened it.
     *
     * @param  ?int  $timeout  seconds for the whole follow, the wait for a worker included; null follows until the task ends
     * @param  ?\Closure(Task): void  $queued  told once while the task still waits for a worker
     * @return Task not terminal when the timeout passed first
     */
    public function followTask(Task $task, ?int $timeout = null, ?\Closure $queued = null): Task
    {
        $deadline = $timeout === null ? null : time() + $timeout;
        $logger = $this->awaitDeployLog($task, $deadline, $queued);
        if ($logger !== null) {
            $settled = static fn (): ?string => $task->refresh()->isTerminal() ? $task->status : null;
            $this->follow($logger, PHP_INT_MAX, $settled, $deadline === null ? null : max(0, $deadline - time()));
        }

        return $task;
    }

    /** @param  ?\Closure(Task): void  $queued */
    private function awaitDeployLog(Task $task, ?int $deadline, ?\Closure $queued): ?DeployLogger
    {
        while (true) {
            $task->refresh();
            $deployId = $task->details['deploy_id'] ?? null;
            if (is_string($deployId) && $deployId !== '') {
                return DeployLogger::forDeploy((string) $task->username, $deployId);
            }
            if ($task->isTerminal()) {
                return null;
            }
            if ($queued !== null && $task->status === Task::STATUS_QUEUED) {
                $queued($task);
                $queued = null;
            }
            if ($deadline !== null && time() >= $deadline) {
                return null;
            }
            ($this->sleep)();
        }
    }

    /** @return array{0: int, 1: int} where the backlog ended, and when the deploy started */
    private function printBacklog(DeployLogger $logger, int $tail): array
    {
        $page = $logger->read(0);
        $lines = $page['lines'];
        $startedAt = $lines === [] ? time() : (int) ($lines[0]['ts'] ?? time());
        foreach (array_slice($lines, -$tail) as $line) {
            ($this->write)($line, $startedAt);
        }

        return [$page['next_offset'], $startedAt];
    }

    private function printFrom(DeployLogger $logger, int $offset, int $startedAt): int
    {
        $page = $logger->read($offset);
        foreach ($page['lines'] as $line) {
            ($this->write)($line, $startedAt);
        }

        return $page['next_offset'];
    }
}
