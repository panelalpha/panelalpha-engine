<?php

namespace App\Lib\Deploy\DeployLog;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\DeployHook\Coalescing;
use Illuminate\Support\Facades\Log;

/**
 * Structured deploy log for DinD project deployments.
 *
 * A deploy is a sequence of stages (preparing, cloning, running) run
 * synchronously inside an engine API request. Because PHP-FPM serves requests
 * concurrently, a polling request reads the log file while the deploy request
 * is still writing to it — which is why the status pointer is replaced whole
 * and the log itself is append-only JSON lines.
 *
 * This class is the deploy's view of that: it owns the deploy id and the
 * lock, and hands the actual work to {@see DeployLogPaths},
 * {@see DeployStatus}, {@see DeployLogReader}, {@see DeployLogArchive} and
 * {@see ProcessOutput}.
 *
 * The contract (payload shape, statuses, stages) is designed so the deploy
 * can later move to a background process without panel or frontend changes.
 */
class DeployLogger
{
    public const STAGE_PREPARING = 'preparing';
    public const STAGE_CLONING = 'cloning';
    public const STAGE_RUNNING = 'running';

    public const LEVEL_DIM = 'dim';
    public const LEVEL_OK = 'ok';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_ERROR = 'error';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const KEEP_LOGS = DeployLogArchive::KEEP_LOGS;

    /** How a finished deploy announces itself, by status. */
    private const FINISH_MESSAGE = [
        self::STATUS_SUCCESS => 'Deploy finished successfully',
        self::STATUS_PARTIAL => 'Deploy finished with warnings',
        self::STATUS_CANCELLED => 'Deploy cancelled',
        self::STATUS_FAILED => 'Deploy failed',
    ];

    /** latest.json key set by {@see markPreCheckRejected()}. */
    public const PRECHECK_REJECTED = 'precheck_rejected';

    private readonly DeployLogPaths $paths;

    private readonly DeployStatus $status;

    private readonly DeployLock $lock;

    private readonly ProcessOutput $output;

    /**
     * Raw build output behind this deploy's failure, when the caller had it.
     *
     * finish() is handed the message a *customer* should read — usually the
     * one sentence DeployFailureExplainer produced, with the BuildKit dump
     * already thrown away. That is right for the panel and useless for
     * telemetry: it has no rule slug left in it, so an already-diagnosed
     * failure would be reported as one nobody has a rule for.
     */
    private ?string $rawFailureOutput = null;

    private function __construct(private readonly string $username, private readonly string $deployId)
    {
        $this->paths = new DeployLogPaths($username);
        $this->status = new DeployStatus($this->paths);
        $this->lock = new DeployLock($this->paths);
        $this->output = new ProcessOutput();
    }

    public static function baseDir(): string
    {
        return DeployLogPaths::base();
    }

    public static function userDirFor(string $username): string
    {
        return DeployLogPaths::userDir($username);
    }

    public function getDeployId(): string
    {
        return $this->deployId;
    }

    public function getLogPath(): string
    {
        return $this->paths->log($this->deployId);
    }

    /**
     * Begin a new deploy for this account. Rotates old logs.
     *
     * @throws DeployAlreadyRunningException
     */
    public static function start(string $username): self
    {
        $logger = new self($username, date('Ymd-His') . '-' . bin2hex(random_bytes(3)));

        LogStorage::ensureDirectory(DeployLogPaths::base());
        LogStorage::ensureDirectory($logger->paths->directory());
        $logger->lock->acquire();
        LogStorage::write($logger->getLogPath(), '');
        DeployLogArchive::prune($username, self::KEEP_LOGS);
        $logger->status->write(DeployStatus::started($logger->deployId));

        return $logger;
    }

    /**
     * Start a deploy log, swallowing filesystem errors: a deploy must proceed
     * even when logging is unavailable.
     *
     * The one exception is a lock conflict — that means another deploy owns
     * this account, so the caller has to stop rather than carry on with
     * logging disabled.
     *
     * @throws DeployAlreadyRunningException
     */
    public static function startSafely(string $username): ?self
    {
        return self::safely(static fn (): self => self::start($username), $username, 'start');
    }

    /**
     * Keep a zip follow-up on the same deploy as the account create. POST
     * /users for dind-without-git leaves latest.json running at "preparing";
     * a rebuild must not start a second id or the UI rewinds to cloning.
     */
    public static function resumeRunningOrStart(string $username): self
    {
        $current = self::current($username);
        if ($current === null || !$current->isRunning()) {
            return self::start($username);
        }
        $current->lock->acquire();

        return $current;
    }

    /**
     * @see startSafely() for the swallow-everything-but-a-lock-conflict rule.
     *
     * @throws DeployAlreadyRunningException
     */
    public static function resumeRunningOrStartSafely(string $username): ?self
    {
        return self::safely(
            static fn (): self => self::resumeRunningOrStart($username),
            $username,
            'resume'
        );
    }

    /**
     * @param callable(): self $open
     * @throws DeployAlreadyRunningException
     */
    private static function safely(callable $open, string $username, string $action): ?self
    {
        try {
            return $open();
        } catch (DeployAlreadyRunningException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning("Could not {$action} deploy log for {$username}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Tee every line and stage written from now on to the given emitter.
     */
    public static function streamTo(callable $emitter): void
    {
        DeployLogStream::to($emitter);
    }

    public static function stopStreaming(): void
    {
        DeployLogStream::stop();
    }

    /** Bind to a specific deploy id; the file may or may not exist yet. */
    public static function forDeploy(string $username, string $deployId): self
    {
        DeployLogPaths::assertSafeName($deployId, 'deploy id');

        return new self($username, $deployId);
    }

    /**
     * Bind to the deploy recorded in latest.json, running or finished. Null
     * when the account has no deploy log yet.
     */
    public static function current(string $username): ?self
    {
        $latest = (new self($username, ''))->readLatest();
        $id = $latest['id'] ?? null;

        return is_string($id) && $id !== '' ? new self($username, $id) : null;
    }

    /**
     * Whether a live process holds this account's deploy lock -- a deploy is
     * actually in progress, whatever latest.json says. The status alone stays
     * `running` forever after a deploy is killed before finish() (an OOM, a
     * worker timeout, a container restart), while the kernel releases the
     * lock with the process. And a cancelled deploy still winding down holds
     * the lock under a status that is no longer `running`.
     */
    public static function isLockedFor(string $username): bool
    {
        return (new DeployLock(new DeployLogPaths($username)))->isHeld();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listUsers(): array
    {
        return DeployLogArchive::users();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listDeploys(string $username): array
    {
        return DeployLogArchive::deploys($username);
    }

    /**
     * @return list<string> deleted absolute paths
     */
    public static function pruneUser(string $username, int $keep = self::KEEP_LOGS, bool $dryRun = false): array
    {
        return DeployLogArchive::prune($username, $keep, $dryRun);
    }

    public static function deleteUserLogs(string $username): void
    {
        DeployLogArchive::forget($username);
    }

    /**
     * @return array<string, list<string>> username => deleted paths
     */
    public static function pruneAll(int $keep = self::KEEP_LOGS, bool $dryRun = false): array
    {
        return DeployLogArchive::pruneAll($keep, $dryRun);
    }

    /**
     * Mark the running deploy as cancelled. The pipeline polls
     * {@see isCancelled()} between steps and aborts.
     *
     * @return array{cancelled: bool, pid: ?int} pid = subprocess to kill, if any
     */
    public static function requestCancel(string $username): array
    {
        $logger = new self($username, '');
        $latest = $logger->readLatest();
        if ($latest === null || ($latest['status'] ?? null) !== self::STATUS_RUNNING) {
            return ['cancelled' => false, 'pid' => null];
        }

        $latest['status'] = self::STATUS_CANCELLED;
        $logger->status->write($latest);
        $running = ProcessIdentity::isStillRunning($latest['pid'] ?? null, $latest['pid_start_time'] ?? null);

        return ['cancelled' => true, 'pid' => $running ? $latest['pid'] : null];
    }

    /**
     * The deploy stopped at a precheck: validation that runs before the clone.
     * Kept in latest.json because the logger that finishes the deploy is a
     * different instance from the one the precheck saw.
     */
    public function markPreCheckRejected(): void
    {
        $this->status->update([self::PRECHECK_REJECTED => true]);
    }

    public function isRunning(): bool
    {
        return $this->status->is(self::STATUS_RUNNING);
    }

    public function isCancelled(): bool
    {
        return $this->status->is(self::STATUS_CANCELLED);
    }

    /**
     * @throws DeployCancelledException
     */
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new DeployCancelledException('Deployment cancelled by user');
        }
    }

    /**
     * The stage this deploy is in, for a failure that has to say where it
     * died. Read from the same status file `stage()` writes, so it cannot
     * drift from what the log reports.
     */
    public function currentStage(): ?string
    {
        $stage = ($this->readLatest() ?? [])['stage'] ?? null;

        return is_string($stage) && $stage !== '' ? $stage : null;
    }

    public function stage(string $stage): void
    {
        $this->throwIfCancelled();

        $latest = $this->readLatest() ?? [];
        if (($latest['stage'] ?? null) === $stage) {
            return;
        }

        $now = time();
        [$stages, $finished] = DeployStatus::closeOpenStage($latest['stages'] ?? [], $now);
        $stages[] = ['name' => $stage, 'started_at' => $now, 'finished_at' => null];
        $this->status->write(array_merge($latest, ['stage' => $stage, 'stages' => $stages]));

        DeployLogStream::emit(['type' => 'stage', 'stage' => $stage, 'stages' => $stages]);

        if ($finished !== null) {
            $this->writeLine(self::LEVEL_OK, "Stage '{$finished}' finished", $stage);
        }
        $this->writeLine(self::LEVEL_INFO, "Starting stage: {$stage}", $stage);
    }

    public function log(string $level, string $message): void
    {
        $this->writeLine($level, $message, $this->status->value('stage'));
    }

    public function dim(string $message): void
    {
        $this->log(self::LEVEL_DIM, $message);
    }

    public function ok(string $message): void
    {
        $this->log(self::LEVEL_OK, $message);
    }

    public function info(string $message): void
    {
        $this->log(self::LEVEL_INFO, $message);
    }

    public function warn(string $message): void
    {
        $this->log(self::LEVEL_WARN, $message);
    }

    public function error(string $message): void
    {
        $this->log(self::LEVEL_ERROR, $message);
    }

    /**
     * Incremental subprocess output callback for Symfony Process.
     */
    public function writeProcessBuffer(string $type, string $data): void
    {
        foreach ($this->output->consume($type, $data) as $line) {
            $this->log(self::LEVEL_DIM, $line);
        }
    }

    public function flushBuffers(): void
    {
        foreach ($this->output->flush() as $line) {
            $this->log(self::LEVEL_DIM, $line);
        }
    }

    public function setPid(?int $pid): void
    {
        if ($this->readLatest() === null) {
            return;
        }

        $this->status->update([
            'pid' => $pid,
            'pid_start_time' => $pid === null ? null : ProcessIdentity::startTime($pid),
        ]);
    }

    /**
     * Keep the raw build output behind a failure, for telemetry only.
     *
     * Call this wherever the unabridged output is still in hand, before it is
     * reduced to the one sentence finish() will be given. Nothing is written
     * to the log file: the raw dump is already in there line by line.
     */
    public function recordFailureOutput(?string $raw): void
    {
        if (is_string($raw) && trim($raw) !== '') {
            $this->rawFailureOutput = $raw;
        }
    }

    public function finish(string $status, ?string $error = null): void
    {
        $this->flushBuffers();

        $latest = $this->readLatest() ?? [];
        [$status, $error] = $this->settleStatus($latest, $status, $error);
        [$stages] = DeployStatus::closeOpenStage($latest['stages'] ?? [], $now = time());
        $error = $error === null ? null : LogLine::sanitize($error);

        $this->status->write(array_merge($latest, [
            'status' => $status,
            'pid' => null,
            'finished_at' => $now,
            'error' => $error,
            'stages' => $stages,
        ]));
        $this->writeLine(
            $status === self::STATUS_SUCCESS ? self::LEVEL_OK : self::LEVEL_ERROR,
            self::finishMessage($status, $error),
            $latest['stage'] ?? null
        );
        $this->lock->release();

        // Every terminal status passes through here, which is why the
        // telemetry hook lives at this one point rather than at each of
        // UserController's exits. Capture is fire-and-forget and cannot
        // throw; see Telemetry. The raw output wins when a caller kept it:
        // the explainer has to see what the build actually printed, not the
        // sentence it already turned that into.
        Telemetry::captureDeploy($this, $this->username, $status, $this->rawFailureOutput ?? $error);

        // Same reasoning, same choke point: a push that coalesced while this
        // deploy ran -- of any kind, for any reason -- is followed up from
        // here, once, whatever this deploy's own outcome was. Cannot throw;
        // see Coalescing.
        Coalescing::runPendingFor($this->username);
    }

    /**
     * Once cancelled, never overwrite the status with a failure caused by the
     * cancellation itself — a killed subprocess, a cleanup error.
     *
     * @param array<string, mixed> $latest
     * @return array{0: string, 1: ?string}
     */
    private function settleStatus(array $latest, string $status, ?string $error): array
    {
        $wasCancelled = ($latest['status'] ?? null) === self::STATUS_CANCELLED;

        return $wasCancelled && $status === self::STATUS_FAILED
            ? [self::STATUS_CANCELLED, null]
            : [$status, $error];
    }

    private static function finishMessage(string $status, ?string $error): string
    {
        $message = self::FINISH_MESSAGE[$status] ?? self::FINISH_MESSAGE[self::STATUS_FAILED];

        return $error === null || $error === '' ? $message : $message . ': ' . $error;
    }

    /**
     * Every line of this deploy's log, timestamped, in order.
     *
     * @return list<array{ts: int, level: string, msg: string}>
     */
    public function entries(): array
    {
        return $this->reader()->entries();
    }

    /**
     * @return array{lines: list<array<string, mixed>>, next_offset: int}
     */
    public function read(int $offset = 0, int $limit = DeployLogReader::MAX_READ_LINES): array
    {
        return $this->reader()->page($offset, $limit);
    }

    /**
     * @return list<array{ts: int, stage: ?string, level: string, msg: string}>
     */
    public function tail(int $limit = 120): array
    {
        return $this->reader()->tail($limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readLatestFor(string $username): ?array
    {
        return (new self($username, ''))->readLatest();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readLatest(): ?array
    {
        return $this->status->read();
    }

    public function __destruct()
    {
        $this->lock->release();
    }

    private function reader(): DeployLogReader
    {
        return new DeployLogReader($this->getLogPath());
    }

    private function writeLine(string $level, string $message, ?string $stage = null): void
    {
        $message = LogLine::sanitize($message);
        if ($this->deployId === '' || $message === '') {
            return;
        }

        $frame = [
            'ts' => time(),
            'stage' => $stage,
            'level' => $level,
            'msg' => LogLine::truncate($message),
        ];
        LogStorage::write($this->getLogPath(), json_encode($frame) . "\n", FILE_APPEND | LOCK_EX);
        DeployLogStream::emit(['type' => 'line'] + $frame);
    }
}
