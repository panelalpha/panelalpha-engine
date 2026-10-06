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

    /** Why a deploy whose process died before finish() is closed as failed. */
    public const INTERRUPTED_MESSAGE =
        'The deploy stopped without finishing — the engine process running it is gone. '
        . 'This usually means the host restarted or ran out of memory. Deploy again to retry.';

    /** The line a DinD account without git ends its first deploy on, `running`, until files arrive. */
    public const WAITING_FOR_FILES = 'Environment ready, waiting for project files';

    /** How far back from the end of a log to look for its finish line. */
    private const FINISH_LINE_LOOKBACK = 60;

    /** Lines of a known problem's text written to the log, per section. */
    private const PROBLEM_MAX_LINES = 40;

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

    /**
     * Values this deploy must never write, by deploy id: a password the engine
     * delivered can come back in whatever the app prints. Static so a second
     * logger opened on the same deploy masks them too.
     *
     * @var array<string, array<string, true>>
     */
    private static array $masked = [];

    /** Shorter values would mask ordinary words. */
    private const MIN_MASKED_LENGTH = 4;

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
     * Close every deploy left `running` by a process that died before finish().
     *
     * @return list<string> the accounts whose deploy was (or, dry, would be) closed
     */
    public static function settleOrphanedDeploys(bool $dryRun = false): array
    {
        $settled = [];
        foreach (self::listUsers() as $row) {
            $username = $row['username'] ?? null;
            if (($row['status'] ?? null) === self::STATUS_RUNNING && is_string($username)
                && self::settleOrphaned($username, $dryRun) !== null) {
                $settled[] = $username;
            }
        }

        return $settled;
    }

    /**
     * Close this account's deploy if its process is gone: latest.json says
     * `running` and nothing holds the deploy lock, which the kernel released
     * with the process (an OOM kill, a core or worker restart). Nothing else
     * would ever write its status, and a client polling for one waits forever.
     *
     * A deploy that got as far as its finish line keeps that verdict; one that
     * did not is failed with {@see INTERRUPTED_MESSAGE}. A DinD account waiting
     * for its files is `running` with no lock by design and is left alone.
     *
     * @return ?string the status written (or, dry, that would be), null when left alone
     */
    public static function settleOrphaned(string $username, bool $dryRun = false): ?string
    {
        $logger = self::current($username);
        if ($logger === null || !$logger->isRunning() || $logger->isWaitingForFiles()) {
            return null;
        }
        if ($dryRun) {
            return $logger->lock->isHeld() ? null : ($logger->finishLine()[0] ?? self::STATUS_FAILED);
        }

        // Held while the status is written, so a deploy starting now cannot be overwritten.
        try {
            $logger->lock->acquire();
        } catch (DeployAlreadyRunningException) {
            return null;
        }
        try {
            $latest = $logger->readLatest() ?? [];
            if (($latest['id'] ?? null) !== $logger->deployId || ($latest['status'] ?? null) !== self::STATUS_RUNNING) {
                return null;
            }
            $finished = $logger->finishLine();
            if ($finished === null) {
                $finished = [self::STATUS_FAILED, self::INTERRUPTED_MESSAGE];
                $logger->writeLine(self::LEVEL_ERROR, self::finishMessage(...$finished), $latest['stage'] ?? null);
            }
            [$stages] = DeployStatus::closeOpenStage($latest['stages'] ?? [], $now = time());
            $logger->status->write(array_merge($latest, [
                'status' => $finished[0],
                'pid' => null,
                'finished_at' => $now,
                'error' => $finished[1],
                'stages' => $stages,
            ]));
        } finally {
            $logger->lock->release();
        }
        Log::warning("Deploy {$logger->deployId} for {$username} was left running by a process that is gone; closed as {$finished[0]}.");
        // What finish() would have done last. Cannot throw.
        Coalescing::runPendingFor($username);

        return $finished[0];
    }

    /**
     * The first deploy of a DinD account without git, stopped on purpose until files arrive.
     * Git and file commands run on the waiting account stream their output into this log as
     * dim lines, so it is the last line that is not dim that has to say so.
     */
    private function isWaitingForFiles(): bool
    {
        if (($this->readLatest()['stage'] ?? null) !== self::STAGE_PREPARING) {
            return false;
        }
        foreach (array_reverse($this->entries()) as $line) {
            if ($line['level'] !== self::LEVEL_DIM) {
                return $line['msg'] === self::WAITING_FOR_FILES;
            }
        }

        return false;
    }

    /**
     * The status and error a finish() line near the end of the log announced:
     * finish() writes it before the status, so a process killed in between
     * (telemetry's report is the slow part) leaves the verdict only there.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function finishLine(): ?array
    {
        foreach (array_reverse($this->tail(self::FINISH_LINE_LOOKBACK)) as $line) {
            if (!in_array($line['level'] ?? null, [self::LEVEL_OK, self::LEVEL_ERROR], true)) {
                continue;
            }
            $msg = (string) ($line['msg'] ?? '');
            foreach (self::FINISH_MESSAGE as $status => $message) {
                if ($msg === $message) {
                    return [$status, null];
                }
                if (str_starts_with($msg, $message . ': ')) {
                    return [$status, substr($msg, strlen($message) + 2)];
                }
            }
        }

        return null;
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

    /** finish() has run. A cancel request alone sets `cancelled` without it. */
    public function isFinished(): bool
    {
        return $this->status->value('finished_at') !== null;
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

    /**
     * Mask these values in every line this deploy writes from now on, in its
     * error and in what telemetry is handed.
     *
     * @param list<string> $values
     */
    public function mask(array $values): void
    {
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) >= self::MIN_MASKED_LENGTH) {
                self::$masked[$this->deployId][$value] = true;
            }
        }
    }

    public function redact(string $text): string
    {
        $values = array_keys(self::$masked[$this->deployId] ?? []);
        if ($values === []) {
            return $text;
        }
        // Longest first, so a value containing another is masked whole.
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_replace($values, '***', $text);
    }

    public function finish(string $status, ?string $error = null): void
    {
        $this->flushBuffers();

        $latest = $this->readLatest() ?? [];
        // A caller that catches a failure the workflow already finished must not
        // write a second terminal line, a second telemetry report or a new status.
        if (($latest['id'] ?? null) === $this->deployId && ($latest['finished_at'] ?? null) !== null) {
            return;
        }
        [$status, $error] = $this->settleStatus($latest, $status, $error);
        [$stages] = DeployStatus::closeOpenStage($latest['stages'] ?? [], $now = time());
        $error = $error === null ? null : LogLine::sanitize($this->redact($error));
        $rawFailure = $this->rawFailureOutput === null ? null : $this->redact($this->rawFailureOutput);

        $final = array_merge($latest, [
            'status' => $status,
            'pid' => null,
            'finished_at' => $now,
            'error' => $error,
            'stages' => $stages,
        ]);
        $this->writeLine(
            $status === self::STATUS_SUCCESS ? self::LEVEL_OK : self::LEVEL_ERROR,
            self::finishMessage($status, $error),
            $latest['stage'] ?? null
        );

        // Every terminal status passes through here, which is why the
        // telemetry hook lives at this one point rather than at each of
        // UserController's exits. Capture cannot throw; see Telemetry. The raw
        // output wins when a caller kept it: the explainer has to see what the
        // build actually printed, not the sentence it already turned that into.
        // It runs before the status is published so a failure's fix from
        // monitoring is in the same answer as the failure.
        $problem = Telemetry::captureDeploy($this, $this->username, $status, $rawFailure ?? $error, $final);
        if ($problem !== null) {
            $this->writeProblem($problem, $latest['stage'] ?? null);
        }

        $this->status->write(array_merge($final, ['problem' => $problem]));
        $this->lock->release();
        unset(self::$masked[$this->deployId]);

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

    /**
     * Monitoring's fix for this failure, as log lines, so every reader of the
     * log sees it and not only the `problem` field.
     *
     * A fix released in this engine's version or earlier did not prevent this
     * failure, so its "fixed in" and how-to-fix text would be a false claim:
     * only the title and the cause are written then.
     *
     * @param array<string, ?string> $problem
     */
    private function writeProblem(array $problem, ?string $stage): void
    {
        $this->writeLine(self::LEVEL_WARN, 'Known problem: ' . ($problem['title'] ?? 'PanelAlpha has a fix for this failure'), $stage);

        $fixedIn = $problem['fixed_in_version'] ?? null;
        $alreadyHere = $fixedIn !== null && self::engineHasRelease($fixedIn);
        $fields = $alreadyHere ? ['body_why' => 'Why'] : ['body_why' => 'Why', 'body_fix' => 'How to fix'];
        foreach ($fields as $field => $label) {
            $lines = array_values(array_filter(
                array_map('trim', explode("\n", (string) ($problem[$field] ?? ''))),
                static fn (string $line): bool => $line !== ''
            ));
            if ($lines === []) {
                continue;
            }
            $this->writeLine(self::LEVEL_INFO, $label . ':', $stage);
            foreach (array_slice($lines, 0, self::PROBLEM_MAX_LINES) as $line) {
                $this->writeLine(self::LEVEL_INFO, $line, $stage);
            }
        }

        if ($alreadyHere) {
            $this->writeLine(
                self::LEVEL_INFO,
                "This engine already includes the fix released in {$fixedIn}; this failure is a case it does not cover",
                $stage
            );
        } elseif ($fixedIn !== null) {
            $this->writeLine(self::LEVEL_INFO, 'Fixed in engine version ' . $fixedIn, $stage);
        }
    }

    /** Whether the running engine is $version or newer; false when either is not a version. */
    private static function engineHasRelease(string $version): bool
    {
        $running = config('system.version');
        $pattern = '/^\d+(\.\d+)*$/';
        if (!is_string($running) || preg_match($pattern, $running) !== 1 || preg_match($pattern, trim($version)) !== 1) {
            return false;
        }

        return version_compare($running, trim($version), '>=');
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
     * @return array{lines: list<array<string, mixed>>, next_offset: int, more: bool}
     */
    public function read(int $offset = 0, int $limit = DeployLogReader::MAX_READ_LINES, ?int $maxBytes = null): array
    {
        return $this->reader()->page($offset, $limit, $maxBytes);
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
        $message = LogLine::sanitize($this->redact($message));
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
