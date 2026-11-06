<?php

namespace App\Lib\Deploy\DeployLog;

use App\Exceptions\DeployAlreadyRunningException;
use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Findings\Finding;
use Illuminate\Support\Facades\Log;

/**
 * A DinD deploy's structured log: a poller reads it while the deploy writes, so the status is replaced whole and the log is
 * append-only JSON lines; the contract would survive the deploy moving to a background process.
 *
 * @see DeployLogPaths
 * @see DeployStatus
 * @see DeployLogReader
 * @see DeployLogArchive
 * @see ProcessOutput
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

    /** Why a deploy whose process died before finish() is closed as failed. */
    public const INTERRUPTED = 'deploy_interrupted';

    public const INTERRUPTED_MESSAGE =
        'The deploy stopped without finishing — the engine process running it is gone. '
        .'This usually means the host restarted or ran out of memory. Deploy again to retry.';

    /** The line a DinD account without git ends its first deploy on, `running`, until files arrive. */
    public const WAITING_FOR_FILES = 'Environment ready, waiting for project files';

    /** Lines of a known problem's text written to the log, per section. */
    private const PROBLEM_MAX_LINES = 40;

    /** latest.json key set by {@see markPreCheckRejected()}. */
    public const PRECHECK_REJECTED = 'precheck_rejected';

    private readonly DeployLogPaths $paths;

    private readonly DeployStatus $status;

    private readonly DeployLock $lock;

    private readonly ProcessOutput $output;

    /**
     * Raw build output behind this deploy's failure, for telemetry: finish() gets the customer's sentence, which has no rule
     * slug left in it, so an already-diagnosed failure would be reported as one nobody has a rule for.
     */
    private ?string $rawFailureOutput = null;

    /**
     * Values this deploy must never write, by deploy id: a delivered password can come back in what the app prints. Static, so
     * a second logger opened on the same deploy masks them too.
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
        $this->output = new ProcessOutput;
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

    public function getUsername(): string
    {
        return $this->username;
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
        $logger = new self($username, date('Ymd-His').'-'.bin2hex(random_bytes(3)));

        LogStorage::ensureDirectory(DeployLogPaths::base());
        LogStorage::ensureDirectory($logger->paths->directory());
        $logger->lock->acquire();
        LogStorage::write($logger->getLogPath(), '');
        DeployLogArchive::prune($username, DeployLogArchive::keep());
        $logger->status->write(DeployStatus::started($logger->deployId));

        return $logger;
    }

    /**
     * Start a deploy log, swallowing filesystem errors, as a deploy must proceed without logging. Not a lock conflict: another
     * deploy owns this account, so the caller has to stop rather than carry on with logging disabled.
     *
     * @throws DeployAlreadyRunningException
     */
    public static function startSafely(string $username): ?self
    {
        return self::safely(static fn (): self => self::start($username), $username, 'start');
    }

    /**
     * Keep a zip follow-up on the same deploy as the account create: a create without git leaves latest.json running at
     * "preparing", and a second id would rewind the UI to cloning.
     */
    public static function resumeRunningOrStart(string $username): self
    {
        $current = self::current($username);
        if ($current === null || ! $current->isRunning()) {
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
     * @param  callable(): self  $open
     *
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
     * Whether a live process holds this account's deploy lock, whatever latest.json says: a deploy killed before finish() stays
     * `running` though the kernel released its lock, and a cancelled one winding down holds it under another status.
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
     * Close this account's deploy if its process is gone (`running`, no lock holder): nothing else would write its status, and a
     * poller waits forever. A DinD account waiting for its files is left alone; a coalesced push follows as the lock goes.
     *
     * @return ?string the status written (or, dry, that would be), null when left alone
     */
    public static function settleOrphaned(string $username, bool $dryRun = false): ?string
    {
        $logger = self::current($username);
        if ($logger === null || ! $logger->isRunning() || $logger->isWaitingForFiles()) {
            return null;
        }
        if ($dryRun) {
            return $logger->lock->isHeld() ? null : ($logger->finishLine()['status'] ?? self::STATUS_FAILED);
        }

        try {
            $logger->lock->acquire();
        } catch (DeployAlreadyRunningException) {
            return null;
        }
        try {
            $finished = self::closeOrphanedStatus($logger);
        } finally {
            $logger->lock->release();
        }
        if ($finished === null) {
            return null;
        }
        Log::warning("Deploy {$logger->deployId} for {$username} was left running by a process that is gone; closed as {$finished[0]}.");

        return $finished[0];
    }

    /**
     * Write the closed status under settleOrphaned()'s lock, so a deploy starting meanwhile cannot be overwritten.
     * Null when latest.json moved on; a verdict the finish line announced is kept, else the deploy fails with {@see INTERRUPTED_MESSAGE}.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function closeOrphanedStatus(self $logger): ?array
    {
        $latest = $logger->readLatest() ?? [];
        if (($latest['id'] ?? null) !== $logger->deployId || ($latest['status'] ?? null) !== self::STATUS_RUNNING) {
            return null;
        }
        $finished = $logger->finishLine();
        if ($finished === null) {
            $finished = ['status' => self::STATUS_FAILED, 'error' => self::INTERRUPTED_MESSAGE, 'warnings' => [], 'failure' => new Finding(self::INTERRUPTED, self::INTERRUPTED_MESSAGE)];
            $logger->writeLine(self::LEVEL_ERROR, self::finishMessage(self::STATUS_FAILED, self::INTERRUPTED_MESSAGE), $latest['stage'] ?? null);
        }
        [$stages] = DeployStatus::closeOpenStage($latest['stages'] ?? [], $now = time());
        $logger->status->write(array_merge($latest, [
            'status' => $finished['status'],
            'pid' => null,
            'finished_at' => $now,
            'error' => $finished['error'],
            'warnings' => Finding::storable($finished['warnings']),
            'failure' => isset($finished['failure']) ? $finished['failure']->toArray() : null,
            'stages' => $stages,
        ]));

        return [$finished['status'], $finished['error']];
    }

    /**
     * The first deploy of a DinD account without git, stopped on purpose until files arrive. Commands run on the waiting account
     * stream into this log as dim lines, so it is the last line that is not dim that has to say so.
     */
    private function isWaitingForFiles(): bool
    {
        if (($this->ownLatest()['stage'] ?? null) !== self::STAGE_PREPARING) {
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
     * A create waiting for its files runs nothing: it is `running` with no lock until a
     * deploy resumes its log, and that deploy is the one a cancel is meant for.
     */
    private static function isIdleWaitingForFiles(string $username): bool
    {
        $current = self::current($username);

        return $current !== null && $current->isWaitingForFiles() && ! $current->lock->isHeld();
    }

    /**
     * The verdict a finish() line near the end of the log announced: it is written before the status, so a process
     * killed in between (telemetry's report is the slow part) leaves the verdict only there.
     *
     * @return array{status: string, error: ?string, warnings: list<Finding>, ts: int}|null
     */
    private function finishLine(): ?array
    {
        return DeployFinishLine::find($this->tail(DeployFinishLine::LOOKBACK));
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
        if ($latest === null || ($latest['status'] ?? null) !== self::STATUS_RUNNING || self::isIdleWaitingForFiles($username)) {
            return ['cancelled' => false, 'pid' => null];
        }

        $latest['status'] = self::STATUS_CANCELLED;
        $logger->status->write($latest);
        $running = ProcessIdentity::isStillRunning($latest['pid'] ?? null, $latest['pid_start_time'] ?? null);

        return ['cancelled' => true, 'pid' => $running ? $latest['pid'] : null];
    }

    /**
     * The deploy stopped at a precheck, the validation before the clone. Kept in latest.json, as the logger that finishes the
     * deploy is a different instance from the one the precheck saw.
     */
    public function markPreCheckRejected(): void
    {
        if ($this->ownLatest() !== null) {
            $this->status->update([self::PRECHECK_REJECTED => true]);
        }
    }

    public function isRunning(): bool
    {
        return ($this->ownLatest()['status'] ?? null) === self::STATUS_RUNNING;
    }

    public function isCancelled(): bool
    {
        return ($this->ownLatest()['status'] ?? null) === self::STATUS_CANCELLED;
    }

    /**
     * finish() has run, or a deploy started since has taken latest.json over. A cancel request alone sets `cancelled`
     * without it.
     */
    public function isFinished(): bool
    {
        $own = $this->ownLatest();

        return $own === null || ($own['finished_at'] ?? null) !== null;
    }

    /**
     * latest.json while it is this deploy's record, empty before it has one; null once a deploy started after this one
     * finished has replaced it: the lock goes at finish(), and a logger kept past it must not read or write that deploy.
     *
     * @return array<string, mixed>|null
     */
    private function ownLatest(): ?array
    {
        $latest = $this->readLatest() ?? [];

        return ($latest['id'] ?? $this->deployId) === $this->deployId ? $latest : null;
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
     * The stage this deploy is in, for a failure that has to say where it died. Read from the status file `stage()` writes, so
     * it cannot drift from what the log reports.
     */
    public function currentStage(): ?string
    {
        $stage = $this->ownLatest()['stage'] ?? null;

        return is_string($stage) && $stage !== '' ? $stage : null;
    }

    public function stage(string $stage): void
    {
        $this->throwIfCancelled();

        $latest = $this->ownLatest();
        if ($latest === null || ($latest['stage'] ?? null) === $stage) {
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
        $this->writeLine($level, $message, $this->ownLatest()['stage'] ?? null);
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
        if (($this->ownLatest() ?? []) === []) {
            return;
        }

        $this->status->update([
            'pid' => $pid,
            'pid_start_time' => $pid === null ? null : ProcessIdentity::startTime($pid),
        ]);
    }

    /**
     * Keep the raw build output behind a failure, for telemetry only, while it is still unabridged. Nothing is written to the
     * log file: the raw dump is already in there line by line.
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
     * @param  list<string>  $values
     */
    public function mask(array $values): void
    {
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) >= self::MIN_MASKED_LENGTH) {
                self::$masked[$this->deployId][$value] = true;
            }
        }
    }

    /** Mask this deploy's values in $text, longest first so a value containing another is masked whole. */
    public function redact(string $text): string
    {
        $values = array_keys(self::$masked[$this->deployId] ?? []);
        if ($values === []) {
            return $text;
        }
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_replace($values, '***', $text);
    }

    /**
     * Close the deploy, once: a caller catching a failure the workflow already finished writes no second line, report or status.
     * A push that coalesced while it ran is followed up as the lock goes, once, whatever the outcome ({@see DeployLock::release()}).
     */
    public function finish(string $status, ?string $error = null): void
    {
        $this->close($status, $error, []);
    }

    /** A deploy that failed: `error` is its sentence, and `failure` the same with the code a client branches on. */
    public function finishFailed(Finding $failure): void
    {
        $this->close(self::STATUS_FAILED, $failure->message, [], $failure);
    }

    /**
     * A deploy that serves with something wrong: its warnings say what, and `error` stays empty.
     *
     * @param  list<Finding>  $warnings
     */
    public function finishPartial(array $warnings): void
    {
        $this->close(self::STATUS_PARTIAL, null, $warnings);
    }

    /**
     * A deploy already closed is left as it is, and so is the one that took latest.json over after it: a caller finishing
     * again once a coalesced push has started its own deploy would otherwise end that one, and write a second verdict here.
     *
     * @param  list<Finding>  $warnings
     */
    private function close(string $status, ?string $error, array $warnings, ?Finding $failure = null): void
    {
        $this->flushBuffers();

        $latest = $this->ownLatest();
        if ($latest === null || ($latest['finished_at'] ?? null) !== null) {
            return;
        }
        [$status, $error] = $this->settleStatus($latest, $status, $error);
        $warnings = $status === self::STATUS_PARTIAL ? $this->redactedFindings($warnings) : [];
        [$stages] = DeployStatus::closeOpenStage($latest['stages'] ?? [], $now = time());
        $error = $error === null ? null : LogLine::sanitize($this->redact($error));
        $failure = $status === self::STATUS_FAILED && $error !== null
            ? ($failure ?? new Finding(DeployFailureExplainer::DEPLOY_FAILED, $error))->withMessage($error)
            : null;
        $summary = $error ?? ($warnings !== [] ? Finding::sentence($warnings) : null);
        $rawFailure = $this->rawFailureOutput === null ? null : $this->redact($this->rawFailureOutput);

        $final = self::finishedStatus($latest, $status, $error, $warnings, $failure, $stages, $now);
        $this->writeLine(DeployFinishLine::level($status), self::finishMessage($status, $summary), $latest['stage'] ?? null);

        $problem = $this->captureProblem($status, $rawFailure ?? $summary, $final, $latest['stage'] ?? null);

        $this->status->write(array_merge($final, ['problem' => $problem]));
        unset(self::$masked[$this->deployId]);
        $this->lock->release();
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function redactedFindings(array $findings): array
    {
        return array_map(fn (Finding $finding): Finding => $finding->withMessage(LogLine::sanitize($this->redact($finding->message))), $findings);
    }

    /**
     * latest.json as a finished deploy leaves it, before monitoring's problem is added.
     *
     * @param  array<string, mixed>  $latest
     * @param  list<Finding>  $warnings
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, mixed>
     */
    private static function finishedStatus(array $latest, string $status, ?string $error, array $warnings, ?Finding $failure, array $stages, int $now): array
    {
        return array_merge($latest, [
            'status' => $status,
            'pid' => null,
            'finished_at' => $now,
            'error' => $error,
            'warnings' => Finding::storable($warnings),
            'failure' => $failure?->toArray(),
            'stages' => $stages,
        ]);
    }

    /**
     * Report the deploy to telemetry at the one point every terminal status passes (capture cannot throw), with the raw output
     * when kept, as the explainer needs what the build printed; before the status is published, so a fix answers with the failure.
     *
     * @param  array<string, mixed>  $final
     * @return array<string, ?string>|null
     */
    private function captureProblem(string $status, ?string $failure, array $final, ?string $stage): ?array
    {
        $problem = Telemetry::captureDeploy($this, $this->username, $status, $failure, $final);
        if ($problem !== null) {
            $this->writeProblem($problem, $stage);
        }

        return $problem;
    }

    /**
     * Once cancelled, never overwrite the status with a failure caused by the
     * cancellation itself — a killed subprocess, a cleanup error.
     *
     * @param  array<string, mixed>  $latest
     * @return array{0: string, 1: ?string}
     */
    private function settleStatus(array $latest, string $status, ?string $error): array
    {
        $wasCancelled = ($latest['status'] ?? null) === self::STATUS_CANCELLED;
        $cancellationOutcome = in_array($status, [self::STATUS_FAILED, self::STATUS_PARTIAL], true);

        return $wasCancelled && $cancellationOutcome
            ? [self::STATUS_CANCELLED, null]
            : [$status, $error];
    }

    /**
     * Monitoring's fix as log lines, for every reader of the log. A fix released in this engine's version or earlier did not
     * prevent this failure, so only the title and the cause are written then: "fixed in" and how-to-fix would be a false claim.
     *
     * @param  array<string, ?string>  $problem
     */
    private function writeProblem(array $problem, ?string $stage): void
    {
        $this->writeLine(self::LEVEL_WARN, 'Known problem: '.($problem['title'] ?? 'PanelAlpha has a fix for this failure'), $stage);

        $fixedIn = $problem['fixed_in_version'] ?? null;
        $alreadyHere = $fixedIn !== null && self::engineHasRelease($fixedIn);
        $fields = $alreadyHere ? ['body_why' => 'Why'] : ['body_why' => 'Why', 'body_fix' => 'How to fix'];
        foreach ($fields as $field => $label) {
            $lines = self::problemLines($problem, $field);
            if ($lines === []) {
                continue;
            }
            $this->writeLine(self::LEVEL_INFO, $label.':', $stage);
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
            $this->writeLine(self::LEVEL_INFO, 'Fixed in engine version '.$fixedIn, $stage);
        }
    }

    /**
     * The non-blank lines of one of the problem's texts, trimmed.
     *
     * @param  array<string, ?string>  $problem
     * @return list<string>
     */
    private static function problemLines(array $problem, string $field): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", (string) ($problem[$field] ?? ''))),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /** Whether the running engine is $version or newer; false when either is not a version. */
    private static function engineHasRelease(string $version): bool
    {
        $running = config('system.version');
        $pattern = '/^\d+(\.\d+)*$/';
        if (! is_string($running) || preg_match($pattern, $running) !== 1 || preg_match($pattern, trim($version)) !== 1) {
            return false;
        }

        return version_compare($running, trim($version), '>=');
    }

    private static function finishMessage(string $status, ?string $error): string
    {
        return DeployFinishLine::message($status, $error);
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
        $this->lock->releaseQuietly();
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
        LogStorage::write($this->getLogPath(), json_encode($frame)."\n", FILE_APPEND | LOCK_EX);
        DeployLogStream::emit(['type' => 'line'] + $frame);
    }
}
