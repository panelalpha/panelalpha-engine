<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Jobs\RebuildProject;
use App\Lib\Deploy\DeployLog\DeployLogFollower;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Task;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Following a deploy log from its file while another process writes it, by the log's own status or by the task that runs it. */
class DeployLogFollowerTest extends TestCase
{
    use InMemoryDatabase;

    private string $username;

    /** @var list<string> */
    private array $printed = [];

    private ?DeployLogger $openLogger = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->username = 'lf' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** @param \Closure(): void $sleep */
    private function follower(\Closure $sleep): DeployLogFollower
    {
        return new DeployLogFollower(function (array $line): void {
            $this->printed[] = (string) $line['msg'];
        }, $sleep);
    }

    public function test_lines_written_while_following_are_printed_until_the_verdict(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->info('before');
        $sleep = static function () use ($logger): void {
            $logger->info('while following');
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        };

        $verdict = $this->follower($sleep)->follow($logger, 10, static function () use ($logger): ?string {
            $status = $logger->readLatest()['status'] ?? null;

            return $status === DeployLogger::STATUS_RUNNING ? null : $status;
        });

        $this->assertSame(DeployLogger::STATUS_SUCCESS, $verdict);
        $this->assertSame(['before', 'while following'], array_slice($this->printed, 0, 2));
        $this->assertCount(3, $this->printed, 'the finish line is drained after the verdict');
    }

    public function test_no_verdict_by_the_timeout_is_null(): void
    {
        $logger = DeployLogger::start($this->username);
        $logger->info('still going');

        $verdict = $this->follower(static function (): void {
        })->follow($logger, 10, static fn (): ?string => null, 0);

        $this->assertNull($verdict);
        $this->assertSame(['still going'], $this->printed);
    }

    public function test_a_queued_task_is_followed_from_its_first_line_until_it_ends(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username);
        $worker = $this->worker($task);

        $ended = $this->follower($worker)->followTask($task);

        $this->assertSame(Task::STATUS_COMPLETED, $ended->status);
        $this->assertSame(['opened by the worker', 'built by the worker'], array_slice($this->printed, 0, 2));
    }

    public function test_a_task_that_ends_before_it_opens_a_log_prints_nothing(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username);
        $task->cancelIfQueued();

        $ended = $this->follower(static function (): void {
            throw new \LogicException('must not wait');
        })->followTask($task);

        $this->assertSame(Task::STATUS_CANCELLED, $ended->status);
        $this->assertSame([], $this->printed);
    }

    public function test_a_task_waiting_for_a_worker_is_announced_once(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username);
        $waits = 0;
        $announced = [];

        $ended = $this->follower(static function () use (&$waits, $task): void {
            if (++$waits === 3) {
                $task->cancelIfQueued();
            }
        })->followTask($task, null, static function (Task $queued) use (&$announced): void {
            $announced[] = $queued->id;
        });

        $this->assertSame(Task::STATUS_CANCELLED, $ended->status);
        $this->assertSame([$task->id], $announced);
    }

    public function test_a_task_no_worker_takes_is_given_up_at_the_timeout(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username);
        $announced = 0;

        $ended = $this->follower(static function (): void {
            throw new \LogicException('the timeout has passed');
        })->followTask($task, 0, static function () use (&$announced): void {
            $announced++;
        });

        $this->assertSame(Task::STATUS_QUEUED, $ended->status);
        $this->assertSame(1, $announced);
    }

    public function test_the_timeout_covers_following_the_log_too(): void
    {
        $task = Task::start(jobType: RebuildProject::class, queue: 'default', username: $this->username);
        $logger = DeployLogger::start($this->username);
        $logger->info('opened by the worker');
        Task::query()->whereKey($task->id)->update(['status' => Task::STATUS_RUNNING, 'details' => json_encode(['deploy_id' => $logger->getDeployId()])]);

        $ended = $this->follower(static function (): void {
            throw new \LogicException('the timeout has passed');
        })->followTask($task, 0);

        $this->assertSame(Task::STATUS_RUNNING, $ended->status);
        $this->assertSame(['opened by the worker'], $this->printed);
    }

    /** @return \Closure(): void each wait is one step of a worker: open the log and name it, write, then end the task */
    private function worker(Task $task): \Closure
    {
        $steps = [
            function () use ($task): void {
                $logger = DeployLogger::start($this->username);
                $logger->info('opened by the worker');
                Task::query()->whereKey($task->id)->update(['status' => Task::STATUS_RUNNING, 'details' => json_encode(['deploy_id' => $logger->getDeployId()])]);
                $this->openLogger = $logger;
            },
            function (): void {
                $this->openLogger?->info('built by the worker');
                $this->openLogger?->finish(DeployLogger::STATUS_SUCCESS);
            },
            static function () use ($task): void {
                Task::query()->whereKey($task->id)->update(['status' => Task::STATUS_COMPLETED]);
            },
        ];

        return static function () use (&$steps): void {
            $step = array_shift($steps);
            if ($step !== null) {
                $step();
            }
        };
    }
}
