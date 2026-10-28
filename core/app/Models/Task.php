<?php

namespace App\Models;

use App\Jobs\ApplyEnvVars;
use App\Jobs\DeployProject;
use App\Jobs\RebuildProject;
use App\Lib\Task\TaskReconciler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class Task extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** `details` key of the worker process running the task; never shown to a client. */
    public const WORKER = 'worker';

    /**
     * Jobs that deploy a project and write its deploy log.
     *
     * @var list<class-string>
     */
    public const DEPLOY_JOB_TYPES = [
        DeployProject::class,
        RebuildProject::class,
    ];

    /**
     * Jobs that run under the account's deploy lock: a deploy, and an env var
     * apply that recreates its services. A delete or another deploy refuses
     * while one is queued or running.
     *
     * @var list<class-string>
     */
    public const DEPLOY_LOCK_JOB_TYPES = [
        DeployProject::class,
        RebuildProject::class,
        ApplyEnvVars::class,
    ];

    /** The message a refused `since` answers with, on the API and over MCP. */
    public const SINCE_EXPECTED = 'since must be unix seconds or an ISO-8601 datetime';

    protected $fillable = [
        'api_token_id',
        'job_id',
        'username',
        'queue',
        'job_type',
        'status',
        'details',
        'pid',
        'pid_start_time',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'api_token_id' => 'integer',
        'details' => 'array',
        'pid' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function start(
        string $jobType,
        string $queue,
        ?string $username = null,
        ?array $details = null
    ): self {
        if ($jobType === '' || $queue === '') {
            throw new InvalidArgumentException('job_type and queue are required');
        }

        return self::create([
            'job_type' => $jobType,
            'queue' => $queue,
            'username' => $username,
            'api_token_id' => self::currentTokenId(),
            'details' => $details,
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
    }

    /** The bearer that started this task, or null for work the engine began itself. */
    public static function currentTokenId(): ?int
    {
        $token = request()?->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->id : null;
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    /**
     * Page of log rows after an id cursor and/or a timestamp.
     *
     * $since is unix seconds or an ISO datetime string. Both filters AND
     * together so a poller can advance by created_at and still skip rows that
     * share the same second via after_id.
     *
     * @return Collection<int, TaskLog>
     */
    public function logsPage(int $afterId = 0, int|string|null $since = null, int $limit = 2000): Collection
    {
        $query = $this->logs()->orderBy('id')->limit($limit);

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        $sinceAt = self::parseSince($since);
        if ($sinceAt !== null) {
            $query->where('created_at', '>', $sinceAt);
        }

        return $query->get();
    }

    /**
     * @return Carbon|null null when the filter is absent or zero
     */
    public static function parseSince(int|string|null $since): ?Carbon
    {
        if ($since === null || $since === '' || $since === 0 || $since === '0') {
            return null;
        }

        if (is_int($since) || (is_string($since) && ctype_digit($since))) {
            $seconds = (int) $since;
            if ($seconds <= 0) {
                return null;
            }

            return Carbon::createFromTimestamp($seconds);
        }

        return Carbon::parse((string) $since);
    }

    /** Whether {@see parseSince()} can read this; Carbon throws on anything else. */
    public static function isSince(int|string|null $since): bool
    {
        try {
            self::parseSince($since);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Whether this task was a project deploy.
     *
     * Deploys are the only job types that write a per-account deploy log, and
     * the only ones whose liveness can be read from the pid that log records —
     * {@see TaskReconciler} relies on both facts.
     */
    public function isDeploy(): bool
    {
        return in_array($this->job_type, self::DEPLOY_JOB_TYPES, true);
    }

    /**
     * Merging `details` from this copy is safe: nothing else writes a queued task's details.
     *
     * @param  array<string, mixed>  $details  merged into `details` by the same write
     */
    public function markRunning(?string $jobId = null, array $details = []): bool
    {
        $values = ['status' => self::STATUS_RUNNING, 'started_at' => now()];
        if (is_string($jobId) && $jobId !== '') {
            $values['job_id'] = $jobId;
        }
        if ($details !== []) {
            $values['details'] = $this->castAttributeAsJson('details', array_merge($this->details ?? [], $details));
        }

        return $this->leaveQueued($values);
    }

    /**
     * Cancel a task no worker has taken yet. False once one has: a delete
     * that cancels queued work must then see the task as running instead.
     */
    public function cancelIfQueued(): bool
    {
        return $this->leaveQueued(['status' => self::STATUS_CANCELLED, 'cancelled_at' => now()]);
    }

    /**
     * Decided by the row, not by this copy of it: a worker taking the task and
     * a delete cancelling it may each have read it as queued.
     *
     * @param  array<string, mixed>  $values
     */
    private function leaveQueued(array $values): bool
    {
        if ($this->status !== self::STATUS_QUEUED) {
            return false;
        }

        $moved = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_QUEUED)
            ->update($values) === 1;
        $this->refresh();

        return $moved;
    }

    public function markCompleted(): bool
    {
        return $this->settle(['status' => self::STATUS_COMPLETED, 'completed_at' => now()]);
    }

    /**
     * Merge fields into `details` from the worker that owns the row right now.
     *
     * @param  array<string, mixed>  $details
     */
    public function mergeDetails(array $details): bool
    {
        if ($details === []) {
            return false;
        }

        $this->details = array_merge($this->details ?? [], $details);

        return $this->save();
    }

    public function markFailed(string $error): bool
    {
        $details = $this->details ?? [];
        $details['error'] = $error;

        return $this->settle([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'details' => $this->castAttributeAsJson('details', $details),
        ]);
    }

    public function markCancelled(): bool
    {
        return $this->settle(['status' => self::STATUS_CANCELLED, 'cancelled_at' => now()]);
    }

    /**
     * The first terminal write wins, decided by a conditional update on the
     * row: a cancel and a finishing worker may each hold a running copy.
     * `details` set in memory (the reconciler does) is written by the same update.
     *
     * @param  array<string, mixed>  $values
     */
    private function settle(array $values): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        if (! array_key_exists('details', $values) && $this->isDirty('details')) {
            $values['details'] = $this->castAttributeAsJson('details', $this->details);
        }

        $moved = static::query()
            ->whereKey($this->getKey())
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->update($values) === 1;
        $this->refresh();

        return $moved;
    }

    public function setPid(?int $pid, ?string $startTime): void
    {
        $this->pid = $pid;
        $this->pid_start_time = $pid === null ? null : $startTime;
        $this->save();
    }
}
